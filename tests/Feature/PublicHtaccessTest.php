<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Laravel's front-controller rewrite rules live in public/.htaccess. The host currently serves the app through a
 * docroot shim, but the document root is being moved to <app>/public, which needs this file to be tracked.
 */
class PublicHtaccessTest extends TestCase
{
    private function htaccess(): string
    {
        $path = dirname(__DIR__, 2).'/public/.htaccess';
        $this->assertFileExists($path, 'public/.htaccess must be tracked so the document root can be public/');

        return (string) file_get_contents($path);
    }

    public function test_it_sends_unknown_paths_to_the_front_controller(): void
    {
        $this->assertStringContainsString('RewriteRule ^ index.php [L]', $this->htaccess());
    }

    public function test_it_passes_the_authorization_and_xsrf_headers_through(): void
    {
        $htaccess = $this->htaccess();

        $this->assertStringContainsString('HTTP_AUTHORIZATION', $htaccess);
        $this->assertStringContainsString('HTTP_X_XSRF_TOKEN', $htaccess);
    }

    public function test_it_turns_directory_listings_off(): void
    {
        $this->assertStringContainsString('-Indexes', $this->htaccess());
    }
}
