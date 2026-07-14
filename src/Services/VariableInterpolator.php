<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Branding\Contracts\BrandingProvider;
use ConferenceTools\Registration\Models\BaseCharge;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\DiscountCode;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Variable;
use ConferenceTools\Registration\Support\CostSummary;
use ConferenceTools\Registration\Support\GroupMemberCostSummary;

/**
 * Replaces "{...}" tokens in admin-authored texts (landing-page steps, question
 * texts, email templates, closed-page messages) with live values:
 *
 *   {name}            an admin-defined {@see Variable}, plus the built-ins:
 *                     {admin_email} and {from_email} (the registration
 *                     addresses), {conference_name} and {conference_year} (the
 *                     current conference edition), {closed_conference_name} and
 *                     {closed_conference_year} (the conference whose
 *                     registration closed — see ConferenceEdition),
 *                     {conference_site_url} (the branding website URL), and
 *                     {opens_date}, {opens_time} and {opens_timezone} (the
 *                     scheduled registration open date), and {opens_countdown}
 *                     (a human-readable relative time, e.g. "3 days from now")
 *   {c:name}          a base charge's name; {c:name.amount} its amount
 *   {d:code}          a discount code's description; {d:code.formula} its formula
 *   {q:key}           a question's label; {q:key.value} the current registrant's
 *                     answer; {q:key.cost} the cost tied to that answer
 *   {cost_summary}    the registrant's invoice-style cost summary as plain text
 *                     ({@see CostSummary::toText()}); resolves only when a
 *                     registrant context is set (the token stays visible
 *                     elsewhere) and wins over a like-named admin variable —
 *                     for a group member context (see {@see setAnswers()})
 *                     this is instead the leader/member split summary
 *                     ({@see GroupMemberCostSummary::toText()})
 *   {leader_name}     the inviting group leader's name; {leader_organization}
 *                     their organization; {invite_link} the invitee's
 *                     registration link — resolve only while a group invite
 *                     email is being composed (see {@see setExtra()}), and
 *                     always win over a like-named admin variable
 *
 * Prefixes are case-insensitive, as are charge-name and code matches. A pair
 * of braces may also hold several c:/d:/q: references separated by spaces —
 * e.g. {q:name1.value q:name2.value} — each resolved and joined with a
 * space, so one mapping row or template line can combine multiple answers.
 * Unknown tokens, and blocks where any one reference fails to resolve, are
 * left untouched so a typo stays visible instead of silently disappearing.
 * Registered as a singleton; the variable map is loaded once per request and
 * flushed by the Variable model whenever a variable changes. The registrant
 * context for {q:...value/.cost} is set per request via {@see setAnswers()}
 * (the wizard's draft answers, or the committed answers when rendering the
 * registration emails).
 */
class VariableInterpolator
{
    public function __construct(
        private RegistrationEmails $emails,
        private ConferenceEdition $edition,
        private RegistrationStatus $status,
        private BrandingProvider $branding,
        private CostSummaryBuilder $costs,
        private VisibilityEvaluator $visibility,
    ) {}

    /** @var array<string, string>|null */
    private ?array $replacements = null;

    /** @var array<string, mixed>|null the current registrant's answers (question key => value(s)) */
    private ?array $answers = null;

    /** Whether the current registrant context (see {@see setAnswers()}) is a group member — changes which {cost_summary} shape resolves. */
    private bool $isGroupMember = false;

    /** @var array<string, string>|null one-off named tokens (e.g. {leader_name}) for the email currently being composed — see {@see setExtra()} */
    private ?array $extra = null;

    /** The text with every {placeholder} replaced by its resolved value. */
    public function interpolate(?string $text): ?string
    {
        if ($text === null || ! str_contains($text, '{')) {
            return $text;
        }

        return preg_replace_callback(
            '/\{([^{}]+)\}/',
            fn (array $m): string => $this->block($m[1]) ?? $m[0],
            $text,
        );
    }

    /**
     * Resolve one {...} block's content: a single plain token, or one or more
     * whitespace-separated prefixed ({@see prefixed()}) references — e.g.
     * "{q:name1.value q:name2.value}" resolves each and joins them with a
     * space, so a mapping row or template can combine several answers in one
     * pair of braces instead of needing one per token. Any piece that fails
     * to resolve leaves the whole block visible, same as a single unmatched
     * token (the typo guard).
     */
    private function block(string $content): ?string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $content)) {
            return $this->plain($content);
        }

        $resolved = [];
        foreach (preg_split('/\s+(?=[CcDdQq]:)/', $content) as $part) {
            if (! preg_match('/^([CcDdQq]):(.+)$/s', $part, $m)) {
                return null;
            }

            $value = $this->prefixed(strtolower($m[1]), $m[2]);
            if ($value === null) {
                return null;
            }

            $resolved[] = $value;
        }

        return implode(' ', $resolved);
    }

    /**
     * Resolve one plain (unprefixed) token: the one-off {@see setExtra()}
     * context first (e.g. {leader_name} while composing a group invite),
     * then the dynamic {cost_summary} built-in — it depends on the
     * per-request registrant context, so it cannot live in the memoized map
     * — then the variable/built-in map.
     */
    private function plain(string $name): ?string
    {
        if ($this->extra !== null && array_key_exists($name, $this->extra)) {
            return $this->extra[$name];
        }

        if ($name === 'cost_summary') {
            if ($this->answers === null) {
                return null;
            }

            return $this->isGroupMember
                ? $this->costs->fromAnswersForMember($this->answers)->toText(Currency::def())
                : $this->costs->fromAnswers($this->answers)->toText(Currency::def());
        }

        return ($this->replacements ??= $this->replacements())[$name] ?? null;
    }

    /**
     * One-off named tokens (currently {leader_name}, {leader_organization},
     * {invite_link}) for the single email about to be composed — set right
     * before interpolating a group invite's subject/body and cleared right
     * after (see RegistrationMailer::sendGroupMemberInvites()), since each
     * invite in a batch has its own values. Always wins over a like-named
     * admin {@see Variable}.
     *
     * @param  array<string, string>|null  $extra
     */
    public function setExtra(?array $extra): void
    {
        $this->extra = $extra;
    }

    /**
     * Set (or clear) the registrant whose {q:...value/.cost} tokens
     * resolve. $isGroupMember switches {cost_summary} to the leader/member
     * split shape (see {@see plain()}) — meaningful only once the
     * registration is committed, when a group member's is_group_admin flag
     * is settled either way.
     */
    public function setAnswers(?array $answers, bool $isGroupMember = false): void
    {
        $this->answers = $answers;
        $this->isGroupMember = $isGroupMember;
    }

    /** Forget the memoized variable map (called when a variable changes). */
    public function flush(): void
    {
        $this->replacements = null;
    }

    /**
     * The plain-token map: admin-defined variables plus the built-ins, which
     * win on a name clash (the built-ins stay authoritative). An unconfigured
     * built-in is omitted so its token stays visible.
     *
     * @return array<string, string>
     */
    private function replacements(): array
    {
        $opens = $this->status->opensAt()?->locale(app()->getLocale());

        $builtins = array_filter([
            'admin_email' => (string) $this->emails->adminEmail(),
            'from_email' => (string) $this->emails->effectiveFromEmail(),
            'conference_name' => (string) $this->edition->name(),
            'conference_year' => (string) $this->edition->year(),
            'closed_conference_name' => (string) $this->edition->closedName(),
            'closed_conference_year' => (string) $this->edition->closedYear(),
            'conference_site_url' => $this->branding->url(),
            'opens_date' => (string) $opens?->isoFormat('LL'),
            'opens_time' => (string) $opens?->isoFormat('LT'),
            'opens_timezone' => (string) $opens?->format('T'),
            'opens_countdown' => (string) $opens?->diffForHumans(),
        ], fn (string $value): bool => $value !== '');

        return array_merge(Variable::replacements(), $builtins);
    }

    /** Resolve one prefixed token; null leaves the token visible (typo guard). */
    private function prefixed(string $prefix, string $reference): ?string
    {
        return match ($prefix) {
            'c' => $this->charge(...$this->splitSuffix($reference, ['amount'])),
            'd' => $this->discount(...$this->splitSuffix($reference, ['formula'])),
            'q' => $this->question(...$this->splitSuffix($reference, ['value', 'cost'])),
        };
    }

    /**
     * Split "name.suffix" into [name, suffix] when the reference ends in one of
     * the recognized suffixes; any other dot stays part of the name.
     *
     * @param  array<int, string>  $suffixes
     * @return array{0: string, 1: ?string}
     */
    private function splitSuffix(string $reference, array $suffixes): array
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with(mb_strtolower($reference), '.'.$suffix)) {
                return [substr($reference, 0, -strlen($suffix) - 1), $suffix];
            }
        }

        return [$reference, null];
    }

    /** A {charge:...} placeholder's value, or null when no base charge matches. */
    private function charge(string $name, ?string $suffix): ?string
    {
        $charge = BaseCharge::whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])->first();

        return $charge === null ? null
            : ($suffix === 'amount' ? (string) $charge->amount : $charge->name);
    }

    /** Enabled codes only, matching what a registrant may actually enter. */
    private function discount(string $code, ?string $suffix): ?string
    {
        $discount = DiscountCode::lookup($code);

        return $discount === null ? null
            : (string) ($suffix === 'formula' ? $discount->formula : $discount->description);
    }

    /** A {question:...} placeholder's value, or null when no question matches. */
    private function question(string $key, ?string $suffix): ?string
    {
        $question = Question::with('options')->firstWhere('key', trim($key));

        return $question === null ? null : match ($suffix) {
            null => (string) $question->translate('label'),
            'value' => $this->answerDisplay($question),
            'cost' => $this->answerCost($question),
        };
    }

    /**
     * The registrant's answer as display text: option labels for choice
     * answers, resolved through the option's visibility so a value shared by
     * several conditional variants shows the label this registrant saw.
     */
    private function answerDisplay(Question $question): string
    {
        return collect($this->answerValues($question))
            ->map(fn (string $value): string => $question->usesOptions()
                ? (string) ($this->visibility->optionFor($question, $value, $this->answers ?? [])?->translate('label') ?? $value)
                : $value)
            ->implode(', ');
    }

    /** @return array<int, string> the answered value(s), [] when unanswered */
    private function answerValues(Question $question): array
    {
        $value = $this->answers[$question->key] ?? null;
        if ($value === null || $value === '') {
            return [];
        }

        return array_map(strval(...), is_array($value) ? array_values($value) : [$value]);
    }

    /**
     * The cost tied to the registrant's answer: the summed option costs ("0"
     * when nothing chosen carries one), or for a discount-code question the
     * formula of the entered code ("" when the entry matches no code).
     */
    private function answerCost(Question $question): string
    {
        if ($question->type->isDiscountCode()) {
            return (string) (DiscountCode::lookup($this->answerValues($question)[0] ?? null)?->formula ?? '');
        }

        $cost = collect($this->answerValues($question))
            ->map(fn (string $value) => $this->visibility->optionFor($question, $value, $this->answers ?? [])?->cost)
            ->filter(fn ($cost) => $cost !== null)
            ->sum();

        return $cost > 0 ? number_format((float) $cost, 2, '.', '') : '0';
    }
}
