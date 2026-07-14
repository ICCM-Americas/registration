<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Models\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The completed registrants (host users saved into a group — the same
 * completeness rule as {@see Group::registeredParticipantCount()}),
 * for the report pages. Ordered by last name, then first name (both
 * nominated questions — see {@see ReportQuestions}), then user id as a
 * unique tiebreaker.
 */
class Registrants
{
    /** Memoized: the report services read the same list several times over. */
    private ?Collection $all = null;

    public function __construct(private ReportQuestions $questions) {}

    /** @return Collection<int, Model> */
    public function all(): Collection
    {
        // Eager-loaded unconditionally: shared by every report service below
        // (badges, photos, directory, room assignment, shuttle, Prayer Pals),
        // and cheaper to load once here than to special-case per consumer.
        return $this->all ??= config('registration.user_model')::query()
            ->whereNotNull('group_id')
            ->with('guests')
            ->get()
            ->sortBy(fn ($user) => [
                mb_strtolower((string) $this->questions->lastName($user)),
                mb_strtolower((string) $this->questions->firstName($user)),
                $user->getKey(),
            ])
            ->values();
    }

    /**
     * A registrant's first and last name (nominated questions), concatenated
     * and normalized — the basis of the couple/roommate matching's "mostly".
     * This is a first pass an admin corrects by hand, so it is deliberately
     * loose: stripping all whitespace before comparing means "J Leno" and
     * "JL Eno" match each other, which is fine.
     */
    public function normalizedName(Model $user): string
    {
        return self::normalize($this->questions->firstName($user).$this->questions->lastName($user));
    }

    /** A name lowercased with whitespace removed, for matching. */
    public static function normalize(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', '', $name));
    }
}
