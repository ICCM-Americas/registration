<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A currency registrations can be priced in, one row flagged as the default. */
class Currency extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $primaryKey = 'code';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'symbol', 'rate', 'def', 'enabled'];

    protected $casts = [
        'def' => 'boolean',
        'enabled' => 'boolean',
        'rate' => 'decimal:8',
    ];

    /** Convert an amount, given in the base currency, into this currency. */
    public function convert($amount): float
    {
        $converted = (float) $this->rate * (float) $amount;

        return round($converted, 2);
    }

    /** Render an amount as a string prefixed with this currency's symbol. */
    public function format($amount): string
    {
        return sprintf('%s %s', $this->symbol, number_format((float) $amount, 2));
    }

    /** The base currency (the one flagged default), or null if none is set. */
    public static function def(): ?self
    {
        return static::query()->where('def', true)->first();
    }
}
