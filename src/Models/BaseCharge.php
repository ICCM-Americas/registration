<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A flat conference charge, in the default currency, applied to every registrant
 * on top of their priced-option selections. The sum of all enabled base charges
 * is {@see total()}; with no rows there is no flat fee and prices are unchanged.
 */
class BaseCharge extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = ['name', 'amount', 'enabled', 'order'];

    protected $casts = [
        'amount' => 'decimal:2',
        'enabled' => 'boolean',
        'order' => 'integer',
    ];

    /** Total of all enabled base charges, in the default currency. */
    public static function total(): float
    {
        return (float) self::where('enabled', true)->sum('amount');
    }

    /**
     * Every enabled base charge as a line (name, amount), in configured order —
     * the per-line breakdown behind {@see total()}.
     *
     * @return array<int, array{label: string, amount: float}>
     */
    public static function lines(): array
    {
        return self::where('enabled', true)
            ->orderBy('order')
            ->get()
            ->map(fn (BaseCharge $charge) => ['label' => (string) $charge->name, 'amount' => (float) $charge->amount])
            ->all();
    }
}
