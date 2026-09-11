<?php

namespace Tests\Feature;

use App\Models\LmsCourse;
use App\Models\LmsMaterial;
use App\Models\LmsModule;
use App\Models\LmsSession;
use App\Models\LmsTeacher;
use App\Models\LmsTrack;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Staff AI training materials (StaffGammaController, Gamma Pro+). The teacher
 * gets the same generate → poll → save flow the owner has, but every save /
 * module-list target must be a course they are ASSIGNED to (via their cohorts,
 * LmsTrack.instructor_id) — the same scoping StaffPortalController applies to
 * materials. generate/status hit the external Gamma API and are not covered
 * here; save + courseModules exercise the context resolution, the tenant
 * scope, the assignment hooks and the ai_materials PlanGate without it.
 */
class StaffGammaScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['saas.enforce_tenancy' => true]);
    }

    private function makeTenant(string $slug, string $plan = 'pro'): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'plan' => $plan]);
    }

    /** Run $fn with $tenant bound, mimicking a resolved request, then unbind. */
    private function asTenant(Tenant $tenant, callable $fn)
    {
        app()->instance('currentTenant', $tenant);
        try {
            return $fn();
        } finally {
            app()->forgetInstance('currentTenant');
        }
    }

    /** Create a teacher + staff session (tenant_id stamped, like real login), return [$token, $teacher]. */
    private function staffToken(Tenant $tenant): array
    {
        $teacher = $this->asTenant($tenant, fn () => LmsTeacher::create([
            'name' => 'Tutor', 'email' => 'tutor@' . $tenant->slug . '.test',
            'password' => Hash::make('secret123'), 'role' => 'Instructor', 'is_active' => true,
        ]));

        $token = Str::random(80);
        $this->asTenant($tenant, fn () => LmsSession::create([
            'role' => 'staff',
            'user_id' => $teacher->id,
            'token' => $token,
            'tenant_id' => $tenant->id,
            'expires_at' => now()->addDay(),
        ]));

        return [$token, $teacher];
    }

    /** Create an owner user + tenant_admins row, mint an owner session, return the bearer token. */
    private function ownerToken(Tenant $tenant): string
    {
        $email = 'owner@' . $tenant->slug . '.test';
        $user = User::create([
            'first_name' => 'Owner',
            'last_name' => 'One',
            'username' => $email,
            'email' => $email,
            'password' => Hash::make('secret123'),
        ]);
        DB::table('tenant_admins')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $token = Str::random(80);
        $this->asTenant($tenant, fn () => LmsSession::create([
            'role' => 'owner',
            'user_id' => $user->id,
            'token' => $token,
            'tenant_id' => $tenant->id,
            'expires_at' => now()->addDay(),
        ]));

        return $token;
    }

    /** A course + a cohort taught by $teacher (the assignment that unlocks it). */
    private function assignedCourse(Tenant $tenant, LmsTeacher $teacher, string $title): LmsCourse
    {
        return $this->asTenant($tenant, function () use ($teacher, $title) {
            $course = LmsCourse::create(['title' => $title, 'is_active' => true]);
            LmsTrack::create(['name' => $title . ' Batch', 'course_id' => $course->id, 'instructor_id' => $teacher->id]);
            return $course;
        });
    }

    public function test_staff_saves_export_file_into_assigned_course(): void
    {
        Storage::fake('public');
        Http::fake(['https://exports.test/html.pdf' => Http::response('%PDF-1.4 fake-bytes', 200)]);

        $tenant = $this->makeTenant('acme');
        [$token, $teacher] = $this->staffToken($tenant);
        $course = $this->assignedCourse($tenant, $teacher, 'Web Dev');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/staff/ai/materials/save', [
                'title' => 'HTML Slides',
                'url' => 'https://gamma.app/doc/abc123',
                'export_url' => 'https://exports.test/html.pdf',
                'format' => 'pdf',
                'course_id' => $course->id,
            ])
            ->assertCreated()
            ->assertJsonPath('target', 'course');

        // White-label contract: the student-facing material is OUR stored file,
        // never the vendor link (which lives only in provider/external_id).
        $this->assertDatabaseHas('lms_materials', [
            'course_id' => $course->id,
            'title' => 'HTML Slides',
            'type' => 'pdf',
            'provider' => 'gamma',
            'external_id' => 'https://gamma.app/doc/abc123',
        ]);
        $material = LmsMaterial::withoutGlobalScope(TenantScope::class)->where('title', 'HTML Slides')->first();
        $this->assertNotNull($material->file_path);
        $this->assertStringStartsWith('materials/', $material->file_path);
        Storage::disk('public')->assertExists($material->file_path);
        $this->assertNotSame('https://gamma.app/doc/abc123', $material->file_url);
        // The stored link must be RELATIVE so the Next /storage rewrite serves
        // it same-origin from any host (an absolute 127.0.0.1 URL 404s from a
        // phone or the live domain).
        $this->assertSame('/storage/' . $material->file_path, $material->file_url);
    }

    public function test_staff_saves_a_docx_converted_from_the_pptx_export(): void
    {
        Storage::fake('public');
        Http::fake(['https://exports.test/deck.pptx' => Http::response($this->syntheticPptx(), 200)]);

        $tenant = $this->makeTenant('acme');
        [$token, $teacher] = $this->staffToken($tenant);
        $course = $this->assignedCourse($tenant, $teacher, 'Web Dev');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/staff/ai/materials/save', [
                'title' => 'HTML Handout',
                'url' => 'https://gamma.app/doc/abc123',
                'export_url' => 'https://exports.test/deck.pptx',
                'format' => 'docx',
                'course_id' => $course->id,
            ])
            ->assertCreated()
            ->assertJsonPath('type', 'doc');

        $material = LmsMaterial::withoutGlobalScope(TenantScope::class)->where('title', 'HTML Handout')->first();
        $this->assertNotNull($material);
        // Stored as a real Word file with the relative in-app link…
        $this->assertStringEndsWith('.docx', $material->file_path);
        $this->assertSame('/storage/' . $material->file_path, $material->file_url);
        Storage::disk('public')->assertExists($material->file_path);

        // …whose content is a valid docx zip carrying the deck's text, a
        // Heading1 for the slide title, and the embedded picture.
        $docx = Storage::disk('public')->get($material->file_path);
        $this->assertStringStartsWith('PK', $docx);
        $document = $this->zipEntry($docx, 'word/document.xml');
        $this->assertStringContainsString('HTML Basics', $document);
        $this->assertStringContainsString('Tags &amp; elements', $document);
        $this->assertStringContainsString('w:val="Heading1"', $document);
        $this->assertStringContainsString('r:embed="rId110"', $document);
        $this->assertNotNull($this->zipEntry($docx, 'word/media/image1.png'));
        // The emitted document.xml must be well-formed, not just contain text.
        $this->assertNotFalse(simplexml_load_string($document));
    }

    public function test_docx_download_endpoint_streams_a_word_file(): void
    {
        Http::fake(['https://exports.test/deck.pptx' => Http::response($this->syntheticPptx(), 200)]);

        $tenant = $this->makeTenant('acme');
        [$token] = $this->staffToken($tenant);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/staff/ai/materials/docx', [
                'export_url' => 'https://exports.test/deck.pptx',
                'title' => 'HTML Basics',
            ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            (string) $response->headers->get('Content-Type'),
        );
        $this->assertStringContainsString('html-basics.docx', (string) $response->headers->get('Content-Disposition'));

        // The docx is streamed from a temp file (deleted on shutdown), so read
        // the BinaryFileResponse's underlying file rather than getContent().
        $file = $response->baseResponse->getFile();
        $docx = (string) file_get_contents($file->getPathname());
        $this->assertStringStartsWith('PK', $docx);
        $document = $this->zipEntry($docx, 'word/document.xml');
        $this->assertStringContainsString('HTML Basics', $document);
        // The emitted document.xml must be well-formed, not just contain text.
        $this->assertNotFalse(simplexml_load_string($document));
    }

    public function test_garbage_pptx_fails_the_docx_save_loudly(): void
    {
        Storage::fake('public');
        Http::fake(['https://exports.test/junk.pptx' => Http::response('this is not a zip', 200)]);

        $tenant = $this->makeTenant('acme');
        [$token, $teacher] = $this->staffToken($tenant);
        $course = $this->assignedCourse($tenant, $teacher, 'Web Dev');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/staff/ai/materials/save', [
                'title' => 'Junk',
                'url' => 'https://gamma.app/doc/abc123',
                'export_url' => 'https://exports.test/junk.pptx',
                'format' => 'docx',
                'course_id' => $course->id,
            ])
            ->assertStatus(502);

        $this->assertSame(0, LmsMaterial::withoutGlobalScope(TenantScope::class)->where('title', 'Junk')->count());
    }

    /**
     * A minimal but structurally real pptx: two slides, the first with a title
     * placeholder, a body shape (with an XML-escaped ampersand) and a picture
     * resolved through the slide .rels into ppt/media/.
     */
    private function syntheticPptx(): string
    {
        $slide1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld><p:spTree>'
            . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Title 1"/><p:cNvSpPr/><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>'
            . '<p:spPr/><p:txBody><a:bodyPr/><a:p><a:r><a:t>HTML Basics</a:t></a:r></a:p></p:txBody></p:sp>'
            . '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Content 2"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr/><p:txBody><a:bodyPr/><a:p><a:r><a:t>Tags &amp; elements</a:t></a:r></a:p></p:txBody></p:sp>'
            . '<p:pic><p:nvPicPr><p:cNvPr id="4" name="Picture 3"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr>'
            . '<p:blipFill><a:blip r:embed="rId2"/></p:blipFill><p:spPr/></p:pic>'
            . '</p:spTree></p:cSld></p:sld>';

        $slide2 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld><p:spTree>'
            . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Title 1"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr/><p:txBody><a:bodyPr/><a:p><a:r><a:t>Second slide body</a:t></a:r></a:p></p:txBody></p:sp>'
            . '</p:spTree></p:cSld></p:sld>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.png"/>'
            . '</Relationships>';

        // 1x1 transparent PNG.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        $tmp = tempnam(sys_get_temp_dir(), 'pptxtest');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('ppt/slides/slide1.xml', $slide1);
        $zip->addFromString('ppt/slides/slide2.xml', $slide2);
        $zip->addFromString('ppt/slides/_rels/slide1.xml.rels', $rels);
        $zip->addFromString('ppt/media/image1.png', $png);
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /** Read one entry out of an in-memory zip, or null when absent. */
    private function zipEntry(string $zipBytes, string $entry): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ziptest');
        file_put_contents($tmp, $zipBytes);

        $zip = new \ZipArchive();
        $ok = $zip->open($tmp);
        $value = $ok === true ? $zip->getFromName($entry) : false;
        $zip->close();
        @unlink($tmp);

        return $value === false ? null : (string) $value;
    }

    public function test_save_without_a_fetchable_export_stores_nothing(): void
    {
        // The white-label guarantee: if the export can't be fetched, the save
        // fails loudly instead of quietly storing the vendor link students
        // would see. Both a missing export_url and a failed download 502.
        $tenant = $this->makeTenant('acme');
        [$token, $teacher] = $this->staffToken($tenant);
        $course = $this->assignedCourse($tenant, $teacher, 'Web Dev');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/staff/ai/materials/save', [
                'title' => 'No Export',
                'url' => 'https://gamma.app/doc/abc123',
                'course_id' => $course->id,
            ])
            ->assertStatus(502)
            ->assertJsonPath('error', 'export_unavailable');

        Http::fake(['https://exports.test/down.pdf' => Http::response('gone', 500)]);
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/staff/ai/materials/save', [
                'title' => 'Broken Export',
                'url' => 'https://gamma.app/doc/abc123',
                'export_url' => 'https://exports.test/down.pdf',
                'course_id' => $course->id,
            ])
            ->assertStatus(502);

        $this->assertSame(0, LmsMaterial::withoutGlobalScope(TenantScope::class)
            ->where('course_id', $course->id)->count());
    }

    public function test_staff_cannot_save_into_unassigned_course_of_their_institute(): void
    {
        $tenant = $this->makeTenant('acme');
        [$token, $teacher] = $this->staffToken($tenant);
        $assigned = $this->assignedCourse($tenant, $teacher, 'Web Dev');
        $other = $this->asTenant($tenant, fn () => LmsCourse::create(['title' => 'Data Science', 'is_active' => true]));

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/staff/ai/materials/save', [
                'title' => 'Sneaky',
                'url' => 'https://gamma.app/doc/abc123',
                'course_id' => $other->id,
            ])
            ->assertStatus(403);

        $this->assertSame(0, LmsMaterial::withoutGlobalScope(TenantScope::class)->where('title', 'Sneaky')->count());
        // The assigned course is untouched too.
        $this->assertSame(0, LmsMaterial::withoutGlobalScope(TenantScope::class)->where('course_id', $assigned->id)->count());
    }

    public function test_staff_cannot_save_into_module_of_unassigned_course(): void
    {
        $tenant = $this->makeTenant('acme');
        [$token, $teacher] = $this->staffToken($tenant);
        $this->assignedCourse($tenant, $teacher, 'Web Dev');
        $other = $this->asTenant($tenant, fn () => LmsCourse::create(['title' => 'Data Science', 'is_active' => true]));
        $module = $this->asTenant($tenant, fn () => LmsModule::create(['course_id' => $other->id, 'title' => 'Week 1']));

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/staff/ai/materials/save', [
                'title' => 'Sneaky',
                'url' => 'https://gamma.app/doc/abc123',
                'module_id' => $module->id,
            ])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('lms_module_contents')->where('module_id', $module->id)->count());
    }

    public function test_staff_module_picker_is_scoped_to_assigned_courses(): void
    {
        $tenant = $this->makeTenant('acme');
        [$token, $teacher] = $this->staffToken($tenant);
        $assigned = $this->assignedCourse($tenant, $teacher, 'Web Dev');
        $this->asTenant($tenant, fn () => LmsModule::create(['course_id' => $assigned->id, 'title' => 'Week 1']));
        $other = $this->asTenant($tenant, fn () => LmsCourse::create(['title' => 'Data Science', 'is_active' => true]));

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/frontend/lms/staff/courses/{$assigned->id}/modules")
            ->assertOk()
            ->assertJsonCount(1, 'modules');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/frontend/lms/staff/courses/{$other->id}/modules")
            ->assertStatus(403);
    }

    public function test_cross_tenant_course_id_404s_instead_of_authorizing(): void
    {
        $acme = $this->makeTenant('acme');
        [$token] = $this->staffToken($acme);
        $beta = $this->makeTenant('beta');
        $betaCourse = $this->asTenant($beta, fn () => LmsCourse::create(['title' => 'Beta Only', 'is_active' => true]));

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/staff/ai/materials/save', [
                'title' => 'Sneaky',
                'url' => 'https://gamma.app/doc/abc123',
                'course_id' => $betaCourse->id,
            ])
            ->assertNotFound();
    }

    public function test_free_plan_academy_gets_the_pro_gate_402(): void
    {
        $tenant = $this->makeTenant('freebie', 'free');
        [$token, $teacher] = $this->staffToken($tenant);
        $course = $this->assignedCourse($tenant, $teacher, 'Web Dev');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/staff/ai/materials/save', [
                'title' => 'HTML Slides',
                'url' => 'https://gamma.app/doc/abc123',
                'course_id' => $course->id,
            ])
            ->assertStatus(402);
    }

    public function test_owner_save_still_reaches_any_course_of_their_institute(): void
    {
        // Regression guard for the hook refactor: the owner variant must keep
        // allowing every tenant course, assigned to a teacher or not.
        Storage::fake('public');
        Http::fake(['https://exports.test/owner.pdf' => Http::response('%PDF-1.4 fake-bytes', 200)]);

        $tenant = $this->makeTenant('acme');
        $token = $this->ownerToken($tenant);
        $course = $this->asTenant($tenant, fn () => LmsCourse::create(['title' => 'Owner Course', 'is_active' => true]));

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/lms/owner/ai/materials/save', [
                'title' => 'Owner Slides',
                'url' => 'https://gamma.app/doc/owner123',
                'export_url' => 'https://exports.test/owner.pdf',
                'format' => 'pdf',
                'course_id' => $course->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('lms_materials', ['course_id' => $course->id, 'title' => 'Owner Slides']);
    }

    public function test_staff_me_exposes_the_ai_materials_flag(): void
    {
        $pro = $this->makeTenant('acme');
        [$proToken] = $this->staffToken($pro);

        $this->withHeader('Authorization', 'Bearer ' . $proToken)
            ->getJson('/api/frontend/lms/staff/me')
            ->assertOk()
            ->assertJsonPath('ai_materials', true);

        $free = $this->makeTenant('freebie', 'free');
        [$freeToken] = $this->staffToken($free);

        $this->withHeader('Authorization', 'Bearer ' . $freeToken)
            ->getJson('/api/frontend/lms/staff/me')
            ->assertOk()
            ->assertJsonPath('ai_materials', false);
    }
}
