<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Mail\TemplatedMail;
use ConferenceTools\Registration\Models\EmailTemplate;
use ConferenceTools\Registration\Models\GroupInvite;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Support\AnswerBag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the registration-committed emails: the confirmation to the registrant
 * and the notification to the configured administrator address (skipped while
 * that address is unset — see RegistrationEmails), plus the invite each
 * person a group leader adds to their group gets (see
 * {@see sendGroupMemberInvites()}). Templates come from the "Emails" admin
 * console with the registrant's committed answers as the variable context, so
 * {q:...}, {c:...}, {d:...} and the built-ins all resolve. A transport failure
 * is reported, never thrown — the registration is already committed and must
 * not appear to fail because a mail could not be sent.
 */
class RegistrationMailer
{
    public function __construct(
        private VariableInterpolator $interpolator,
        private RegistrationEmails $emails,
    ) {}

    /** @param  Model  $user  the just-registered host user (group loaded/linked) */
    public function sendRegistrationEmails(Model $user): void
    {
        $answers = AnswerBag::forOwner($user)->values()
            + ($user->group ? AnswerBag::forOwner($user->group)->values() : []);

        // An invited group member sees the leader/member cost split in their
        // own {cost_summary}, if the template references it — is_group_admin
        // is already settled correctly for both branches by commit time.
        $this->interpolator->setAnswers($answers, ! $user->is_group_admin);

        try {
            $this->send(EmailTemplate::REGISTRANT, $user->email);
            $this->send(EmailTemplate::ADMIN, $this->emails->adminEmail());
        } finally {
            $this->interpolator->setAnswers(null);
        }
    }

    /**
     * Sends one invite email per group member a leader drafted during their
     * own commit (see RegistrationController::commit()) — each a still-
     * unconsumed {@see GroupInvite}. {leader_name}/{leader_organization}/
     * {invite_link} are set fresh for each one, since every invite's link is
     * different; never affects the {q:...}/{cost_summary} registrant
     * context, which this method leaves untouched.
     *
     * @param  Model  $leader  the just-registered group admin
     * @param  Collection<int, GroupInvite>  $invites
     */
    public function sendGroupMemberInvites(Model $leader, Collection $invites): void
    {
        foreach ($invites as $invite) {
            $email = $invite->registrationAnswers()->value(Question::GROUP_MEMBER_EMAIL_KEY);
            if (empty($email)) {
                continue; // defensive — the question is required, so this should not happen
            }

            $this->interpolator->setExtra([
                'leader_name' => (string) $leader->name,
                'leader_organization' => (string) $leader->group->name,
                'invite_link' => route(config('registration.route_name_prefix').'register.invite.accept', $invite->token),
            ]);

            try {
                $this->send(EmailTemplate::GROUP_INVITE, $email);
            } finally {
                $this->interpolator->setExtra(null);
            }
        }
    }

    /**
     * The admin test drive's variant: both templates — the registrant
     * confirmation AND the administrator notification — go to the tester's own
     * address, with the never-stored wizard answers as the variable context.
     *
     * @param  Model  $user  the signed-in admin walking the test run
     * @param  array<string, mixed>  $answers  the test run's accumulated answers
     */
    public function sendTestRegistrationEmails(Model $user, array $answers): void
    {
        $this->interpolator->setAnswers($answers);

        try {
            $this->send(EmailTemplate::REGISTRANT, $user->email);
            $this->send(EmailTemplate::ADMIN, $user->email);
        } finally {
            $this->interpolator->setAnswers(null);
        }
    }

    /** Send a template's mail for a registrant, with variables resolved. */
    private function send(string $key, ?string $to): void
    {
        if (empty($to)) {
            return;
        }

        $template = EmailTemplate::forKey($key);

        try {
            Mail::to($to)->send(new TemplatedMail(
                subjectLine: (string) $this->interpolator->interpolate($template->subject),
                bodyText: (string) $this->interpolator->interpolate($template->body),
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
