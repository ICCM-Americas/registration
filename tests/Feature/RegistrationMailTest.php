<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Mail\TemplatedMail;
use ConferenceTools\Registration\Models\BaseCharge;
use ConferenceTools\Registration\Models\DiscountCode;
use ConferenceTools\Registration\Models\EmailTemplate;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\GroupInvite;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Variable;
use ConferenceTools\Registration\Services\AnswerStore;
use ConferenceTools\Registration\Services\RegistrationEmails;
use ConferenceTools\Registration\Services\RegistrationMailer;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Concerns\WalksRegistrationWizard;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The emails sent when a registration commits: the registrant confirmation and
 * the administrator notification, both admin-edited templates whose variables
 * ({q:...}, {c:...}, {d:...}, built-ins) resolve against the committed answers.
 */
#[TestDox('Registration Mail')]
class RegistrationMailTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase, WalksRegistrationWizard;

    /** Seed the questionnaire these tests submit against. */
    private function seedForm(): void
    {
        $this->defaultCurrency();
        $this->seed(QuestionConfigSeeder::class);
    }

    #[TestDox('completing the wizard sends both emails with variables resolved')]
    public function test_completing_the_wizard_sends_both_emails_with_variables_resolved(): void
    {
        Mail::fake();
        app(RegistrationEmails::class)->update('admin@conf.test', 'no-reply@conf.test');
        $this->openRegistration();

        BaseCharge::factory()->create(['name' => 'Conference Fee', 'amount' => 250]);
        DiscountCode::factory()->create(['code' => 'SAVE10', 'formula' => '-10%', 'description' => 'Save ten percent']);

        EmailTemplate::create([
            'key' => EmailTemplate::REGISTRANT,
            'subject' => 'Thanks {q:name.value}',
            'body' => '{q:accommodation}: {q:accommodation.value} ({q:accommodation.cost}). '
                .'Org: {q:organization.value}. Fee: {c:Conference Fee.amount}. '
                ."Offer: {d:save10} {d:SAVE10.formula}. Questions? {admin_email}\n{cost_summary}",
        ]);
        EmailTemplate::create([
            'key' => EmailTemplate::ADMIN,
            'subject' => 'New registration: {q:name.value} {q:lastname.value}',
            'body' => 'Sent from {from_email}.',
        ]);

        $this->seedForm();
        $account = $this->makeUser(['email' => 'ada@example.com']);
        $this->completeWizard($account, $this->fixtureAnswers())
            ->assertRedirect(route('registration.info'));

        Mail::assertSent(TemplatedMail::class, 2);

        // The registrant confirmation: answer values/costs, charge and discount
        // references, built-ins, and the configured from address. The group-scope
        // organization question is not asked (group questions are hidden from
        // the flow), so its {q:...value} token resolves to an empty answer.
        Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) {
            if (! $mail->hasTo('ada@example.com')) {
                return false;
            }
            $this->assertSame('Thanks Ada', $mail->subjectLine);
            // {cost_summary} renders the committed answers as invoice lines
            // (the flat charge and the chosen priced option) with the total.
            $this->assertSame(
                'Accommodation: Hotel (100.00). Org: . Fee: 250.00. '
                    ."Offer: Save ten percent -10%. Questions? admin@conf.test\n"
                    ."Conference Fee: $ 250.00\nHotel: $ 100.00\nTotal: $ 350.00",
                $mail->bodyText,
            );
            $this->assertSame('no-reply@conf.test', $mail->envelope()->from->address);

            return true;
        });

        // The administrator notification goes to the configured address.
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('admin@conf.test')
            && $mail->subjectLine === 'New registration: Ada Lovelace'
            && $mail->bodyText === 'Sent from no-reply@conf.test.');
    }

    #[TestDox('lang defaults apply and no admin mail is sent without a configured address')]
    public function test_lang_defaults_apply_and_no_admin_mail_is_sent_without_a_configured_address(): void
    {
        Mail::fake();

        // Neither address is configured (the settings default). Without them the registration window holds the wizard
        // closed, so this state is only reachable by calling the mailer
        // directly (its skip branch stays as defense in depth).
        app(RegistrationMailer::class)->sendRegistrationEmails($this->makeUser(['email' => 'ada@example.com']));

        // Only the registrant confirmation, on the untouched lang defaults,
        // deferring to the host's default from address.
        Mail::assertSent(TemplatedMail::class, 1);
        Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) {
            $this->assertTrue($mail->hasTo('ada@example.com'));
            $this->assertSame(__('registration::mail.registrant_confirmation_subject'), $mail->subjectLine);
            $this->assertSame(__('registration::mail.registrant_confirmation_body'), $mail->bodyText);
            $this->assertNull($mail->envelope()->from);

            return true;
        });
    }

    #[TestDox('a mail failure is reported but does not fail the registration')]
    public function test_a_mail_failure_is_reported_but_does_not_fail_the_registration(): void
    {
        $this->openRegistration();
        // Both sends (registrant + admin) hit the broken transport; each
        // failure is reported separately and neither breaks the commit.
        Mail::shouldReceive('to')->twice()->andThrow(new \RuntimeException('smtp down'));
        $handler = $this->spy(ExceptionHandler::class);

        $this->seedForm();
        $account = $this->makeUser(['email' => 'ada@example.com']);
        $this->completeWizard($account, $this->fixtureAnswers())
            ->assertRedirect(route('registration.info'));

        $this->assertSame(1, Group::count());
        $handler->shouldHaveReceived('report')->twice();
    }

    #[TestDox('the mailer copes with a registrant who has no group')]
    public function test_the_mailer_copes_with_a_registrant_who_has_no_group(): void
    {
        Mail::fake();

        // Defensive path (no administrator address configured): a caller passing a user that is not linked to a group
        // still gets the confirmation (with only participant-scope answers).
        app(RegistrationMailer::class)->sendRegistrationEmails($this->makeUser(['email' => 'solo@example.com']));

        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('solo@example.com'));
    }

    #[TestDox('the mailable renders the body as unescaped plain text')]
    public function test_the_mailable_renders_the_body_as_unescaped_plain_text(): void
    {
        $rendered = (new TemplatedMail('Subject', "Dinner & drinks\n<no HTML here>"))->render();

        $this->assertStringContainsString('Dinner & drinks', $rendered);
        $this->assertStringContainsString('<no HTML here>', $rendered);
    }

    #[TestDox('sendGroupMemberInvites sends one email per invite with the leader and link tokens resolved')]
    public function test_send_group_member_invites_sends_one_email_per_invite(): void
    {
        Mail::fake();
        // {leader_name} must win over a like-named admin variable — proves
        // the extra context takes precedence, not just that it resolves.
        Variable::factory()->create(['name' => 'leader_name', 'value' => 'Not The Leader']);

        $group = Group::factory()->create(['name' => 'Analytical Engines']);
        $leader = $this->makeUser(['name' => 'Ada Lovelace', 'group_id' => $group->id, 'is_group_admin' => true]);

        $first = GroupInvite::create(['group_id' => $group->id, 'token' => 'tok1']);
        app(AnswerStore::class)->store(QuestionScope::GroupMember, $first, [
            Question::GROUP_MEMBER_NAME_KEY => 'Grace', Question::GROUP_MEMBER_EMAIL_KEY => 'grace@example.com',
        ]);
        $second = GroupInvite::create(['group_id' => $group->id, 'token' => 'tok2']);
        app(AnswerStore::class)->store(QuestionScope::GroupMember, $second, [
            Question::GROUP_MEMBER_NAME_KEY => 'Bea', Question::GROUP_MEMBER_EMAIL_KEY => 'bea@example.com',
        ]);

        app(RegistrationMailer::class)->sendGroupMemberInvites($leader, collect([$first, $second]));

        Mail::assertSent(TemplatedMail::class, 2);
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('grace@example.com')
            && str_contains($mail->bodyText, 'Ada Lovelace of Analytical Engines')
            && str_contains($mail->bodyText, route('registration.register.invite.accept', 'tok1')));
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('bea@example.com')
            && str_contains($mail->bodyText, route('registration.register.invite.accept', 'tok2')));
    }

    #[TestDox('sendGroupMemberInvites skips an invite with no captured email')]
    public function test_send_group_member_invites_skips_an_invite_with_no_captured_email(): void
    {
        Mail::fake();
        $group = Group::factory()->create();
        $leader = $this->makeUser(['group_id' => $group->id, 'is_group_admin' => true]);
        $invite = GroupInvite::create(['group_id' => $group->id, 'token' => 'tok1']);

        app(RegistrationMailer::class)->sendGroupMemberInvites($leader, collect([$invite]));

        Mail::assertNothingSent();
    }

    #[TestDox('a group members own confirmation email renders the leader/member split cost summary')]
    public function test_a_group_members_own_confirmation_email_renders_the_split_cost_summary(): void
    {
        Mail::fake();
        BaseCharge::factory()->create(['name' => 'Conference Fee', 'amount' => 250]);
        EmailTemplate::create(['key' => EmailTemplate::REGISTRANT, 'subject' => 'Confirmed', 'body' => '{cost_summary}']);
        $this->seedForm();

        $group = Group::factory()->create();
        $member = $this->makeUser(['email' => 'member@example.com', 'group_id' => $group->id, 'is_group_admin' => false]);
        app(AnswerStore::class)->store(QuestionScope::Participant, $member, ['accommodation' => 'hotel']);

        app(RegistrationMailer::class)->sendRegistrationEmails($member);

        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('member@example.com')
            && str_contains($mail->bodyText, 'Covered by Your Group Leader')
            && str_contains($mail->bodyText, 'Conference Fee')
            && str_contains($mail->bodyText, 'Your Responsibility')
            && str_contains($mail->bodyText, 'Hotel'));
    }
}
