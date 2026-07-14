<?php

use ConferenceTools\Registration\Http\Controllers\Admin\AnswersController;
use ConferenceTools\Registration\Http\Controllers\Admin\BadgeController;
use ConferenceTools\Registration\Http\Controllers\Admin\BadgeLayoutController;
use ConferenceTools\Registration\Http\Controllers\Admin\ClosedMessageController;
use ConferenceTools\Registration\Http\Controllers\Admin\ConferenceController;
use ConferenceTools\Registration\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use ConferenceTools\Registration\Http\Controllers\Admin\EmailTemplateController;
use ConferenceTools\Registration\Http\Controllers\Admin\HomeCardMessageController;
use ConferenceTools\Registration\Http\Controllers\Admin\InfoStepController;
use ConferenceTools\Registration\Http\Controllers\Admin\LogisticsController;
use ConferenceTools\Registration\Http\Controllers\Admin\PaymentsController;
use ConferenceTools\Registration\Http\Controllers\Admin\PrayerPalsController;
use ConferenceTools\Registration\Http\Controllers\Admin\PricingController;
use ConferenceTools\Registration\Http\Controllers\Admin\QuestionBuilderController;
use ConferenceTools\Registration\Http\Controllers\Admin\QuestionOptionVisibilityController;
use ConferenceTools\Registration\Http\Controllers\Admin\QuestionVisibilityController;
use ConferenceTools\Registration\Http\Controllers\Admin\RegistrationWindowController;
use ConferenceTools\Registration\Http\Controllers\Admin\ReportColumnMappingController;
use ConferenceTools\Registration\Http\Controllers\Admin\ReportColumnVisibilityController;
use ConferenceTools\Registration\Http\Controllers\Admin\ReportController;
use ConferenceTools\Registration\Http\Controllers\Admin\ReportVisibilityController;
use ConferenceTools\Registration\Http\Controllers\Admin\RoomAssignmentController;
use ConferenceTools\Registration\Http\Controllers\Admin\RoomController;
use ConferenceTools\Registration\Http\Controllers\Admin\SectionVisibilityController;
use ConferenceTools\Registration\Http\Controllers\Admin\ShuttleScheduleController;
use ConferenceTools\Registration\Http\Controllers\Admin\TestGroupMemberController;
use ConferenceTools\Registration\Http\Controllers\Admin\TestGuestController;
use ConferenceTools\Registration\Http\Controllers\Admin\TestRegistrationController;
use ConferenceTools\Registration\Http\Controllers\Admin\TranslationController;
use ConferenceTools\Registration\Http\Controllers\Admin\VariableController;
use ConferenceTools\Registration\Http\Controllers\GroupInviteController;
use ConferenceTools\Registration\Http\Controllers\GroupMemberController;
use ConferenceTools\Registration\Http\Controllers\GuestController;
use ConferenceTools\Registration\Http\Controllers\MyGroupMemberController;
use ConferenceTools\Registration\Http\Controllers\MyGuestController;
use ConferenceTools\Registration\Http\Controllers\MyRegistrationController;
use ConferenceTools\Registration\Http\Controllers\RegistrationController;
use ConferenceTools\Registration\Http\Middleware\EnsureRegistrationIsOpen;
use Illuminate\Support\Facades\Route;

$namePrefix = config('registration.route_name_prefix');
$routePrefix = config('registration.route_prefix');

// The admin console lives under the same registration prefix, at "{prefix}/admin"
// (mirroring the BoF scheduler). Driven entirely by route_prefix so the whole
// module — public, authenticated and admin — moves together.
$adminPrefix = trim($routePrefix.'/admin', '/');

/*
|--------------------------------------------------------------------------
| Public registration routes
|--------------------------------------------------------------------------
| The information/landing page (at the prefix root), under route_prefix (e.g.
| "/registration"). The registration form itself is NOT public — it requires an
| account and lives in the authenticated group below.
|
| Both registrant-facing groups sit behind EnsureRegistrationIsOpen: outside
| the admin-set registration window they answer with the "closed" page.
*/
Route::middleware([...config('registration.web_middleware'), EnsureRegistrationIsOpen::class])
    ->prefix($routePrefix)
    ->group(function () use ($namePrefix) {
        Route::get('/', [RegistrationController::class, 'info'])->name($namePrefix.'info');

        // A group leader's invite link: remembers the invite for the wizard
        // (see GroupInviteController), then hands off to the host's own
        // login/signup — the invitee needn't be authenticated yet.
        Route::get('register/invite/{token}', [GroupInviteController::class, 'accept'])->name($namePrefix.'register.invite.accept');
    });

/*
|--------------------------------------------------------------------------
| Authenticated registrant routes (registration wizard)
|--------------------------------------------------------------------------
*/
Route::middleware([...config('registration.auth_middleware'), EnsureRegistrationIsOpen::class])
    ->prefix($routePrefix)
    ->group(function () use ($namePrefix) {
        // Registering for the conference requires an account: the host application
        // handles login / account creation, then the signed-in user fills in their
        // group and conference profile here.
        Route::get('register', [RegistrationController::class, 'showForm'])->name($namePrefix.'register');
        Route::post('register', [RegistrationController::class, 'register'])->name($namePrefix.'register.store');

        // Guest List hub: reached as a detour from register.store once the
        // registrant indicates they're bringing a non-attending guest — see
        // RegistrationController::register() and GuestController.
        Route::get('register/guests', [GuestController::class, 'index'])->name($namePrefix.'register.guests');
        Route::get('register/guests/create', [GuestController::class, 'create'])->name($namePrefix.'register.guests.create');
        Route::post('register/guests', [GuestController::class, 'store'])->name($namePrefix.'register.guests.store');
        Route::get('register/guests/{guest}/edit', [GuestController::class, 'edit'])->name($namePrefix.'register.guests.edit');
        Route::post('register/guests/{guest}', [GuestController::class, 'update'])->name($namePrefix.'register.guests.update');
        Route::delete('register/guests/{guest}', [GuestController::class, 'destroy'])->name($namePrefix.'register.guests.destroy');

        // Group Member hub: reached as a detour from register.store once the
        // registrant indicates they're registering a group — see
        // RegistrationController::register() and GroupMemberController.
        Route::get('register/group-members', [GroupMemberController::class, 'index'])->name($namePrefix.'register.group_members');
        Route::get('register/group-members/create', [GroupMemberController::class, 'create'])->name($namePrefix.'register.group_members.create');
        Route::post('register/group-members', [GroupMemberController::class, 'store'])->name($namePrefix.'register.group_members.store');
        Route::get('register/group-members/{member}/edit', [GroupMemberController::class, 'edit'])->name($namePrefix.'register.group_members.edit');
        Route::post('register/group-members/{member}', [GroupMemberController::class, 'update'])->name($namePrefix.'register.group_members.update');
        Route::delete('register/group-members/{member}', [GroupMemberController::class, 'destroy'])->name($namePrefix.'register.group_members.destroy');
    });

/*
|--------------------------------------------------------------------------
| "My Registration" — the registrant-facing view/modify page
|--------------------------------------------------------------------------
| Deliberately NOT wrapped in EnsureRegistrationIsOpen: a registrant must be
| able to view their own already-committed registration (and find the
| administrator's address) even once registration has closed. Eligibility to
| modify is checked in the controller instead (see MyRegistrationController).
*/
Route::middleware(config('registration.auth_middleware'))
    ->prefix($routePrefix)
    ->group(function () use ($namePrefix) {
        Route::get('mine', [MyRegistrationController::class, 'show'])->name($namePrefix.'mine');
        Route::get('mine/edit', [MyRegistrationController::class, 'edit'])->name($namePrefix.'mine.edit');
        Route::post('mine/edit', [MyRegistrationController::class, 'update'])->name($namePrefix.'mine.update');

        // Post-commit guest management: add/edit/remove real Guest rows — see MyGuestController.
        Route::get('mine/guests', [MyGuestController::class, 'index'])->name($namePrefix.'mine.guests');
        Route::get('mine/guests/create', [MyGuestController::class, 'create'])->name($namePrefix.'mine.guests.create');
        Route::post('mine/guests', [MyGuestController::class, 'store'])->name($namePrefix.'mine.guests.store');
        Route::get('mine/guests/{guest}/edit', [MyGuestController::class, 'edit'])->name($namePrefix.'mine.guests.edit');
        Route::post('mine/guests/{guest}', [MyGuestController::class, 'update'])->name($namePrefix.'mine.guests.update');
        Route::delete('mine/guests/{guest}', [MyGuestController::class, 'destroy'])->name($namePrefix.'mine.guests.destroy');

        // Post-commit group-member management: add/edit/remove real GroupInvite rows — see MyGroupMemberController.
        Route::get('mine/group-members', [MyGroupMemberController::class, 'index'])->name($namePrefix.'mine.group_members');
        Route::get('mine/group-members/create', [MyGroupMemberController::class, 'create'])->name($namePrefix.'mine.group_members.create');
        Route::post('mine/group-members', [MyGroupMemberController::class, 'store'])->name($namePrefix.'mine.group_members.store');
        Route::get('mine/group-members/{member}/edit', [MyGroupMemberController::class, 'edit'])->name($namePrefix.'mine.group_members.edit');
        Route::post('mine/group-members/{member}', [MyGroupMemberController::class, 'update'])->name($namePrefix.'mine.group_members.update');
        Route::delete('mine/group-members/{member}', [MyGroupMemberController::class, 'destroy'])->name($namePrefix.'mine.group_members.destroy');
    });

/*
|--------------------------------------------------------------------------
| Admin booking console
|--------------------------------------------------------------------------
| Gated by the host-defined "manage-registration" gate (see config). The package
| never administers users — only the registration form configuration and pricing.
*/
Route::middleware(config('registration.admin_middleware'))
    ->prefix($adminPrefix)
    ->group(function () use ($namePrefix) {
        Route::get('/', [AdminDashboardController::class, 'index'])->name($namePrefix.'admin.dashboard');

        // Test drive: an admin walks the whole registration wizard without
        // anything being recorded — progress stays in the session and both
        // completion emails go to the signed-in admin. Deliberately NOT behind
        // EnsureRegistrationIsOpen: a test run records nothing, so the
        // registration window must not gate it.
        Route::get('test-registration', [TestRegistrationController::class, 'showForm'])->name($namePrefix.'admin.test');
        Route::post('test-registration', [TestRegistrationController::class, 'register'])->name($namePrefix.'admin.test.store');

        // Guest List hub for the test drive — mirrors the registrant-facing
        // routes above, served against the session-held TestDraft instead.
        Route::get('test-registration/guests', [TestGuestController::class, 'index'])->name($namePrefix.'admin.test.guests');
        Route::get('test-registration/guests/create', [TestGuestController::class, 'create'])->name($namePrefix.'admin.test.guests.create');
        Route::post('test-registration/guests', [TestGuestController::class, 'store'])->name($namePrefix.'admin.test.guests.store');
        Route::get('test-registration/guests/{guest}/edit', [TestGuestController::class, 'edit'])->name($namePrefix.'admin.test.guests.edit');
        Route::post('test-registration/guests/{guest}', [TestGuestController::class, 'update'])->name($namePrefix.'admin.test.guests.update');
        Route::delete('test-registration/guests/{guest}', [TestGuestController::class, 'destroy'])->name($namePrefix.'admin.test.guests.destroy');

        // Group Member hub for the test drive — mirrors the registrant-facing
        // routes above, served against the session-held TestDraft instead.
        Route::get('test-registration/group-members', [TestGroupMemberController::class, 'index'])->name($namePrefix.'admin.test.group_members');
        Route::get('test-registration/group-members/create', [TestGroupMemberController::class, 'create'])->name($namePrefix.'admin.test.group_members.create');
        Route::post('test-registration/group-members', [TestGroupMemberController::class, 'store'])->name($namePrefix.'admin.test.group_members.store');
        Route::get('test-registration/group-members/{member}/edit', [TestGroupMemberController::class, 'edit'])->name($namePrefix.'admin.test.group_members.edit');
        Route::post('test-registration/group-members/{member}', [TestGroupMemberController::class, 'update'])->name($namePrefix.'admin.test.group_members.update');
        Route::delete('test-registration/group-members/{member}', [TestGroupMemberController::class, 'destroy'])->name($namePrefix.'admin.test.group_members.destroy');

        // Registration window: when registration is open to registrants. The
        // schedule form sets the dates; "open/close now" write the same dates.
        Route::put('window', [RegistrationWindowController::class, 'update'])->name($namePrefix.'admin.window.update');
        Route::post('window/open', [RegistrationWindowController::class, 'open'])->name($namePrefix.'admin.window.open');
        Route::post('window/close', [RegistrationWindowController::class, 'close'])->name($namePrefix.'admin.window.close');

        // Conference edition: the name/year all admin-authored texts reference
        // via {conference_name}/{conference_year}; "start next" rolls it over
        // while archiving the outgoing edition for the closed page.
        Route::put('conference', [ConferenceController::class, 'update'])->name($namePrefix.'admin.conference.update');
        Route::post('conference/next', [ConferenceController::class, 'startNext'])->name($namePrefix.'admin.conference.next');

        // Registration data exports: a .ZIP of flattened CSVs (answers, groups,
        // payments, room assignments, Prayer Pals) for the current cycle, or
        // for the single retained archive, in the same layout.
        Route::get('conference/registrations/csv', [ConferenceController::class, 'csvRegistrations'])->name($namePrefix.'admin.conference.registrations.csv');
        Route::get('conference/archive/csv', [ConferenceController::class, 'csvArchive'])->name($namePrefix.'admin.conference.archive.csv');

        // Data reset: wipes every answer, group, draft and room assignment (a
        // clean slate for a testing pass or to clear seeded demo data). Never
        // deletes user accounts.
        Route::delete('data/answers', [AnswersController::class, 'destroy'])->name($namePrefix.'admin.data.answers.destroy');

        // Form builder: arrange sections/questions (drag & drop) and edit them.
        Route::get('questions', [QuestionBuilderController::class, 'index'])->name($namePrefix.'admin.questions');
        Route::post('questions/reorder', [QuestionBuilderController::class, 'reorder'])->name($namePrefix.'admin.questions.reorder');
        Route::get('questions/create', [QuestionBuilderController::class, 'createQuestion'])->name($namePrefix.'admin.questions.create');
        Route::post('questions', [QuestionBuilderController::class, 'storeQuestion'])->name($namePrefix.'admin.questions.store');
        Route::get('questions/{question}/edit', [QuestionBuilderController::class, 'editQuestion'])->name($namePrefix.'admin.questions.edit');
        Route::put('questions/{question}', [QuestionBuilderController::class, 'updateQuestion'])->name($namePrefix.'admin.questions.update');
        Route::delete('questions/{question}', [QuestionBuilderController::class, 'destroyQuestion'])->name($namePrefix.'admin.questions.destroy');
        // AJAX: persist a new option row the moment its line is committed, so
        // its Visibility button works before the question form is saved.
        Route::post('questions/{question}/options', [QuestionBuilderController::class, 'storeOption'])->name($namePrefix.'admin.questions.options.store');

        Route::post('sections', [QuestionBuilderController::class, 'storeSection'])->name($namePrefix.'admin.sections.store');
        Route::put('sections/{section}', [QuestionBuilderController::class, 'updateSection'])->name($namePrefix.'admin.sections.update');
        Route::delete('sections/{section}', [QuestionBuilderController::class, 'destroySection'])->name($namePrefix.'admin.sections.destroy');

        // Visibility-rule editor: nested AND/OR condition tree for a question.
        Route::get('questions/{question}/visibility', [QuestionVisibilityController::class, 'edit'])->name($namePrefix.'admin.questions.visibility');
        Route::post('questions/{question}/rule', [QuestionVisibilityController::class, 'storeRoot'])->name($namePrefix.'admin.questions.rule.store');
        Route::delete('questions/{question}/rule', [QuestionVisibilityController::class, 'destroyRule'])->name($namePrefix.'admin.questions.rule.destroy');
        Route::post('questions/{question}/hide', [QuestionVisibilityController::class, 'hide'])->name($namePrefix.'admin.questions.hide');
        Route::post('questions/{question}/show', [QuestionVisibilityController::class, 'show'])->name($namePrefix.'admin.questions.show');
        Route::post('questions/{question}/groups', [QuestionVisibilityController::class, 'storeGroup'])->name($namePrefix.'admin.questions.groups.store');
        Route::patch('questions/{question}/groups/{group}', [QuestionVisibilityController::class, 'updateGroup'])->name($namePrefix.'admin.questions.groups.update');
        Route::delete('questions/{question}/groups/{group}', [QuestionVisibilityController::class, 'destroyGroup'])->name($namePrefix.'admin.questions.groups.destroy');
        Route::post('questions/{question}/conditions', [QuestionVisibilityController::class, 'storeCondition'])->name($namePrefix.'admin.questions.conditions.store');
        Route::delete('questions/{question}/conditions/{condition}', [QuestionVisibilityController::class, 'destroyCondition'])->name($namePrefix.'admin.questions.conditions.destroy');

        // The same rule editor on a whole section (wizard step): a failing rule
        // or "never show" skips the step — and with it every question on it —
        // regardless of the questions' own visibility.
        Route::get('sections/{section}/visibility', [SectionVisibilityController::class, 'edit'])->name($namePrefix.'admin.sections.visibility');
        Route::post('sections/{section}/rule', [SectionVisibilityController::class, 'storeRoot'])->name($namePrefix.'admin.sections.rule.store');
        Route::delete('sections/{section}/rule', [SectionVisibilityController::class, 'destroyRule'])->name($namePrefix.'admin.sections.rule.destroy');
        Route::post('sections/{section}/hide', [SectionVisibilityController::class, 'hide'])->name($namePrefix.'admin.sections.hide');
        Route::post('sections/{section}/show', [SectionVisibilityController::class, 'show'])->name($namePrefix.'admin.sections.show');
        Route::post('sections/{section}/groups', [SectionVisibilityController::class, 'storeGroup'])->name($namePrefix.'admin.sections.groups.store');
        Route::patch('sections/{section}/groups/{group}', [SectionVisibilityController::class, 'updateGroup'])->name($namePrefix.'admin.sections.groups.update');
        Route::delete('sections/{section}/groups/{group}', [SectionVisibilityController::class, 'destroyGroup'])->name($namePrefix.'admin.sections.groups.destroy');
        Route::post('sections/{section}/conditions', [SectionVisibilityController::class, 'storeCondition'])->name($namePrefix.'admin.sections.conditions.store');
        Route::delete('sections/{section}/conditions/{condition}', [SectionVisibilityController::class, 'destroyCondition'])->name($namePrefix.'admin.sections.conditions.destroy');

        // The same rule editor on a single option of a choice question, for
        // conditionally-offered options. Options have no hide/show state.
        Route::get('options/{option}/visibility', [QuestionOptionVisibilityController::class, 'edit'])->name($namePrefix.'admin.options.visibility');
        Route::post('options/{option}/rule', [QuestionOptionVisibilityController::class, 'storeRoot'])->name($namePrefix.'admin.options.rule.store');
        Route::delete('options/{option}/rule', [QuestionOptionVisibilityController::class, 'destroyRule'])->name($namePrefix.'admin.options.rule.destroy');
        Route::post('options/{option}/groups', [QuestionOptionVisibilityController::class, 'storeGroup'])->name($namePrefix.'admin.options.groups.store');
        Route::patch('options/{option}/groups/{group}', [QuestionOptionVisibilityController::class, 'updateGroup'])->name($namePrefix.'admin.options.groups.update');
        Route::delete('options/{option}/groups/{group}', [QuestionOptionVisibilityController::class, 'destroyGroup'])->name($namePrefix.'admin.options.groups.destroy');
        Route::post('options/{option}/conditions', [QuestionOptionVisibilityController::class, 'storeCondition'])->name($namePrefix.'admin.options.conditions.store');
        Route::delete('options/{option}/conditions/{condition}', [QuestionOptionVisibilityController::class, 'destroyCondition'])->name($namePrefix.'admin.options.conditions.destroy');

        // Cost provisions: allowed currencies, the flat conference base charges,
        // and the discount codes.
        Route::get('pricing', [PricingController::class, 'index'])->name($namePrefix.'admin.pricing');

        Route::post('pricing/currencies', [PricingController::class, 'storeCurrency'])->name($namePrefix.'admin.pricing.currencies.store');
        Route::put('pricing/currencies/{currency}', [PricingController::class, 'updateCurrency'])->name($namePrefix.'admin.pricing.currencies.update');
        Route::delete('pricing/currencies/{currency}', [PricingController::class, 'destroyCurrency'])->name($namePrefix.'admin.pricing.currencies.destroy');

        Route::post('pricing/base-charges', [PricingController::class, 'storeBaseCharge'])->name($namePrefix.'admin.pricing.charges.store');
        Route::put('pricing/base-charges/{baseCharge}', [PricingController::class, 'updateBaseCharge'])->name($namePrefix.'admin.pricing.charges.update');
        Route::delete('pricing/base-charges/{baseCharge}', [PricingController::class, 'destroyBaseCharge'])->name($namePrefix.'admin.pricing.charges.destroy');

        Route::post('pricing/discount-codes', [PricingController::class, 'storeDiscountCode'])->name($namePrefix.'admin.pricing.discounts.store');
        Route::put('pricing/discount-codes/{discountCode}', [PricingController::class, 'updateDiscountCode'])->name($namePrefix.'admin.pricing.discounts.update');
        Route::delete('pricing/discount-codes/{discountCode}', [PricingController::class, 'destroyDiscountCode'])->name($namePrefix.'admin.pricing.discounts.destroy');

        Route::put('pricing/per-diem', [PricingController::class, 'updatePerDiem'])->name($namePrefix.'admin.pricing.per_diem.update');

        // Landing page: the numbered how-to steps shown to visitors before
        // login. Mirrors the form builder: drag & drop reorder plus a form
        // page per step, and hide/show instead of a rule editor (the steps
        // are display-only, so visibility is a plain toggle).
        Route::get('steps', [InfoStepController::class, 'index'])->name($namePrefix.'admin.steps');
        Route::post('steps/reorder', [InfoStepController::class, 'reorder'])->name($namePrefix.'admin.steps.reorder');
        Route::get('steps/create', [InfoStepController::class, 'create'])->name($namePrefix.'admin.steps.create');
        Route::post('steps', [InfoStepController::class, 'store'])->name($namePrefix.'admin.steps.store');
        Route::get('steps/{step}/edit', [InfoStepController::class, 'edit'])->name($namePrefix.'admin.steps.edit');
        Route::put('steps/{step}', [InfoStepController::class, 'update'])->name($namePrefix.'admin.steps.update');
        Route::delete('steps/{step}', [InfoStepController::class, 'destroy'])->name($namePrefix.'admin.steps.destroy');
        Route::post('steps/{step}/hide', [InfoStepController::class, 'hide'])->name($namePrefix.'admin.steps.hide');
        Route::post('steps/{step}/show', [InfoStepController::class, 'show'])->name($namePrefix.'admin.steps.show');

        // Emails: the registration email addresses (runtime settings — see
        // RegistrationEmails) and the fixed registration email templates
        // (registrant confirmation, administrator notification) sent when a
        // registration commits; only their subject/body texts are editable.
        // The addresses route is declared first so "addresses" is never
        // captured by the {key} template route.
        Route::get('emails', [EmailTemplateController::class, 'index'])->name($namePrefix.'admin.emails');
        Route::put('emails/addresses', [EmailTemplateController::class, 'updateAddresses'])->name($namePrefix.'admin.emails.addresses');
        Route::put('emails/{key}', [EmailTemplateController::class, 'update'])->name($namePrefix.'admin.emails.update');

        // Closed page: the fixed set of messages shown while registration is
        // closed, one per window state; each is edited on its own form page
        // and translated through the shared translations editor.
        Route::get('closed-messages', [ClosedMessageController::class, 'index'])->name($namePrefix.'admin.closed');
        Route::get('closed-messages/{key}/edit', [ClosedMessageController::class, 'edit'])->name($namePrefix.'admin.closed.edit');
        Route::put('closed-messages/{key}', [ClosedMessageController::class, 'update'])->name($namePrefix.'admin.closed.update');

        // Home card messages: the fixed set of messages shown on the host
        // application's home dashboard registration card while registration
        // is not open (see HomeCardMessage) — distinct from the closed page
        // above, which serves the package's own registration pages.
        Route::get('home-card-messages', [HomeCardMessageController::class, 'index'])->name($namePrefix.'admin.home_card_messages');
        Route::get('home-card-messages/{key}/edit', [HomeCardMessageController::class, 'edit'])->name($namePrefix.'admin.home_card_messages.edit');
        Route::put('home-card-messages/{key}', [HomeCardMessageController::class, 'update'])->name($namePrefix.'admin.home_card_messages.update');

        // Variables: admin-defined "{name}" tokens interpolated into the steps
        // and the questionnaire's question/option texts.
        Route::get('variables', [VariableController::class, 'index'])->name($namePrefix.'admin.variables');
        Route::post('variables', [VariableController::class, 'store'])->name($namePrefix.'admin.variables.store');
        Route::put('variables/{variable}', [VariableController::class, 'update'])->name($namePrefix.'admin.variables.update');
        Route::delete('variables/{variable}', [VariableController::class, 'destroy'])->name($namePrefix.'admin.variables.destroy');

        // Logistics: links to the interactive consoles (badges, rooms,
        // shuttles, Prayer Pals) and the settings that feed them — question
        // nominations, matching answer values, and shuttle capacity/timing.
        Route::get('logistics', [LogisticsController::class, 'index'])->name($namePrefix.'admin.logistics');
        Route::put('logistics/settings', [LogisticsController::class, 'update'])->name($namePrefix.'admin.logistics.settings');

        Route::get('logistics/badges', [BadgeController::class, 'index'])->name($namePrefix.'admin.logistics.badges');
        Route::get('logistics/badges/csv', [BadgeController::class, 'csv'])->name($namePrefix.'admin.logistics.badges.csv');
        // Badge layout designer: a separate page from the badges report itself,
        // where an admin arranges badge name/logo/conference name/organization
        // plus static text/images, independently per card size.
        Route::get('logistics/badges/layout', [BadgeLayoutController::class, 'index'])->name($namePrefix.'admin.logistics.badges.layout');
        Route::post('logistics/badges/layout', [BadgeLayoutController::class, 'store'])->name($namePrefix.'admin.logistics.badges.layout.store');
        Route::post('logistics/badges/layout/seed', [BadgeLayoutController::class, 'seedDefaults'])->name($namePrefix.'admin.logistics.badges.layout.seed');
        Route::patch('logistics/badges/layout/{element}/position', [BadgeLayoutController::class, 'updatePosition'])->name($namePrefix.'admin.logistics.badges.layout.position');
        Route::put('logistics/badges/layout/{element}', [BadgeLayoutController::class, 'update'])->name($namePrefix.'admin.logistics.badges.layout.update');
        Route::delete('logistics/badges/layout/{element}', [BadgeLayoutController::class, 'destroy'])->name($namePrefix.'admin.logistics.badges.layout.destroy');
        Route::get('logistics/shuttles', [ShuttleScheduleController::class, 'index'])->name($namePrefix.'admin.logistics.shuttles');
        Route::get('logistics/shuttles/csv', [ShuttleScheduleController::class, 'csv'])->name($namePrefix.'admin.logistics.shuttles.csv');
        // The travel-answer editor behind the schedule's "unreadable" names:
        // a fragment for the shared editor modal, showing the flight answer
        // exactly as entered so the admin can fix it into something readable.
        Route::get('logistics/shuttles/{flight}/{user}', [ShuttleScheduleController::class, 'editFlight'])
            ->whereIn('flight', ['arrival', 'departure'])->whereNumber('user')
            ->name($namePrefix.'admin.logistics.shuttles.flight');
        Route::put('logistics/shuttles/{flight}/{user}', [ShuttleScheduleController::class, 'updateFlight'])
            ->whereIn('flight', ['arrival', 'departure'])->whereNumber('user')
            ->name($namePrefix.'admin.logistics.shuttles.flight.update');

        // Prayer Pals: every registrant split by sex, manually clustered into
        // small single-sex groups (never auto-grouped) whose number/letter
        // also then places on the name badge — see PrayerPalsController.
        Route::get('logistics/prayer-pals', [PrayerPalsController::class, 'index'])->name($namePrefix.'admin.logistics.prayer_pals');
        Route::put('logistics/prayer-pals/settings', [PrayerPalsController::class, 'updateSettings'])->name($namePrefix.'admin.logistics.prayer_pals.settings');
        Route::post('logistics/prayer-pals/groups', [PrayerPalsController::class, 'storeGroup'])->name($namePrefix.'admin.logistics.prayer_pals.groups.store');
        Route::delete('logistics/prayer-pals/groups/{group}', [PrayerPalsController::class, 'destroyGroup'])->name($namePrefix.'admin.logistics.prayer_pals.groups.destroy');
        Route::post('logistics/prayer-pals/assignments', [PrayerPalsController::class, 'assign'])->name($namePrefix.'admin.logistics.prayer_pals.assign');
        Route::delete('logistics/prayer-pals/assignments/{assignment}', [PrayerPalsController::class, 'unassign'])->name($namePrefix.'admin.logistics.prayer_pals.unassign');

        // Admin-defined reports: the Reports page lists them; each one is
        // built from question/built-in columns with per-row cell rules and a
        // row rule (both reusing the visibility rule tree editor). Every
        // report is a web page carrying print styling plus CSV and
        // client-side PDF exports. {report}/{column} are constrained numeric
        // so literals like reports/create above are never captured as ids.
        Route::get('reports', [ReportController::class, 'index'])->name($namePrefix.'admin.reports');
        Route::get('reports/create', [ReportController::class, 'create'])->name($namePrefix.'admin.reports.create');
        Route::post('reports', [ReportController::class, 'store'])->name($namePrefix.'admin.reports.store');
        Route::get('reports/{report}', [ReportController::class, 'show'])->whereNumber('report')->name($namePrefix.'admin.reports.show');
        Route::get('reports/{report}/csv', [ReportController::class, 'csv'])->whereNumber('report')->name($namePrefix.'admin.reports.csv');
        Route::get('reports/{report}/edit', [ReportController::class, 'edit'])->whereNumber('report')->name($namePrefix.'admin.reports.edit');
        Route::put('reports/{report}', [ReportController::class, 'update'])->whereNumber('report')->name($namePrefix.'admin.reports.update');
        Route::delete('reports/{report}', [ReportController::class, 'destroy'])->whereNumber('report')->name($namePrefix.'admin.reports.destroy');
        Route::post('reports/{report}/columns', [ReportController::class, 'storeColumn'])->whereNumber('report')->name($namePrefix.'admin.reports.columns.store');
        Route::post('reports/{report}/columns/reorder', [ReportController::class, 'reorderColumns'])->whereNumber('report')->name($namePrefix.'admin.reports.columns.reorder');
        Route::put('reports/{report}/columns/{column}', [ReportController::class, 'updateColumn'])->whereNumber('report')->name($namePrefix.'admin.reports.columns.update');
        Route::delete('reports/{report}/columns/{column}', [ReportController::class, 'destroyColumn'])->whereNumber('report')->name($namePrefix.'admin.reports.columns.destroy');

        // The rule editor on a report's row rule (which registrants appear).
        Route::get('reports/{report}/visibility', [ReportVisibilityController::class, 'edit'])->whereNumber('report')->name($namePrefix.'admin.reports.visibility');
        Route::post('reports/{report}/rule', [ReportVisibilityController::class, 'storeRoot'])->whereNumber('report')->name($namePrefix.'admin.reports.rule.store');
        Route::delete('reports/{report}/rule', [ReportVisibilityController::class, 'destroyRule'])->whereNumber('report')->name($namePrefix.'admin.reports.rule.destroy');
        Route::post('reports/{report}/groups', [ReportVisibilityController::class, 'storeGroup'])->whereNumber('report')->name($namePrefix.'admin.reports.groups.store');
        Route::patch('reports/{report}/groups/{group}', [ReportVisibilityController::class, 'updateGroup'])->whereNumber('report')->name($namePrefix.'admin.reports.groups.update');
        Route::delete('reports/{report}/groups/{group}', [ReportVisibilityController::class, 'destroyGroup'])->whereNumber('report')->name($namePrefix.'admin.reports.groups.destroy');
        Route::post('reports/{report}/conditions', [ReportVisibilityController::class, 'storeCondition'])->whereNumber('report')->name($namePrefix.'admin.reports.conditions.store');
        Route::delete('reports/{report}/conditions/{condition}', [ReportVisibilityController::class, 'destroyCondition'])->whereNumber('report')->name($namePrefix.'admin.reports.conditions.destroy');

        // The same rule editor on a single column's per-row cell rule (when
        // the cell shows on a given row).
        Route::get('report-columns/{column}/visibility', [ReportColumnVisibilityController::class, 'edit'])->name($namePrefix.'admin.report_columns.visibility');
        Route::post('report-columns/{column}/rule', [ReportColumnVisibilityController::class, 'storeRoot'])->name($namePrefix.'admin.report_columns.rule.store');
        Route::delete('report-columns/{column}/rule', [ReportColumnVisibilityController::class, 'destroyRule'])->name($namePrefix.'admin.report_columns.rule.destroy');
        Route::post('report-columns/{column}/groups', [ReportColumnVisibilityController::class, 'storeGroup'])->name($namePrefix.'admin.report_columns.groups.store');
        Route::patch('report-columns/{column}/groups/{group}', [ReportColumnVisibilityController::class, 'updateGroup'])->name($namePrefix.'admin.report_columns.groups.update');
        Route::delete('report-columns/{column}/groups/{group}', [ReportColumnVisibilityController::class, 'destroyGroup'])->name($namePrefix.'admin.report_columns.groups.destroy');
        Route::post('report-columns/{column}/conditions', [ReportColumnVisibilityController::class, 'storeCondition'])->name($namePrefix.'admin.report_columns.conditions.store');
        Route::delete('report-columns/{column}/conditions/{condition}', [ReportColumnVisibilityController::class, 'destroyCondition'])->name($namePrefix.'admin.report_columns.conditions.destroy');

        // A "mapped" column's own raw-value -> display-text overrides.
        Route::get('report-columns/{column}/mapping', [ReportColumnMappingController::class, 'edit'])->name($namePrefix.'admin.report_columns.mapping');
        Route::post('report-columns/{column}/mapping', [ReportColumnMappingController::class, 'store'])->name($namePrefix.'admin.report_columns.mapping.store');
        Route::delete('report-columns/{column}/mapping', [ReportColumnMappingController::class, 'destroy'])->name($namePrefix.'admin.report_columns.mapping.destroy');

        // Rooms: the housing inventory (wing/floor zones of designated rooms)
        // and the assignment board — first pass plus manual corrections. The
        // literal "zone"/"assignments" segments are declared before the
        // {room} routes so they are never captured as a room id.
        Route::get('rooms', [RoomController::class, 'index'])->name($namePrefix.'admin.rooms');
        Route::post('rooms', [RoomController::class, 'store'])->name($namePrefix.'admin.rooms.store');
        Route::put('rooms/zone', [RoomController::class, 'updateZone'])->name($namePrefix.'admin.rooms.zone');
        Route::delete('rooms/zone', [RoomController::class, 'destroyZone'])->name($namePrefix.'admin.rooms.zone.destroy');
        Route::get('rooms/assignments', [RoomAssignmentController::class, 'index'])->name($namePrefix.'admin.rooms.assignments');
        Route::post('rooms/assignments', [RoomAssignmentController::class, 'assign'])->name($namePrefix.'admin.rooms.assignments.assign');
        Route::post('rooms/assignments/first-pass', [RoomAssignmentController::class, 'firstPass'])->name($namePrefix.'admin.rooms.assignments.first_pass');
        Route::delete('rooms/assignments/{assignment}', [RoomAssignmentController::class, 'unassign'])->name($namePrefix.'admin.rooms.assignments.unassign');
        Route::delete('rooms/assignments', [RoomAssignmentController::class, 'unassignAll'])->name($namePrefix.'admin.rooms.assignments.unassign_all');
        Route::put('rooms/{room}', [RoomController::class, 'update'])->name($namePrefix.'admin.rooms.update');
        Route::delete('rooms/{room}', [RoomController::class, 'destroy'])->name($namePrefix.'admin.rooms.destroy');

        // Payments: every registrant (and their guests) with their computed
        // cost, a manually-recorded paid/amount/notes state, and links to
        // review or correct their answers. {guest} is a real Eloquent model
        // (implicitly bound); {user} is the host's configurable user model,
        // so it stays a plain numeric id — see PaymentsController::registrant().
        Route::get('payments', [PaymentsController::class, 'index'])->name($namePrefix.'admin.payments');
        Route::get('payments/{user}', [PaymentsController::class, 'show'])->whereNumber('user')->name($namePrefix.'admin.payments.show');
        Route::get('payments/{user}/answers', [PaymentsController::class, 'editAnswers'])->whereNumber('user')->name($namePrefix.'admin.payments.answers.edit');
        Route::put('payments/{user}/answers', [PaymentsController::class, 'updateAnswers'])->whereNumber('user')->name($namePrefix.'admin.payments.answers.update');
        Route::post('payments/{user}/answers/preview', [PaymentsController::class, 'costPreview'])->whereNumber('user')->name($namePrefix.'admin.payments.answers.preview');
        Route::get('payments/{user}/guests/{guest}/answers', [PaymentsController::class, 'editGuestAnswers'])->whereNumber('user')->name($namePrefix.'admin.payments.guests.answers.edit');
        Route::put('payments/{user}/guests/{guest}/answers', [PaymentsController::class, 'updateGuestAnswers'])->whereNumber('user')->name($namePrefix.'admin.payments.guests.answers.update');
        Route::post('payments/{user}/guests/{guest}/answers/preview', [PaymentsController::class, 'guestCostPreview'])->whereNumber('user')->name($namePrefix.'admin.payments.guests.answers.preview');
        Route::put('payments/{user}/payment', [PaymentsController::class, 'updatePayment'])->whereNumber('user')->name($namePrefix.'admin.payments.payment.update');

        // Translations: per-locale texts for a step, section, or question
        // (a question's options are edited on the question's page).
        Route::get('translations/{type}/{id}', [TranslationController::class, 'edit'])->name($namePrefix.'admin.translations');
        Route::post('translations/{type}/{id}', [TranslationController::class, 'save'])->name($namePrefix.'admin.translations.save');
        Route::delete('translations/{type}/{id}/{locale}', [TranslationController::class, 'destroyLocale'])->name($namePrefix.'admin.translations.locale.destroy');
    });
