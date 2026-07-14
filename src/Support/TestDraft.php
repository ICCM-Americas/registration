<?php

namespace ConferenceTools\Registration\Support;

use ConferenceTools\Registration\Models\Draft;

/**
 * The admin test drive's stand-in for a {@see Draft}: the same shape, so the
 * wizard walks it unchanged, but held only in the session — saving writes the
 * session and deleting forgets it. A test run therefore records nothing in the
 * drafts table, not even a partial registration to be completed later.
 */
class TestDraft extends Draft
{
    public const SESSION_KEY = 'registration.test_draft';

    /** Rehydrate the admin's test run from the session (fresh when none). */
    public static function fromSession(): static
    {
        $draft = new static;
        $draft->forceFill(session(self::SESSION_KEY, ['answers' => [], 'guests' => [], 'group_members' => []]));

        // "exists" so the wizard's discard() deletes (forgets) the run; save()
        // and delete() below replace persistence, so no query is ever run.
        $draft->exists = true;

        return $draft;
    }

    /** Persist the pretend draft into the session. */
    public function save(array $options = []): bool
    {
        session([self::SESSION_KEY => [
            'answers' => $this->answers ?? [],
            'guests' => $this->guests ?? [],
            'group_members' => $this->group_members ?? [],
            'current_question_id' => $this->current_question_id,
        ]]);

        return true;
    }

    /** Discard the pretend draft from the session. */
    public function delete(): ?bool
    {
        session()->forget(self::SESSION_KEY);

        return true;
    }
}
