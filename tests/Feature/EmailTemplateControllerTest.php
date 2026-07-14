<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Models\EmailTemplate;
use ConferenceTools\Registration\Services\RegistrationEmails;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Email Template Controller. */
#[TestDox('Email Template Controller')]
class EmailTemplateControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
    }

    #[TestDox('the emails console requires the gate')]
    public function test_the_emails_console_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.emails'))
            ->assertForbidden();
    }

    #[TestDox('the console shows all three templates with their lang defaults')]
    public function test_the_console_shows_both_templates_with_their_lang_defaults(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.emails'))
            ->assertOk()
            ->assertSee(__('registration::admin.email_admin_notification'))
            ->assertSee(__('registration::admin.email_registrant_confirmation'))
            ->assertSee(__('registration::admin.email_group_invite'))
            // No rows exist yet, so the lang-file defaults fill the forms.
            ->assertSee(__('registration::mail.registrant_confirmation_subject'))
            ->assertSee(__('registration::mail.admin_notification_subject'))
            ->assertSee(__('registration::mail.group_invite_subject'));
    }

    /** @return array<string, array{0: string}> */
    public static function templateKeys(): array
    {
        return [
            'admin notification' => [EmailTemplate::ADMIN],
            'registrant confirmation' => [EmailTemplate::REGISTRANT],
            'group invite' => [EmailTemplate::GROUP_INVITE],
        ];
    }

    #[DataProvider('templateKeys')]
    #[TestDox('saving a template persists it and replaces the default')]
    public function test_saving_a_template_persists_it_and_replaces_the_default(string $key): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.emails.update', $key), [
                'subject' => 'Custom subject for '.$key,
                'body' => 'Custom body.',
            ])
            ->assertRedirect(route('registration.admin.emails'));

        $this->assertSame(
            'Custom subject for '.$key,
            EmailTemplate::firstWhere('key', $key)->subject,
        );

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.emails'))
            ->assertOk()
            ->assertSee('Custom subject for '.$key)
            ->assertDontSee(__('registration::mail.'.$key.'_subject'));
    }

    #[TestDox('an unknown template key is a 404')]
    public function test_an_unknown_template_key_is_a_404(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.emails.update', 'weekly_newsletter'), [
                'subject' => 'S', 'body' => 'B',
            ])
            ->assertNotFound();
    }

    #[TestDox('subject and body are required')]
    public function test_subject_and_body_are_required(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.emails.update', EmailTemplate::ADMIN), [
                'subject' => '', 'body' => '',
            ])
            ->assertSessionHasErrors(['subject', 'body']);

        $this->assertSame(0, EmailTemplate::count());
    }

    #[TestDox('saving the addresses stores them as settings and confirms')]
    public function test_saving_the_addresses_stores_them_as_settings_and_confirms(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.emails.addresses'), [
                'admin_email' => 'admin@conf.test',
                'from_email' => 'no-reply@conf.test',
            ])
            ->assertRedirect(route('registration.admin.emails'))
            ->assertSessionHas('addresses_status', __('registration::admin.email_addresses_saved'));

        $emails = app(RegistrationEmails::class);
        $this->assertSame('admin@conf.test', $emails->adminEmail());
        $this->assertSame('no-reply@conf.test', $emails->fromEmail());

        // The console shows the stored addresses back in the form.
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.emails'))
            ->assertOk()
            ->assertSee('admin@conf.test')
            ->assertSee('no-reply@conf.test');
    }

    #[TestDox('saving empty addresses clears the settings')]
    public function test_saving_empty_addresses_clears_the_settings(): void
    {
        app(RegistrationEmails::class)->update('admin@conf.test', 'no-reply@conf.test');

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.emails.addresses'), [
                'admin_email' => '', 'from_email' => '',
            ])
            ->assertRedirect(route('registration.admin.emails'));

        $this->assertNull(app(RegistrationEmails::class)->adminEmail());
        $this->assertNull(app(RegistrationEmails::class)->fromEmail());
    }

    #[TestDox('the addresses must be valid email addresses')]
    public function test_the_addresses_must_be_valid_email_addresses(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.emails.addresses'), [
                'admin_email' => 'not-an-address', 'from_email' => 'also wrong',
            ])
            ->assertSessionHasErrors(['admin_email', 'from_email']);

        $this->assertNull(app(RegistrationEmails::class)->adminEmail());
    }

    #[TestDox('the effective from address falls back to the host mail from')]
    public function test_the_effective_from_address_falls_back_to_the_host_mail_from(): void
    {
        config(['mail.from.address' => 'host@conf.test']);
        $emails = app(RegistrationEmails::class);

        // Nothing stored: the host address stands in for the sender only.
        $this->assertNull($emails->fromEmail());
        $this->assertSame('host@conf.test', $emails->effectiveFromEmail());

        $emails->update(null, 'no-reply@conf.test');
        $this->assertSame('no-reply@conf.test', $emails->effectiveFromEmail());
    }
}
