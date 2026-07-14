<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Services\RegistrationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A runtime package setting an admin changes from the console (as opposed to
 * deploy-time config/registration.php), e.g. the registration window dates
 * managed by {@see RegistrationStatus}.
 */
class Setting extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = ['key', 'value'];

    /** A setting's stored value, or null when unset. */
    public static function get(string $key): ?string
    {
        return self::where('key', $key)->value('value');
    }

    /** Storing null clears the setting (the row is removed, not kept empty). */
    public static function put(string $key, ?string $value): void
    {
        if ($value === null) {
            self::where('key', $key)->delete();

            return;
        }

        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
