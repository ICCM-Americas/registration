<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Guest;
use Illuminate\Support\Str;

/**
 * CRUD over a Draft's "guests" JSON column — the in-progress registrant's
 * not-yet-committed non-attending guests, each a
 * {id, type, answers} entry. Committed to real {@see Guest}
 * rows only once the registration itself is committed (see
 * RegistrationController::commit()).
 *
 * A guest is addressed by a UUID rather than its array position, so a stale
 * browser-back link can never edit or delete the wrong guest after another
 * guest was added or removed earlier in the same session.
 */
class DraftGuests
{
    /** @return list<array{id: string, type: string, answers: array}> */
    public function all(Draft $draft): array
    {
        return $draft->guests ?? [];
    }

    /** @return ?array{id: string, type: string, answers: array} */
    public function find(Draft $draft, string $id): ?array
    {
        return collect($this->all($draft))->firstWhere('id', $id);
    }

    /** @param  array<string, mixed>  $answers */
    public function add(Draft $draft, GuestType $type, array $answers): string
    {
        $id = (string) Str::uuid();

        $draft->guests = [...$this->all($draft), ['id' => $id, 'type' => $type->value, 'answers' => $answers]];
        $draft->save();

        return $id;
    }

    /** @param  array<string, mixed>  $answers */
    public function update(Draft $draft, string $id, array $answers): void
    {
        $draft->guests = collect($this->all($draft))
            ->map(fn (array $guest) => $guest['id'] === $id ? [...$guest, 'answers' => $answers] : $guest)
            ->all();
        $draft->save();
    }

    /** Drop one drafted guest by id from the draft. */
    public function remove(Draft $draft, string $id): void
    {
        $draft->guests = collect($this->all($draft))
            ->reject(fn (array $guest) => $guest['id'] === $id)
            ->values()
            ->all();
        $draft->save();
    }
}
