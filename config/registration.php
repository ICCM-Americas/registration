<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    |
    | The package mounts every registration route under the single URL prefix
    | below ("registration" by default). Public, authenticated and admin screens
    | all live there — the admin console sits at "{prefix}/admin" — so the whole
    | module can be exposed behind one host name (e.g. one pointing at
    | "/registration"). Change the prefix in one place to move the lot.
    |
    | All route NAMES are prefixed with route_name_prefix ("registration."), so
    | the package's published views reference e.g. route('registration.group').
    | If you change the name prefix, publish and adjust the views accordingly.
    |
    */

    'route_prefix' => env('REGISTRATION_ROUTE_PREFIX', 'registration'),
    'route_name_prefix' => env('REGISTRATION_ROUTE_NAME_PREFIX', 'registration.'),

    /*
    |--------------------------------------------------------------------------
    | Host route names
    |--------------------------------------------------------------------------
    |
    | The package never owns authentication. Registering for the conference
    | requires an account, so the package's views link to the host's login and
    | account-creation screens using the route names below. Point them at whatever
    | the host application calls its login and registration routes.
    |
    */

    'login_route' => env('REGISTRATION_LOGIN_ROUTE', 'login'),
    'register_route' => env('REGISTRATION_REGISTER_ROUTE', 'register'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | "auth_middleware" guards the booking dashboard and checkout (the registrant
    | must be signed in via the host's auth system). "admin_middleware" guards
    | the booking/invoice admin console; it references the host-defined gate
    | below so the package never decides who may administer registrations.
    |
    */

    'web_middleware' => ['web'],
    'auth_middleware' => ['web', 'auth'],
    'admin_middleware' => ['web', 'auth', 'can:manage-registration'],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | The package does NOT define who an admin is. The host application defines
    | this gate (Gate::define('manage-registration', ...)). The default
    | admin_middleware above references it via "can:manage-registration".
    |
    */

    'admin_gate' => env('REGISTRATION_ADMIN_GATE', 'manage-registration'),

    /*
    |--------------------------------------------------------------------------
    | Host user model integration
    |--------------------------------------------------------------------------
    |
    | A registrant IS a host user. The package extends the host users table with
    | its own registration columns (see the package migration) and resolves the
    | host user model for its relationships and for creating registrant records.
    | It never owns the users table, authentication, or user administration.
    |
    | Add the ConferenceTools\Registration\Concerns\InteractsWithRegistration
    | trait to your user model to expose the registration relationships/helpers.
    |
    */

    'user_model' => env('REGISTRATION_USER_MODEL', 'App\\Models\\User'),
    'user_table' => env('REGISTRATION_USER_TABLE', 'users'),

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | All package-owned tables share this prefix to avoid collisions with host
    | tables (notably "groups" and "products"). The host users table is NOT
    | prefixed — the package only adds columns to it (see user_table above).
    |
    */

    'tables' => [
        'prefix' => env('REGISTRATION_TABLE_PREFIX', 'registration_'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Layout & branding
    |--------------------------------------------------------------------------
    |
    | Package views extend this layout. It defaults to the package's own neutral
    | Bootstrap layout; point it at the host layout (e.g. "layouts.app") to wrap
    | the registration screens in the host application's chrome.
    |
    */

    'layout' => env('REGISTRATION_LAYOUT', 'registration::layouts.app'),
    'brand_name' => env('REGISTRATION_BRAND_NAME', env('APP_NAME', 'Conference')),

    // Optional logo rendered on the package's branded screens — an absolute path
    // or URL to an image, or null to omit it.
    'logo_path' => env('REGISTRATION_LOGO', null),

    /*
    |--------------------------------------------------------------------------
    | Registration mail
    |--------------------------------------------------------------------------
    |
    | When a registrant completes the wizard, the package sends the two
    | admin-editable email templates (see the "Emails" admin console): a
    | confirmation to the registrant and a notification to the administrator
    | address. The administrator and from addresses are NOT configured here:
    | they change over time (unlike this deploy-time, environmental config),
    | so they are runtime settings edited on the same "Emails" console (see
    | RegistrationEmails). The sender falls back to the host's mail.from
    | address while unset; both are available in admin-authored texts as the
    | {admin_email} and {from_email} variables.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | LimeSurvey import
    |--------------------------------------------------------------------------
    |
    | Absolute path to a LimeSurvey structure export (.lss) for the LssImportSeeder
    | to bring into the configurable question infrastructure. Leave null to skip
    | the import (the seeder is a no-op). See ConferenceTools\Registration\Services
    | \LssImporter for exactly which survey features can and cannot be imported.
    |
    */

    'lss_import_path' => env('REGISTRATION_LSS_IMPORT', null),

];
