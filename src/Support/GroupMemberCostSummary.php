<?php

namespace ConferenceTools\Registration\Support;

use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\DiscountCode;
use ConferenceTools\Registration\Services\CostSummaryBuilder;

/**
 * A group member's split cost summary: the flat base-charge lines the group
 * leader is responsible for (the only cost that needs group-aware handling —
 * every question-based line, guest cost, and per-diem contribution is
 * already correctly attributed to whoever answered those questions), and
 * everything else — this member's own priced answers, their own guest
 * costs, per-diem — that they owe themselves. Built by
 * {@see CostSummaryBuilder::fromAnswersForMember()},
 * and rendered by partials/group-member-cost-summary and the
 * {cost_summary} email token for a group member (see VariableInterpolator).
 */
final class GroupMemberCostSummary
{
    /**
     * @param  array<int, array{label: string, amount: float}>  $leaderLines
     * @param  array<int, array{label: string, amount: float}>  $memberLines
     */
    public function __construct(
        public readonly array $leaderLines,
        public readonly float $leaderTotal,
        public readonly array $memberLines,
        public readonly float $memberSubtotal,
        public readonly ?DiscountCode $discount,
        public readonly float $memberTotal,
    ) {}

    /** What the discount took off the member's own subtotal (0 when no code applies). */
    public function discountAmount(): float
    {
        return round($this->memberSubtotal - $this->memberTotal, 2);
    }

    /** Nothing is priced or charged on either side, so there is nothing worth showing. */
    public function isEmpty(): bool
    {
        return $this->leaderLines === [] && $this->memberLines === [] && $this->discount === null;
    }

    /**
     * The split summary as plain text — the shape of the {cost_summary}
     * email-template token for a group member (emails are sent as
     * text/plain), one labeled block per side.
     */
    public function toText(?Currency $def): string
    {
        $money = fn (float $amount): string => $def?->format($amount) ?? number_format($amount, 2);

        $rows = [__('registration::common.group_leader_covers_heading').':'];
        foreach ($this->leaderLines as $line) {
            $rows[] = $line['label'].': '.$money($line['amount']);
        }
        $rows[] = __('registration::common.group_leader_covers_total').': '.$money($this->leaderTotal);

        $rows[] = '';
        $rows[] = __('registration::common.your_responsibility_heading').':';
        foreach ($this->memberLines as $line) {
            $rows[] = $line['label'].': '.$money($line['amount']);
        }

        if ($this->discount !== null) {
            $rows[] = __('registration::common.cost_subtotal').': '.$money($this->memberSubtotal);
            $rows[] = __('registration::common.cost_discount').' ('.$this->discount->code.'): -'.$money($this->discountAmount());
        }

        $rows[] = __('registration::common.cost_total').': '.$money($this->memberTotal);

        return implode("\n", $rows);
    }
}
