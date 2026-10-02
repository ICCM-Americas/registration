<?php

namespace ConferenceTools\Registration\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\ClosedMessage;
use ConferenceTools\Registration\Models\Setting;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether the conference registration is currently open to registrants.
 *
 * One mechanism decides it: the admin-set window [opens_at, closes_at) held in
 * the package settings. "Open now" / "Close now" write the same dates the
 * schedule form edits (open = opens_at:=now, close = closes_at:=now), so a
 * manual action and a scheduled one are indistinguishable afterwards.
 *
 * On top of the window sits the email guard: the registration emails are the
 * admin's record of who registered, so while the administrator / from
 * addresses are unconfigured ({@see RegistrationEmails}), registration may not
 * open — a manual open is refused, and a scheduled window that arrives without
 * them behaves as closed ({@see misconfigured()}, surfaced as a banner on
 * every admin page).
 *
 * A second guard, shaped the same way but reported separately (its own
 * banner and flash message — see {@see requiredNameQuestionsMissing()}),
 * covers the badge-name, first-name, and last-name questions: these are
 * nominated, not hard-coded (see {@see ReportQuestions}), so registration
 * must not open until an admin has chosen all three — there is no fallback
 * for any of them.
 *
 * A third, independent guard covers the Logistics console's matching-answer
 * settings ({@see ReportQuestions::hasStaleMatches()}): if one no longer
 * matches a current option of its nominated question, a manual open is
 * refused and every admin page banners it ({@see reportAnswersStale()}) —
 * unlike the email guard this is never window-gated, since it is a
 * configuration-integrity problem the admin should see and fix regardless of
 * whether the window has arrived.
 *
 * A fourth, independent condition — {@see answersLocked()} — governs whether
 * Questions and Options may be edited beyond their texts (which
 * {@see AnswerTextSync} carries into stored answers, so they stay editable,
 * as do visibility rules, so anything a text edit breaks can be fixed): once
 * registration is open, or any answer has ever been
 * recorded, changing the form structure risks silently invalidating
 * already-collected data (a re-priced option no longer matches what was
 * charged, a removed option leaves answers no form can show, etc.). This does
 * not feed into {@see isOpen()} or {@see open()} — it is purely consumed by
 * the admin question/option controllers and their views. It is a global condition (any answer at all,
 * not just an answer to the question being edited), since an option's value
 * or cost could be referenced by {@see VariableInterpolator} templates on
 * unrelated questions. The only way to unlock is to purge every answer (see
 * {@see AnswerPurge}), which remains unguarded.
 */
class RegistrationStatus
{
    public const OPENS_AT = 'opens_at';

    public const CLOSES_AT = 'closes_at';

    public function __construct(
        private RegistrationEmails $emails,
        private ReportQuestions $reportQuestions,
        private GuestQuestions $guestQuestions,
    ) {}

    /** Whether registrants can currently register. */
    public function isOpen(): bool
    {
        return $this->withinWindow() && $this->emailsConfigured() && $this->requiredNameQuestionsConfigured();
    }

    /**
     * Whether a committed registrant may currently change their own
     * registration (their own answers, guests, or — for a group admin — the
     * group's invited members): registered, registration still open, and not
     * yet marked paid. The single source of truth for "mine" and its
     * guest/group-member management pages.
     */
    public function eligibleToModify(Model $user): bool
    {
        return $user->group !== null && $this->isOpen() && ! $user->payment?->is_paid;
    }

    /** The window has arrived but the email guard is holding registration closed. */
    public function misconfigured(): bool
    {
        return $this->withinWindow() && ! $this->emailsConfigured();
    }

    /** The window has arrived but a required name-question nomination is missing. */
    public function requiredNameQuestionsMissing(): bool
    {
        return $this->withinWindow() && ! $this->requiredNameQuestionsConfigured();
    }

    /** A Logistics matching-answer setting no longer matches its question's current options. */
    public function reportAnswersStale(): bool
    {
        return $this->reportQuestions->hasStaleMatches() || $this->guestQuestions->hasStaleMatches();
    }

    /**
     * Whether Questions and Options are locked against admin edits other
     * than their texts (see the class docblock).
     */
    public function answersLocked(): bool
    {
        return $this->isOpen() || Answer::query()->exists();
    }

    /** Whether now falls inside the scheduled open/close window. */
    public function withinWindow(): bool
    {
        $opens = $this->opensAt();

        return $opens !== null
            && $opens->lessThanOrEqualTo(now())
            && ($this->closesAt() === null || $this->closesAt()->greaterThan(now()));
    }

    /**
     * Both registration email addresses are available: the administrator
     * notification recipient, and a sender (the configured from address or
     * the host's mail.from address). See {@see RegistrationEmails}.
     */
    public function emailsConfigured(): bool
    {
        return $this->emails->configured();
    }

    /**
     * Badge name, first name, and last name are all nominated (see
     * {@see ReportQuestions}) — required before registration may open, since
     * none of them has a fallback.
     */
    public function requiredNameQuestionsConfigured(): bool
    {
        return $this->reportQuestions->questionKey(ReportQuestions::BADGE_NAME_KEY) !== null
            && $this->reportQuestions->questionKey(ReportQuestions::FIRSTNAME_KEY) !== null
            && $this->reportQuestions->questionKey(ReportQuestions::LASTNAME_KEY) !== null;
    }

    /**
     * Which of the admin-configured closed-page messages applies right now
     * (only meaningful while registration is closed, {@see ClosedMessage}):
     * a scheduled open date has not arrived, it has arrived but the email
     * guard holds registration closed, the close date has passed, or no
     * window is scheduled at all.
     */
    public function closedMessageKey(): string
    {
        if ($this->opensAt()?->isFuture()) {
            return ClosedMessage::BEFORE_OPEN;
        }

        if ($this->withinWindow()) {
            return ClosedMessage::OPENING_SOON;
        }

        if ($this->closesAt()?->isPast()) {
            return ClosedMessage::AFTER_CLOSE;
        }

        return ClosedMessage::CLOSED;
    }

    /**
     * Open registration now. Refused (false) while the emails or required
     * name questions are unconfigured, or a Reports matching-answer setting
     * has gone stale.
     */
    public function open(): bool
    {
        if (! $this->emailsConfigured() || ! $this->requiredNameQuestionsConfigured() || $this->reportAnswersStale()) {
            return false;
        }

        // A leftover close date in the past would immediately re-close the
        // window being opened; clear it. A future close date is a scheduled
        // close and stays.
        $closes = $this->closesAt();
        if ($closes !== null && $closes->lessThanOrEqualTo(now())) {
            $closes = null;
        }

        $this->schedule(now(), $closes);

        return true;
    }

    /** Close registration now. */
    public function close(): void
    {
        $this->schedule($this->opensAt(), now());
    }

    /** Set the open and close datetimes. */
    public function schedule(?CarbonInterface $opensAt, ?CarbonInterface $closesAt): void
    {
        Setting::put(self::OPENS_AT, $opensAt?->toDateTimeString());
        Setting::put(self::CLOSES_AT, $closesAt?->toDateTimeString());
    }

    /** The scheduled opening time, or null while unset. */
    public function opensAt(): ?CarbonImmutable
    {
        return $this->date(self::OPENS_AT);
    }

    /** The scheduled closing time, or null while unset. */
    public function closesAt(): ?CarbonImmutable
    {
        return $this->date(self::CLOSES_AT);
    }

    /** A stored window datetime, or null while unset. */
    private function date(string $key): ?CarbonImmutable
    {
        $value = Setting::get($key);

        return $value === null ? null : CarbonImmutable::parse($value);
    }
}
