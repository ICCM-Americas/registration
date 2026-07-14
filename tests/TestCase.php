<?php

namespace ConferenceTools\Registration\Tests;

use ConferenceTools\Branding\BrandingServiceProvider;
use ConferenceTools\Registration\RegistrationServiceProvider;
use ConferenceTools\Registration\Tests\Fixtures\User;
use Illuminate\Support\Facades\Gate;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    // Verify Mockery expectations (->once(), ->shouldNotReceive(), ...) at
    // teardown and count them as assertions, so mock-based tests are enforced.
    use MockeryPHPUnitIntegration;

    protected function getPackageProviders($app): array
    {
        return [
            RegistrationServiceProvider::class,
            // After the package, as in production: the branding package's real
            // BrandingProvider binding overrides the package's neutral default,
            // and its views back the shared PDF-export partial.
            BrandingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // The web middleware (sessions/cookies) needs an application key.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('registration.user_model', User::class);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // Mirror a real SQLite deploy (Laravel's default sqlite connection sets
            // this true), so the package's ON DELETE CASCADE / SET NULL foreign keys
            // are enforced under SQLite exactly as on MySQL/Postgres.
            'foreign_key_constraints' => true,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // The host users table first, then the package migrations (auto-loaded
        // by the service provider) extend it and create the domain tables.
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    /** Open the manage-registration admin gate to every user for this test. */
    protected function allowRegistrationManagement(): void
    {
        Gate::define('manage-registration', fn ($user) => true);
    }

    /** Close the manage-registration admin gate to every user for this test. */
    protected function denyRegistrationManagement(): void
    {
        Gate::define('manage-registration', fn ($user) => false);
    }
}
