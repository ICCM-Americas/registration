# ConferenceTools Registration

A reusable Laravel package providing a **conference registration system** driven
by a configurable questionnaire: individual and group registration, a
drag-and-drop form builder, multi-currency pricing (priced options, flat base
charges and discount codes), and an admin console for the form and pricing. There
is no payment processing or checkout step — a committed registration simply
stays pending.

The package brings its own domain (models, migrations, views, routes, services).
**The host application keeps owning users, authentication and user
administration.** A registrant *is* a host user — the package extends the host
`users` table with the few columns it needs to link a registrant to their group
and booking state, and creates registrant records through the host user model,
but it never owns the users table, login, logout, password management or general
user administration.

- Namespace: `ConferenceTools\Registration`
- Composer package: `conference-tools/registration`
- View namespace: `registration::`
- Config / route-name prefix: `registration` / `registration.`
- Table prefix: `registration_` (configurable)

---

## What the package owns

Group & participant registration · A configurable EAV questionnaire (sections,
questions, typed answers) · A drag-and-drop **form builder** with nested AND/OR
conditional visibility rules · Multi-currency **pricing**: priced choice options,
flat base charges and discount-code formulae · An admin dashboard, form builder
and pricing console · Package views, routes, migrations, config and the few
registration columns added to the host `users` table.

## What stays with the host application

The `users` table, user registration-as-an-account, login, logout, password
reset, email verification, **general user administration**, the global site
layout / branding, and the decision of **who may administer registrations**. The
package never duplicates authentication and does not depend on a starter kit.

---

## How the registrant ↔ user boundary works

The source application modeled every registrant as a row in its `users` table.
This package preserves that, without taking over the users table:

- A migration **adds** only the structural registration columns to the host
  `users` table — `mail_id`, `checked_out`, `is_group_admin`, `group_id` — each
  only if not already present. The registrant's actual answers (last name,
  passport, gender, residence, chosen options…) are **not** columns; they live in
  the configurable EAV answer store (`registration_answers`).
- `is_group_admin` (a package-owned boolean) marks the user who registered a
  group. It replaces the source app's `role = 'groupadmin'` so the package never
  owns the host's authorization/role system.
- The package resolves registrants through `config('registration.user_model')`.
- **No database foreign key crosses the host boundary.** The package's tables
  reference users by `user_id` only (and `group_id` on `users` is a plain
  column), so the package is not tied to the host's database/connection. When the
  host deletes a user, a `deleting` listener removes that user's in-progress
  wizard draft.

### Account creation vs. registration (two separate processes)

Creating an **account** and **registering for the conference** are deliberately
separate:

- **Account = host application.** An account is only a username (email address)
  and a password. Creating it, logging in, logout, password reset and email
  verification are entirely the host's job. The package never creates accounts or
  sets credentials.
- **Registration = this package.** Registering fills the conference profile and
  group answers on an **already-authenticated** user, through a server-driven
  wizard. The registration form collects no email or password.

Because of this, the registration screen is **auth-gated** (it lives behind
`config('registration.auth_middleware')`). A visitor who is not signed in must
first log in or create an account through the host application; the package's
landing page links to the host's `login_route` / `register_route` for exactly
that. Once signed in, the user lands on the registration form.

The services reflect the split:

```php
// Self-registration: register the signed-in account as a new group's admin.
// The $user already exists (host created the account); no credentials are set.
app(GroupRegistrationService::class)->registerGroup($request->all(), $request->user());
```

The package never writes a user's account fields (email or password) — accounts
and credentials remain entirely the host's job.

### Completing a registration

There is no checkout or payment step. When the wizard's last step commits, the
registration is recorded and the registrant is returned to the landing page with
a confirmation; it stays pending (`checked_out` remains false) until admin
tooling decides otherwise. Pricing (base charges, priced options, discount
codes) is still computed, but the package collects no money.

---

## The configurable questionnaire

The registrant and group profiles are not hard-coded columns; they are a
configuration of **sections** and **questions** whose answers are stored as EAV
(`registration_answers`). Highlights:

- Question types: text, url, radio, checkbox, select, discount-code (see
  `Enums\QuestionType`), scoped to the participant or the group
  (`Enums\QuestionScope`).
- **Conditional visibility**: a question can be shown only when a nested AND/OR
  rule over other answers matches (`Enums\BooleanOperator` /
  `Enums\ConditionOperator`), edited in the admin visibility editor and enforced
  server-side during validation.
- **Priced options**: a choice option may carry a `cost`; a registrant's total is
  the enabled base charges plus their chosen option costs, with any entered
  discount-code formula applied.
- Admins manage all of this from the **form builder** and **pricing** console; the
  package ships no default form (build yours in the admin, or import one — see
  *LimeSurvey import*).

---

## Installation

### 1. Require the package

**From GitHub** (production). Add the repository and require it:

```jsonc
// composer.json
"repositories": [
    { "type": "vcs", "url": "https://github.com/TODO/registration" }
]
```

```bash
composer require conference-tools/registration:^1.0
```

> Replace the `url` above with the real repository URL — **TODO**.

**For local development** against a checkout, use a path repository:

```jsonc
"repositories": [
    { "type": "path", "url": "packages/conference-tools/registration" }
]
```

The service provider is auto-discovered.

> **Install `conference-tools/branding` alongside this package.** This package
> reads branding through `ConferenceTools\Branding\Contracts\BrandingProvider`,
> and its views are styled by the shared `iccm-*` stylesheets that package
> publishes. It is a hard runtime dependency but is deliberately **not** declared
> in this package's `composer.json` — install it in the host the same way you
> install this one (`composer require conference-tools/branding`). Branding is
> the *only* package this one is allowed to reach across a boundary to; see
> "The cross-boundary rule" in the branding package's README for why the
> requirement is documented rather than declared.

### 2. Add the trait to your user model

```php
use ConferenceTools\Registration\Concerns\InteractsWithRegistration;

class User extends Authenticatable
{
    use InteractsWithRegistration; // group(), registrationAnswers(), cost(), currencyString(), nameString()...
}
```

If your model uses `$fillable` (rather than `$guarded = []`), the package writes
its user columns with `forceFill`, so you do not need to add them to `$fillable`.

### 3. Provide login and account-creation routes

Registering for the conference requires an account, so the package links to — and
its auth-gated screens redirect to — the host's own authentication. Make sure your
app exposes named login and register routes and point the config at them
(defaults: `login` and `register`, e.g. Laravel UI / Breeze / Fortify):

```php
// config/registration.php (or via env)
'login_route'    => 'login',
'register_route' => 'register',
```

Your register screen should create an account from **email + password only**;
after it, redirect the new user to `route('registration.register')` to fill in
their conference details.

### 4. Define the admin authorization gate

The package never decides who may administer registrations. Define the gate its
admin routes reference (`config/auth` / a service provider):

```php
use Illuminate\Support\Facades\Gate;

Gate::define('manage-registration', fn ($user) => $user->is_admin ?? false);
```

### 5. Run the migrations

```bash
php artisan migrate
```

This creates the registration tables and adds the registration columns to your
`users` table.

### 6. Set up currencies and the form

There is no bundled reference-data seeder. After migrating, sign in as an admin
and use the **pricing** console to add at least one currency (exactly one must be
marked the default) plus any base charges and discount codes, and the **form
builder** to create your participant/group questions. A LimeSurvey structure
export can bootstrap the questions — see below.

---

## Configuration

Publish the config to customize it:

```bash
php artisan vendor:publish --tag=registration-config
```

Key options (see `config/registration.php` for all of them):

| Key | Purpose |
| --- | --- |
| `route_prefix` | URL prefix for the whole module (default `registration`). Public/booking screens sit under it and the admin console lives at `{prefix}/admin`. |
| `login_route` | Host route name the package links to for **login**. |
| `register_route` | Host route name the package links to for **account creation** (the host's own register screen). |
| `admin_gate` / `admin_middleware` | Host-defined gate guarding the admin console. |
| `auth_middleware` | Guards the registration form (registering requires a signed-in account). |
| `user_model` / `user_table` | Host user model and table the package integrates with. |
| `tables.prefix` | Prefix for all package-owned tables (default `registration_`). |
| `layout` | Blade layout the package views extend (default: the package's own). Point it at your host layout to wrap registration in your chrome. |
| `brand_name` | Conference name backing the default branding; override via a `BrandingProvider` binding (see below). |
| `logo_path` | Optional logo for the package's branded screens. |
| `lss_import_path` | Absolute path to a LimeSurvey `.lss` export for `LssImportSeeder`. |

### Views & layout

Package views extend `config('registration.layout')`, which defaults to a neutral
self-contained Bootstrap layout. Point it at your host layout (e.g.
`layouts.app`) to wrap the registration screens in your own chrome, or publish
and edit the views:

```bash
php artisan vendor:publish --tag=registration-views
```

> Both controllers and views resolve route names through the configured
> `route_name_prefix` (controllers via `Controller::routeName()`, views via the
> `$routeName` helper shared to `registration::*` views), so redirects and links
> keep working when you change it — no need to edit the views.

### Branding (provided by `conference-tools/branding`)

Branding is **not** part of this package — it lives in the separate
`conference-tools/branding` package, which **must be installed in the host
alongside this one**. The package's views receive a `$branding` object via a view
composer scoped to the package's own `registration::*` views, resolving
`ConferenceTools\Branding\Contracts\BrandingProvider`. The contract is small:

```php
interface BrandingProvider
{
    public function siteName(): string;
    public function color(string $key): string;   // primary|secondary|background|text
    public function logoUrl(): ?string;
}
```

The package's default layout reads these: `siteName()` for the title and brand,
`color('primary'|'secondary'|'background'|'text')` for the theme, and `logoUrl()`
for the brand logo. The branding package binds a real implementation (its
`BrandingService`, backed by the branding admin screen), so once it is installed
the package's views show the host's configured branding with no wiring on your
part. If the branding package is somehow absent, this package falls back to its
own `Support\DefaultBranding`, which reads `registration.brand_name` and
`registration.logo_path`, so views still render.

### Translations

All display strings in the core UI use Laravel localization under the
`registration::` namespace. Publish the language files to translate or reword
them:

```bash
php artisan vendor:publish --tag=registration-lang
```

---

## LimeSurvey import

A LimeSurvey structure export (`.lss`) can bootstrap the configurable questions.
Point `registration.lss_import_path` at the file and run the importer seeder:

```bash
php artisan db:seed --class="ConferenceTools\\Registration\\Database\\Seeders\\LssImportSeeder"
```

See `ConferenceTools\Registration\Services\LssImporter` for exactly which survey
features can and cannot be imported. Leave the path null to skip it (the seeder is
a no-op).

---

## Extension points

The package resolves replaceable behavior through the container, so a host can
bind its own implementation without forking the package:

| Contract | Default | Swap it to… |
| --- | --- | --- |
| `ConferenceTools\Branding\Contracts\BrandingProvider` (from `conference-tools/branding`) | `Support\DefaultBranding` | use your own site name, colors and logo. |

`Gender` (`Enums\Gender`, `m`/`f`) is a typed enum used in validation and
seeding; the questionnaire's structural enums (`QuestionType`, `QuestionScope`,
`ConditionOperator`, `BooleanOperator`) drive the form builder and visibility
engine.

## Authorization boundary

Admin routes are guarded by `config('registration.admin_middleware')`, which by
default contains `can:manage-registration`. The package defines neither a role
system nor the gate — the host does. Change `admin_gate` / `admin_middleware` to
use a different mechanism.

---

## Testing

```bash
composer install
composer test
```

The suite uses `orchestra/testbench`; a fixture user model
(`tests/Fixtures/User.php`) stands in for the host user model, and
`tests/Fixtures/QuestionConfigSeeder.php` stands up a representative questionnaire
for the feature tests.

## Acknowledgments

This package is inspired by (and should be compatible with) the efforts of the
awesome ICCM Africa team who created their own custom registration software.

## License

MIT.
