<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The application has no public landing page: the root path sends visitors
     * to the panel's sign-in screen.
     */
    public function test_the_root_path_redirects_to_the_panel_login(): void
    {
        $this->get('/')->assertRedirect(route('filament.admin.auth.login'));
    }
}
