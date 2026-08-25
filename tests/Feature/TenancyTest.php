<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Tenant;
use App\Models\LmsCourse;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_scope_restricts_models()
    {
        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'a']);
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b']);

        app()->instance('currentTenant', $tenantA);
        $courseA = LmsCourse::create(['title' => 'Course A', 'is_active' => true]);

        app()->instance('currentTenant', $tenantB);
        $courseB = LmsCourse::create(['title' => 'Course B', 'is_active' => true]);

        app()->instance('currentTenant', $tenantA);
        $courses = LmsCourse::all();
        $this->assertCount(1, $courses);
        $this->assertEquals('Course A', $courses->first()->title);
    }
}
