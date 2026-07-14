<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Services\AnswerStore;
use ConferenceTools\Registration\Services\GroupRegistrationService;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\Fixtures\User;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Package Structure. */
#[TestDox('Package Structure')]
class PackageStructureTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('package migrations create the prefixed domain tables')]
    public function test_package_migrations_create_the_prefixed_domain_tables(): void
    {
        $prefix = config('registration.tables.prefix');
        $this->assertSame('registration_', $prefix);

        foreach ([
            'groups', 'currencies',
            'sections', 'questions', 'question_options', 'answers', 'drafts',
            'info_steps', 'variables', 'translations', 'email_templates', 'settings',
            'rooms', 'room_assignments', 'guests',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($prefix.$table), "Missing table: {$prefix}{$table}");
            $this->assertFalse(Schema::hasTable($table), "Unprefixed table should not exist: {$table}");
        }
    }

    #[TestDox('removed domain tables are not created')]
    public function test_removed_domain_tables_are_not_created(): void
    {
        $prefix = config('registration.tables.prefix');

        foreach (['accommodations', 'payment_methods', 'invoices', 'post_registrations', 'products'] as $table) {
            $this->assertFalse(Schema::hasTable($prefix.$table), "Removed table should not exist: {$prefix}{$table}");
        }
    }

    #[TestDox('structural registration columns are added to the host users table')]
    public function test_structural_registration_columns_are_added_to_the_host_users_table(): void
    {
        // Only structural columns are added; the registrant's answers live in the
        // EAV answer store, not in columns.
        foreach (['mail_id', 'checked_out', 'is_group_admin', 'group_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column), "Missing users column: {$column}");
        }

        foreach (['lastname', 'nickname', 'passport', 'gender', 'residence', 'accommodation_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('users', $column), "Column should be gone: {$column}");
        }
    }

    #[TestDox('public and admin routes are registered')]
    public function test_public_and_admin_routes_are_registered(): void
    {
        foreach ([
            'registration.info',
            'registration.register',
            'registration.register.guests',
            'registration.register.guests.create',
            'registration.register.guests.store',
            'registration.register.guests.edit',
            'registration.register.guests.update',
            'registration.register.guests.destroy',
            'registration.admin.dashboard',
            'registration.admin.pricing',
            'registration.admin.questions',
            'registration.admin.steps',
            'registration.admin.variables',
            'registration.admin.translations',
            'registration.admin.emails',
            'registration.admin.window.update',
            'registration.admin.window.open',
            'registration.admin.window.close',
            'registration.admin.reports',
            'registration.admin.reports.create',
            'registration.admin.reports.show',
            'registration.admin.reports.csv',
            'registration.admin.reports.visibility',
            'registration.admin.report_columns.visibility',
            'registration.admin.logistics.badges',
            'registration.admin.logistics.shuttles',
            'registration.admin.logistics',
            'registration.admin.logistics.settings',
            'registration.admin.rooms',
            'registration.admin.rooms.assignments',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Missing route: {$name}");
        }
    }

    #[TestDox('removed routes are not registered')]
    public function test_removed_routes_are_not_registered(): void
    {
        foreach ([
            'registration.checkout',
            'registration.postregistration.mail',
            'registration.admin.invoices',
            'registration.admin.groups',
            'registration.group',
            'registration.group.addUser',
            'registration.group.saveUser',
            'registration.group.finish',
            // PDF exports are generated client-side (jsPDF); the dompdf
            // server-side routes are gone.
            'registration.admin.logistics.badges.pdf',
            'registration.admin.logistics.shuttles.pdf',
            // The hard-coded reports became admin-defined (seeded) reports.
            'registration.admin.reports.settings',
            'registration.admin.reports.directory',
            'registration.admin.reports.attendees',
            'registration.admin.reports.arrivals',
            'registration.admin.reports.photos',
            'registration.admin.reports.special_needs',
            'registration.admin.reports.first_time_attendees',
        ] as $name) {
            $this->assertFalse(Route::has($name), "Removed route should not exist: {$name}");
        }
    }

    #[TestDox('group registration creates a group and stores answers in eav')]
    public function test_group_registration_creates_a_group_and_stores_answers_in_eav(): void
    {
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'rate' => 1, 'def' => true]);
        $this->seed(QuestionConfigSeeder::class);

        // The host application creates and authenticates the account first; the
        // package registers that existing account as the group admin.
        $account = User::forceCreate([
            'name' => 'Account Name', 'email' => 'ada@example.com', 'password' => 'host-set',
        ]);

        $answers = [
            'name' => 'Ada', 'lastname' => 'Lovelace', 'passport' => 'Ada Lovelace',
            'gender' => 'f', 'residence' => 'USA',
            'organization' => 'Analytical Engines',
            'accommodation' => 'hotel', 'products' => ['dinner'],
        ];

        // Mirrors the controller commit: create the group + admin (structural),
        // then store the answers in the EAV store for both scopes.
        $user = app(GroupRegistrationService::class)->registerGroup($answers, $account);
        app(AnswerStore::class)->store(QuestionScope::Participant, $user, $answers);
        app(AnswerStore::class)->store(QuestionScope::Group, $user->group, $answers);

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue((bool) $user->is_group_admin);
        $this->assertSame(1, Group::count());
        $this->assertSame('Analytical Engines', $user->group->name);
        // The registrant's answers and cost are read from the EAV store, not
        // from columns; the account's own name is left exactly as the host set it.
        $this->assertSame('Lovelace', $user->lastname);
        $this->assertSame('Account Name', $user->name);
        // accommodation "hotel" (100) + product (20) = 120.
        $this->assertEqualsWithDelta(120.0, $user->cost(), 0.001);
    }
}
