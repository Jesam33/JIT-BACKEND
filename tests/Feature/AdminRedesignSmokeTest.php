<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * THROWAWAY redesign smoke test: renders every restyled LMS admin page as the
 * real super-admin against the real local DB (read-only, no RefreshDatabase).
 * Run with DB_DATABASE=jorsastech_local to hit the real database. Delete after use.
 */
class AdminRedesignSmokeTest extends TestCase
{
    /** @dataProvider pages */
    public function test_page_renders_clean(string $path): void
    {
        $admin = User::where('super_user', 1)->firstOrFail();

        $response = $this->actingAs($admin)->get($path);

        $this->assertSame(
            200,
            $response->status(),
            "Expected 200 for {$path}, got {$response->status()}"
        );

        $html = $response->getContent();

        // New layout must actually be in play (font loaded by the rewritten layout).
        $this->assertStringContainsString(
            'Plus Jakarta Sans',
            $html,
            "New layout did not render for {$path}"
        );

        // Blade must be fully compiled: no raw directives or leaked styles.
        $this->assertStringNotContainsString('@php', $html, "Raw @php leaked on {$path}");
        $this->assertStringNotContainsString('@endphp', $html, "Raw @endphp leaked on {$path}");
        $this->assertStringNotContainsString('@section', $html, "Raw @section leaked on {$path}");
        $this->assertStringNotContainsString('@yield', $html, "Raw @yield leaked on {$path}");

        // No runtime fatals captured in the output.
        $this->assertStringNotContainsString('Division by zero', $html);
        $this->assertStringNotContainsString('ErrorException', $html);
        $this->assertStringNotContainsString('Undefined variable', $html);
    }

    public static function pages(): array
    {
        return [
            'dashboard' => ['/admin/lms'],
            'students' => ['/admin/lms/students'],
            'intake' => ['/admin/lms/intake'],
            'transactions' => ['/admin/lms/transactions'],
            'forums' => ['/admin/lms/forums'],
            'agents' => ['/admin/lms/agents'],
            'agent withdrawals' => ['/admin/lms/agents/withdrawals'],
            'courses' => ['/admin/lms/courses'],
            'tracks' => ['/admin/lms/tracks'],
            'classrooms' => ['/admin/lms/classrooms'],
            'teacher create' => ['/admin/lms/teachers/create'],
            'institutes' => ['/admin/lms/institutes'],
            'announcements' => ['/admin/lms/announcements'],
        ];
    }
}
