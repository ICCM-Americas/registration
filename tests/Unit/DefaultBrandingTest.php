<?php

namespace ConferenceTools\Registration\Tests\Unit;

use ConferenceTools\Registration\Support\DefaultBranding;
use ConferenceTools\Registration\Tests\TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/** Unit tests for Default Branding. */
#[TestDox('Default Branding')]
class DefaultBrandingTest extends TestCase
{
    #[TestDox('site name uses brand name config')]
    public function test_site_name_uses_brand_name_config(): void
    {
        config(['registration.brand_name' => 'My Event']);

        $this->assertSame('My Event', (new DefaultBranding)->siteName());
    }

    #[TestDox('site name falls back to app name when brand name empty')]
    public function test_site_name_falls_back_to_app_name_when_brand_name_empty(): void
    {
        config(['registration.brand_name' => '']);
        config(['app.name' => 'Fallback App']);

        $this->assertSame('Fallback App', (new DefaultBranding)->siteName());
    }

    #[TestDox('color returns known palette entry')]
    public function test_color_returns_known_palette_entry(): void
    {
        $this->assertSame('#2563eb', (new DefaultBranding)->color('primary'));
        $this->assertSame('#7c3aed', (new DefaultBranding)->color('secondary'));
        $this->assertSame('#f8fafc', (new DefaultBranding)->color('background'));
        $this->assertSame('#0f172a', (new DefaultBranding)->color('text'));
    }

    #[TestDox('color returns black for unknown key')]
    public function test_color_returns_black_for_unknown_key(): void
    {
        $this->assertSame('#000000', (new DefaultBranding)->color('does-not-exist'));
    }

    #[TestDox('logo url reflects config')]
    public function test_logo_url_reflects_config(): void
    {
        config(['registration.logo_path' => '/tmp/logo.png']);
        $this->assertSame('/tmp/logo.png', (new DefaultBranding)->logoUrl());

        config(['registration.logo_path' => null]);
        $this->assertNull((new DefaultBranding)->logoUrl());
    }

    #[TestDox('url links to the application root')]
    public function test_url_links_to_the_application_root(): void
    {
        $this->assertSame(url('/'), (new DefaultBranding)->url());
    }
}
