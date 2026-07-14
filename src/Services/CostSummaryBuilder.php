<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Concerns\InteractsWithRegistration;
use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\PerDiemScope;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\BaseCharge;
use ConferenceTools\Registration\Models\DiscountCode;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Support\CostSummary;
use ConferenceTools\Registration\Support\GroupMemberCostSummary;

/**
 * Builds a registrant's {@see CostSummary} from an answers map (question key =>
 * value(s)) — the wizard draft's accumulated answers, or the committed values
 * when rendering the registration emails — plus their drafted guests (each a
 * {id, type, answers} entry, the same shape {@see DraftGuests::all()} returns).
 *
 * Everything is derived live from the current configuration: the enabled base
 * charges, the priced options behind the given answers, the per-diem settings
 * and any entered discount code — so pricing changes (new charges, re-priced
 * options, rate changes) are reflected without touching this code. The total
 * is composed exactly as {@see InteractsWithRegistration::cost()}
 * composes it, so the summary always matches what the registrant is charged.
 *
 * {@see fromAnswersForMember()} builds the same data for an invited group
 * member instead, split into what their group leader covers (the flat base
 * charges) versus what they owe themselves (everything else).
 */
class CostSummaryBuilder
{
    public function __construct(
        private QuestionRepository $questions,
        private VisibilityEvaluator $visibility,
        private PerDiem $perDiem,
    ) {}

    /**
     * @param  array<string, mixed>  $answers  question key => answer value(s)
     * @param  list<array{id: string, type: string, answers: array}>  $guests
     */
    public function fromAnswers(array $answers, array $guests = []): CostSummary
    {
        $lines = $this->chargeLines();
        [$optionLines, $contributions, $discountEntry] = $this->answerLines($answers);
        [$guestOptionLines, $adultGuestCount, $minorGuestCount] = $this->guestLines($guests);

        $lines = array_merge(
            $lines,
            $optionLines,
            $guestOptionLines,
            $this->perDiemLines($contributions, $adultGuestCount, $minorGuestCount),
        );

        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
        $discount = DiscountCode::lookup($discountEntry);
        $total = $discount?->apply($subtotal) ?? round(max(0.0, $subtotal), 2);

        return new CostSummary($lines, $subtotal, $discount, $total);
    }

    /**
     * Builds a group member's split summary instead of one flat total: the
     * base-charge lines (the group leader's responsibility for this member —
     * see the class doc-comment) versus everything else this method already
     * computes for a solo registrant (their own priced options, guest costs,
     * per-diem — the member's own responsibility). Reuses the same private
     * line-builders as {@see fromAnswers()}, just routed into two buckets
     * instead of merged into one. A discount code the member enters
     * themselves reduces only their own subtotal, never the leader's
     * base-charge total.
     *
     * @param  array<string, mixed>  $answers  question key => answer value(s)
     * @param  list<array{id: string, type: string, answers: array}>  $guests
     */
    public function fromAnswersForMember(array $answers, array $guests = []): GroupMemberCostSummary
    {
        $leaderLines = $this->chargeLines();
        [$optionLines, $contributions, $discountEntry] = $this->answerLines($answers);
        [$guestOptionLines, $adultGuestCount, $minorGuestCount] = $this->guestLines($guests);

        $memberLines = array_merge(
            $optionLines,
            $guestOptionLines,
            $this->perDiemLines($contributions, $adultGuestCount, $minorGuestCount),
        );

        $leaderTotal = round(array_sum(array_column($leaderLines, 'amount')), 2);
        $memberSubtotal = round(array_sum(array_column($memberLines, 'amount')), 2);
        $discount = DiscountCode::lookup($discountEntry);
        $memberTotal = $discount?->apply($memberSubtotal) ?? round(max(0.0, $memberSubtotal), 2);

        return new GroupMemberCostSummary($leaderLines, $leaderTotal, $memberLines, $memberSubtotal, $discount, $memberTotal);
    }

    /**
     * One line per enabled base charge, in their configured order.
     *
     * @return array<int, array{label: string, amount: float}>
     */
    private function chargeLines(): array
    {
        return BaseCharge::lines();
    }

    /**
     * Walk the Participant and Group scope questions (form order) against the
     * answers: a chosen priced option becomes a line, a chosen per-diem option
     * contributes days, and a discount-code answer is picked up for the total.
     * Guest-scope questions are deliberately excluded here — there is zero to
     * many guests, each with their own answer map, so they're priced
     * separately by {@see guestLines()}.
     *
     * @param  array<string, mixed>  $answers
     * @return array{0: array<int, array{label: string, amount: float}>, 1: array<int, array{label: string, days: int, scope: PerDiemScope}>, 2: ?string}
     */
    private function answerLines(array $answers): array
    {
        $lines = [];
        $contributions = [];
        $discountEntry = null;

        foreach ([QuestionScope::Participant, QuestionScope::Group] as $scope) {
            [$scopeLines, $scopeContributions, $scopeDiscountEntry] = $this->linesForScope($scope, $answers);
            $lines = [...$lines, ...$scopeLines];
            $contributions = [...$contributions, ...$scopeContributions];
            $discountEntry ??= $scopeDiscountEntry;
        }

        return [$lines, $contributions, $discountEntry];
    }

    /**
     * Each drafted guest's own priced-option lines, plus their headcount by
     * type (for per-diem pricing) — never a separately-answered count, so it
     * can never drift from the guests actually captured.
     *
     * @param  list<array{id: string, type: string, answers: array}>  $guests
     * @return array{0: array<int, array{label: string, amount: float}>, 1: int, 2: int}
     */
    private function guestLines(array $guests): array
    {
        $lines = [];
        $adultCount = 0;
        $minorCount = 0;

        foreach ($guests as $guest) {
            if (GuestType::tryFrom($guest['type'] ?? '') === GuestType::Minor) {
                $minorCount++;
            } else {
                $adultCount++;
            }

            [$guestLines] = $this->linesForScope(QuestionScope::Guest, $guest['answers'] ?? []);
            $lines = [...$lines, ...$guestLines];
        }

        return [$lines, $adultCount, $minorCount];
    }

    /**
     * Walk one scope's configured questions against one flat answers map: a
     * chosen priced option becomes a line, a chosen per-diem option
     * contributes days, and a discount-code answer is picked up. Shared by
     * {@see answerLines()} (once, over the participant/group map) and
     * {@see guestLines()} (once per guest, over that guest's own map).
     *
     * @param  array<string, mixed>  $answers
     * @return array{0: array<int, array{label: string, amount: float}>, 1: array<int, array{label: string, days: int, scope: PerDiemScope}>, 2: ?string}
     */
    private function linesForScope(QuestionScope $scope, array $answers): array
    {
        $lines = [];
        $contributions = [];
        $discountEntry = null;

        foreach ($this->questions->questionsForScope($scope) as $question) {
            $value = $answers[$question->key] ?? null;
            if (! $this->isAnswered($value) || ! $this->visibility->isVisible($question, $answers)) {
                continue;
            }

            if ($question->type->isDiscountCode()) {
                $discountEntry ??= is_array($value) ? null : (string) $value;

                continue;
            }

            if (! $question->usesOptions()) {
                continue;
            }

            foreach (is_array($value) ? array_values($value) : [$value] as $selected) {
                $this->collectOptionLines($question, (string) $selected, $answers, $lines, $contributions);
            }
        }

        return [$lines, $contributions, $discountEntry];
    }

    /** Whether a submitted answer counts as answered (non-empty). */
    private function isAnswered(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * Add one selected option's price line and per-diem day contribution to
     * the running lists, when the option resolves and carries either.
     *
     * @param  array<string, mixed>  $answers
     * @param  array<int, array{label: string, amount: float}>  $lines
     * @param  array<int, array{label: string, days: int, scope: PerDiemScope}>  $contributions
     */
    private function collectOptionLines(Question $question, string $selected, array $answers, array &$lines, array &$contributions): void
    {
        $option = $this->visibility->optionFor($question, $selected, $answers);
        if ($option === null) {
            return;
        }

        if ($option->cost !== null) {
            $lines[] = ['label' => (string) $option->translate('label'), 'amount' => (float) $option->cost];
        }
        if ($option->contributesPerDiem()) {
            $contributions[] = [
                'label' => (string) $option->translate('label'),
                'days' => (int) $option->per_diem_days,
                'scope' => $option->per_diem_scope,
            ];
        }
    }

    /**
     * The per-diem charges as lines: each day contribution priced at the
     * attendee rate, plus one line each for accompanying adult and minor
     * guests when they are billed. Line amounts are exact (integer days × a
     * rate in cents), so they sum to precisely the breakdown's total.
     *
     * @param  array<int, array{label: string, days: int, scope: PerDiemScope}>  $contributions
     * @return array<int, array{label: string, amount: float}>
     */
    private function perDiemLines(array $contributions, int $adultGuestCount, int $minorGuestCount): array
    {
        $breakdown = $this->perDiem->breakdownOf($contributions, $adultGuestCount, $minorGuestCount);

        if ($breakdown->isEmpty()) {
            return [];
        }

        $lines = [];
        foreach ($breakdown->contributions as $contribution) {
            $lines[] = [
                'label' => __('registration::common.cost_per_diem_line', [
                    'label' => $contribution['label'],
                    'days' => $contribution['days'],
                ]),
                'amount' => round($breakdown->attendeeRate * $contribution['days'], 2),
            ];
        }

        if ($breakdown->adultGuestAmount() > 0) {
            $lines[] = [
                'label' => __('registration::common.cost_guests_line', [
                    'count' => $breakdown->adultGuestCount,
                    'days' => $breakdown->guestDays(),
                ]),
                'amount' => $breakdown->adultGuestAmount(),
            ];
        }

        if ($breakdown->minorGuestAmount() > 0) {
            $lines[] = [
                'label' => __('registration::common.cost_guests_minor_line', [
                    'count' => $breakdown->minorGuestCount,
                    'days' => $breakdown->guestDays(),
                ]),
                'amount' => $breakdown->minorGuestAmount(),
            ];
        }

        return $lines;
    }
}
