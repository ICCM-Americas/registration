<?php

namespace ConferenceTools\Registration\Support;

use ConferenceTools\Branding\Contracts\BrandingProvider;

/**
 * Neutral, dependency-free branding used when the host application has not bound
 * its own {@see BrandingProvider}. It reads the package's existing config keys
 * (`registration.brand_name`, `registration.logo_path`) so the package's views
 * render in isolation (e.g. in tests, or before a host wires up branding).
 */
class DefaultBranding implements BrandingProvider
{
    private const COLORS = [
        'primary' => '#2563eb',
        'secondary' => '#7c3aed',
        'background' => '#f8fafc',
        'text' => '#0f172a',
    ];

    /** The application name as the fallback site name. */
    public function siteName(): string
    {
        return config('registration.brand_name') ?: config('app.name', 'Conference');
    }

    /** A fixed neutral palette color for the key. */
    public function color(string $key): string
    {
        return self::COLORS[$key] ?? '#000000';
    }

    /** No logo in the fallback branding. */
    public function logoUrl(): ?string
    {
        return config('registration.logo_path');
    }

    /** The application root as the fallback site URL. */
    public function url(): string
    {
        return url('/');
    }
}
