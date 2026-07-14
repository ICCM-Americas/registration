<?php

namespace ConferenceTools\Registration\Support;

/**
 * Parses and applies a discount-code formula. The single source of truth for both
 * admin-side validation and price application, so a formula that validates is
 * exactly one the calculator can apply.
 *
 * A formula is exactly ONE of the following forms (mixed forms are invalid):
 *
 *   +N   add N to the subtotal
 *   -N   subtract N from the subtotal
 *   =N   the subtotal becomes N (ignoring all other calculation)
 *   *N   multiply the subtotal by N
 *   N%   multiply the subtotal by N and divide by 100
 *
 * N is a non-negative decimal. Applying a formula never returns less than zero —
 * a discount cannot make the conference owe the registrant.
 */
class DiscountFormula
{
    private const PATTERN = '/^(?<op>[+\-=*])(?<num>\d+(?:\.\d+)?)$|^(?<pct>\d+(?:\.\d+)?)%$/';

    private string $op;

    private float $number;

    private bool $valid;

    /** Parse the admin-entered formula (an operator and a number, e.g. "-10%"). */
    public function __construct(string $formula)
    {
        $formula = trim($formula);

        if (preg_match(self::PATTERN, $formula, $m) !== 1) {
            $this->valid = false;

            return;
        }

        $this->valid = true;

        if (($m['pct'] ?? '') !== '') {
            $this->op = '%';
            $this->number = (float) $m['pct'];
        } else {
            $this->op = $m['op'];
            $this->number = (float) $m['num'];
        }
    }

    /** Whether the entered formula parsed successfully. */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /** Apply the formula to a subtotal, rounded to 2dp and clamped at >= 0. */
    public function apply(float $subtotal): float
    {
        if (! $this->valid) {
            return round(max(0.0, $subtotal), 2);
        }

        $result = match ($this->op) {
            '+' => $subtotal + $this->number,
            '-' => $subtotal - $this->number,
            '=' => $this->number,
            '*' => $subtotal * $this->number,
            '%' => $subtotal * $this->number / 100,
            default => $subtotal,
        };

        return round(max(0.0, $result), 2);
    }
}
