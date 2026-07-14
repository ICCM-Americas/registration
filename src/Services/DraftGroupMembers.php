<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\GroupInvite;
use Illuminate\Support\Str;

/**
 * CRUD over a Draft's "group_members" JSON column — the in-progress group
 * leader's not-yet-committed invitees, each a {id, answers} entry. Committed
 * to real {@see GroupInvite} rows (and
 * emailed) only once the registration itself is committed (see
 * RegistrationController::commit()).
 *
 * A member is addressed by a UUID rather than its array position, so a stale
 * browser-back link can never edit or delete the wrong entry after another
 * was added or removed earlier in the same session — mirrors DraftGuests.
 */
class DraftGroupMembers
{
    /** @return list<array{id: string, answers: array}> */
    public function all(Draft $draft): array
    {
        return $draft->group_members ?? [];
    }

    /** @return ?array{id: string, answers: array} */
    public function find(Draft $draft, string $id): ?array
    {
        return collect($this->all($draft))->firstWhere('id', $id);
    }

    /** @param  array<string, mixed>  $answers */
    public function add(Draft $draft, array $answers): string
    {
        $id = (string) Str::uuid();

        $draft->group_members = [...$this->all($draft), ['id' => $id, 'answers' => $answers]];
        $draft->save();

        return $id;
    }

    /** @param  array<string, mixed>  $answers */
    public function update(Draft $draft, string $id, array $answers): void
    {
        $draft->group_members = collect($this->all($draft))
            ->map(fn (array $member) => $member['id'] === $id ? [...$member, 'answers' => $answers] : $member)
            ->all();
        $draft->save();
    }

    /** Drop one drafted group member by id from the draft. */
    public function remove(Draft $draft, string $id): void
    {
        $draft->group_members = collect($this->all($draft))
            ->reject(fn (array $member) => $member['id'] === $id)
            ->values()
            ->all();
        $draft->save();
    }
}
