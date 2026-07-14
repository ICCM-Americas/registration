<?php

namespace ConferenceTools\Registration\Support;

use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\DiscountCode;
use ConferenceTools\Registration\Services\CostSummaryBuilder;

/**
 * A registrant's invoice-style cost summary: the line items (base charges,
 * chosen priced options, per-diem) with the subtotal, any discount and the
 * final total. Built by {@see CostSummaryBuilder}
 * from the live pricing configuration, and rendered two ways: the final wizard
 * step's summary card (partials/cost-summary) and the plain-text {cost_summary}
 * email token ({@see toText()}).
 */
final class CostSummary
{
    /** @param array<int, array{label: string, amount: float}> $lines */
    public function __construct(
        public readonly array $lines,
        public readonly float $subtotal,
        public readonly ?DiscountCode $discount,
        public readonly float $total,
    ) {}

    /** What the discount took off the subtotal (0 when no code applies). */
    public function discountAmount(): float
    {
        return round($this->subtotal - $this->total, 2);
    }

    /** Nothing is priced or charged, so there is nothing worth showing. */
    public function isEmpty(): bool
    {
        return $this->lines === [] && $this->discount === null;
    }

    /**
     * The summary as plain text, one line per item — the shape of the
     * {cost_summary} email-template token (emails are sent as text/plain).
     * Subtotal and discount lines appear only when a discount applies;
     * otherwise they would just repeat the total.
     */
    public function toText(?Currency $def): string
    {
        $money = fn (float $amount): string => $def?->format($amount) ?? number_format($amount, 2);

        $rows = [];
        foreach ($this->lines as $line) {
            $rows[] = $line['label'].': '.$money($line['amount']);
        }

        if ($this->discount !== null) {
            $rows[] = __('registration::common.cost_subtotal').': '.$money($this->subtotal);
            $rows[] = __('registration::common.cost_discount').' ('.$this->discount->code.'): -'.$money($this->discountAmount());
        }

        $rows[] = __('registration::common.cost_total').': '.$money($this->total);

        return implode("\n", $rows);
    }
}
