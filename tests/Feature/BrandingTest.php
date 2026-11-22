<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_uses_app_logo_and_favicon(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('assets/logo.png', false)
            ->assertSee('assets/favicon.png', false);

        foreach (['assets/logo.png', 'assets/favicon.png', 'favicon.ico'] as $file) {
            $this->assertFileExists(public_path($file));
        }
    }
}
