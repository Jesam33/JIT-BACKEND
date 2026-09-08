<?php

namespace Tests\Feature;

use App\Support\Cors;
use Tests\TestCase;

class FrontendCorsTest extends TestCase
{
    private const APEX = 'https://jorsastech.com';

    private const ACADEMY = 'https://pfc.jorsastech.com';

    protected function setUp(): void
    {
        parent::setUp();

        // Live-like CORS shape: exact apex list plus the derived subdomain
        // pattern. The preflight requests below short-circuit inside HandleCors
        // before the app (and database) run, so no DB setup is needed.
        config([
            'cors.allowed_origins' => [self::APEX, 'https://www.jorsastech.com'],
            'cors.allowed_origins_patterns' => array_values(array_filter([
                Cors::subdomainPattern(self::APEX),
            ])),
        ]);
    }

    public function test_preflight_from_academy_subdomain_is_allowed(): void
    {
        $response = $this->options('/api/frontend/lms/branding/public', [], [
            'Origin' => self::ACADEMY,
            'Access-Control-Request-Method' => 'GET',
        ]);

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', self::ACADEMY);
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        $response->assertHeader('Access-Control-Allow-Methods', 'GET');
    }

    public function test_preflight_for_login_post_from_academy_subdomain_is_allowed(): void
    {
        $response = $this->options('/api/frontend/lms/login', [], [
            'Origin' => self::ACADEMY,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type, x-tenant-slug',
        ]);

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', self::ACADEMY);
        $this->assertStringContainsString(
            'POST',
            (string) $response->headers->get('Access-Control-Allow-Methods')
        );
    }

    public function test_preflight_from_apex_origin_is_allowed(): void
    {
        $response = $this->options('/api/frontend/lms/branding/public', [], [
            'Origin' => self::APEX,
            'Access-Control-Request-Method' => 'GET',
        ]);

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', self::APEX);
    }

    public function test_preflight_from_unknown_origin_stays_fail_closed(): void
    {
        $response = $this->options('/api/frontend/lms/branding/public', [], [
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'GET',
        ]);

        // No CORS headers for a disallowed origin, but crucially NO 500: the
        // preflight must return cleanly (204) so the browser sees a proper
        // CORS rejection instead of a header-less server error.
        $response->assertStatus(204);
        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_config_drops_invalid_env_pattern_instead_of_500ing(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS_PATTERN'] = '/^https://unescaped-slashes-here';
        $_ENV['LMS_BASE_URL'] = 'https://www.jorsastech.com';

        $config = require config_path('cors.php');

        // The malformed regex is dropped...
        $this->assertNotContains(
            '/^https://unescaped-slashes-here',
            $config['allowed_origins_patterns']
        );
        // ...and the derived subdomain pattern is present instead.
        $this->assertContains(
            Cors::subdomainPattern('https://www.jorsastech.com'),
            $config['allowed_origins_patterns']
        );

        // Every configured pattern must compile.
        foreach ($config['allowed_origins_patterns'] as $pattern) {
            $this->assertTrue(Cors::patternCompiles($pattern));
        }
    }

    public function test_subdomain_pattern_helper_admits_only_own_subdomains(): void
    {
        $pattern = Cors::subdomainPattern('https://jorsastech.com');

        $this->assertNotNull($pattern);
        $this->assertSame(1, preg_match($pattern, 'https://pfc.jorsastech.com'));
        $this->assertSame(1, preg_match($pattern, 'https://a.b.jorsastech.com'));
        $this->assertSame(1, preg_match($pattern, 'https://www.jorsastech.com'));

        // The apex itself is NOT admitted by the pattern (it belongs in
        // allowed_origins), and look-alike domains are rejected.
        $this->assertSame(0, preg_match($pattern, 'https://jorsastech.com'));
        $this->assertSame(0, preg_match($pattern, 'https://evil-jorsastech.com'));
        $this->assertSame(0, preg_match($pattern, 'https://jorsastech.com.evil.io'));
        $this->assertSame(0, preg_match($pattern, 'http://pfc.jorsastech.com'));
    }

    public function test_subdomain_pattern_helper_skips_local_urls(): void
    {
        $this->assertNull(Cors::subdomainPattern(null));
        $this->assertNull(Cors::subdomainPattern(''));
        $this->assertNull(Cors::subdomainPattern('http://127.0.0.1:3000'));
        $this->assertNull(Cors::subdomainPattern('http://localhost:3000'));
    }

    public function test_pattern_compiles_helper(): void
    {
        $this->assertTrue(Cors::patternCompiles('~^https:\/\/[a-z0-9-]+\.jorsastech\.com$~'));
        $this->assertTrue(Cors::patternCompiles('/^abc$/i'));
        // Delimiter with unescaped slashes (the outage shape), empty pattern.
        $this->assertFalse(Cors::patternCompiles('/^https://x'));
        $this->assertFalse(Cors::patternCompiles(''));
    }

    protected function tearDown(): void
    {
        unset(
            $_ENV['CORS_ALLOWED_ORIGINS_PATTERN'],
            $_ENV['LMS_BASE_URL']
        );

        parent::tearDown();
    }
}
