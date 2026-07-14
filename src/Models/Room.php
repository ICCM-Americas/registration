<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Enums\RoomDesignation;
use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A room registrants can be housed in, identified by wing + floor + name (the
 * room number). The designation is a property of the whole wing/floor combo —
 * men only, women only, or married couples — so the rooms admin console writes
 * it per combo, never per room ({@see scopeInZone()}).
 */
class Room extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = ['wing', 'floor', 'name', 'designation', 'capacity'];

    protected $casts = [
        'designation' => RoomDesignation::class,
        'capacity' => 'integer',
    ];

    /** Who sleeps here. */
    public function assignments(): HasMany
    {
        return $this->hasMany(RoomAssignment::class);
    }

    /** Rooms of one wing/floor combo (the unit a designation applies to). */
    public function scopeInZone(Builder $query, string $wing, string $floor): Builder
    {
        return $query->where('wing', $wing)->where('floor', $floor);
    }

    /** Beds still free, from the loaded assignments. */
    public function vacancies(): int
    {
        return max(0, $this->capacity - $this->assignments->count());
    }

    /** "Wing / floor / name", for lists and flash messages. */
    public function fullName(): string
    {
        return "{$this->wing} / {$this->floor} / {$this->name}";
    }
}
