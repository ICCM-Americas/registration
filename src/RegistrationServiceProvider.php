<?php

namespace ConferenceTools\Registration;

use ConferenceTools\Branding\Contracts\BrandingProvider;
use ConferenceTools\Registration\Models\ConferenceArchive;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\GroupInvite;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\PrayerPalsAssignment;
use ConferenceTools\Registration\Models\RoomAssignment;
use ConferenceTools\Registration\Services\ConferenceEdition;
use ConferenceTools\Registration\Services\RegistrationStatus;
use ConferenceTools\Registration\Services\VariableInterpolator;
use ConferenceTools\Registration\Support\DefaultBranding;
use ConferenceTools\Registration\Support\LabelFormatter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/** Boots the registration package inside the host: config, migrations, routes, views, translations, and the package's container bindings. */
class RegistrationServiceProvider extends ServiceProvider
{
    private const VIEW_NAMESPACE = 'registration';

    /** Merge the package config and register its singletons and the neutral BrandingProvider default. */
    public function register(): void
    {
        // Published as config/registration.php; the config key stays "registration".
        $this->mergeConfigFrom(__DIR__.'/../config/registration.php', 'registration');

        // Branding *management* lives in the host app. The package only needs a
        // render-time seam; the host binds its own implementation to override
        // this neutral default (which reads the package's brand_name/logo config).
        $this->app->singleton(BrandingProvider::class, DefaultBranding::class);

        // One variable map per request: the interpolator memoizes it and the
        // Variable model flushes it on change.
        $this->app->singleton(VariableInterpolator::class);

        // Resolve package model factories from the package factory namespace while
        // leaving the default resolver in place for host models.
        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            if (str_starts_with($modelName, 'ConferenceTools\\Registration\\Models\\')) {
                return 'ConferenceTools\\Registration\\Database\\Factories\\'.class_basename($modelName).'Factory';
            }

            return 'Database\\Factories\\'.class_basename($modelName).'Factory';
        });
    }

    /** Load the package's migrations, routes, views, and translations, and register publishing. */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', self::VIEW_NAMESPACE);
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', self::VIEW_NAMESPACE);
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // The package's views (and any host layout they extend) can reference the
        // configured layout name without hard-coding it, and pick up a `$branding`
        // object resolved from the bound BrandingProvider — the host's
        // implementation when bound, otherwise the neutral default.
        View::composer('registration::*', function ($view): void {
            $view->with('registrationLayout', config('registration.layout'));
            $view->with('branding', $this->app->make(BrandingProvider::class));

            // Blade-accessible mirror of Controller::routeName(): lets the package
            // views build route names through the configured prefix instead of
            // hard-coding it, e.g. `route($routeName('admin.invoices'))`.
            $view->with('routeName', fn (string $name): string => config('registration.route_name_prefix').$name);

            // Replaces "{name}" variable tokens in admin-authored texts (landing
            // page steps, question/option strings) with their configured values.
            $view->with('vars', fn (?string $text): ?string => $this->app->make(VariableInterpolator::class)->interpolate($text));

            // Question labels additionally support a small Markdown-flavored
            // convention (newlines, **bold**, _italics_); this renders the
            // interpolated label as safe HTML for output via {!! !!}.
            $view->with('label', fn (?string $text): string => LabelFormatter::format($this->app->make(VariableInterpolator::class)->interpolate($text)));

            // The registration window state, for the dashboard controls and the
            // misconfiguration banner rendered on every admin page (admin-nav).
            $view->with('registrationStatus', $this->app->make(RegistrationStatus::class));

            // The conference edition (name/year), for the dashboard card.
            $view->with('conferenceEdition', $this->app->make(ConferenceEdition::class));

            // Whether an archive exists yet, for the dashboard card's "Export
            // Archive" button — hidden until the first rollover.
            $view->with('archiveExists', ConferenceArchive::exists());
        });

        $this->registerUserDeletionHandling();
        $this->registerPublishing();
    }

    /**
     * Keep registration data consistent when a host user is deleted, without a
     * cross-table foreign key into the host users table (which would tie the
     * package to the host's database/connection). A registrant's in-progress
     * wizard draft, room and Prayer Pals assignments, and non-attending
     * guests (with their own room/Prayer-Pals assignments and answers) are
     * removed with them; a group invite that account had accepted is kept
     * (it is the record of which group they belonged to) with just its
     * now-dangling user reference cleared.
     */
    private function registerUserDeletionHandling(): void
    {
        $userModel = config('registration.user_model');

        if (! is_string($userModel) || ! class_exists($userModel) || ! method_exists($userModel, 'deleting')) {
            return;
        }

        $userModel::deleting(function ($user) use ($userModel): void {
            $guestIds = Guest::query()->where('user_id', $user->getKey())->pluck('id');

            RoomAssignment::query()->where('assignable_type', $userModel)->where('assignable_id', $user->getKey())->delete();
            RoomAssignment::query()->where('assignable_type', Guest::class)->whereIn('assignable_id', $guestIds)->delete();
            PrayerPalsAssignment::query()->where('assignable_type', $userModel)->where('assignable_id', $user->getKey())->delete();
            PrayerPalsAssignment::query()->where('assignable_type', Guest::class)->whereIn('assignable_id', $guestIds)->delete();
            Guest::query()->whereIn('id', $guestIds)->delete();
            Draft::query()->where('user_id', $user->getKey())->delete();
            // The invite/token/consumed_at row stays — it is the record of
            // which group this account belonged to — only the now-dangling
            // account reference is cleared.
            GroupInvite::query()->where('user_id', $user->getKey())->update(['user_id' => null]);
        });
    }

    /** Expose the package's config, views, translations, and stylesheet to artisan vendor:publish. */
    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // The stylesheet for this package's screens, which the host layout
        // links. Not optional — without it these screens render unstyled — so
        // it also carries Laravel's conventional "laravel-assets" tag, which
        // the stock post-update-cmd composer script republishes with --force.
        $this->publishes([
            __DIR__.'/../resources/css' => public_path('vendor/registration/css'),
        ], ['registration-assets', 'laravel-assets']);

        $this->publishes([
            __DIR__.'/../config/registration.php' => config_path('registration.php'),
        ], 'registration-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/'.self::VIEW_NAMESPACE),
        ], 'registration-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->langPublishPath(),
        ], 'registration-lang');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'registration-migrations');
    }

    /** The host path package translations publish to. */
    private function langPublishPath(): string
    {
        return function_exists('lang_path')
            ? lang_path('vendor/'.self::VIEW_NAMESPACE)
            : resource_path('lang/vendor/'.self::VIEW_NAMESPACE);
    }
}
