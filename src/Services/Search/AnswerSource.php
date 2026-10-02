<?php

namespace ConferenceTools\Registration\Services\Search;

use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Support\Search\SearchHit;
use ConferenceTools\Registration\Support\Search\SearchOptions;
use ConferenceTools\Registration\Support\Search\SearchPattern;
use ConferenceTools\Registration\Support\Search\SearchTarget;
use ConferenceTools\Registration\Support\Search\Snippet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Committed answers of registrants and their guests, linked to the Payments answer editors. */
class AnswerSource extends RegistrantSource
{
    /** {@inheritDoc} */
    public function enabled(SearchOptions $options): bool
    {
        return $options->has(SearchOptions::ANSWERS);
    }

    /** {@inheritDoc} */
    public function search(SearchPattern $pattern, SearchOptions $options): Collection
    {
        $userMorph = (new (config('registration.user_model')))->getMorphClass();
        $guestMorph = (new Guest)->getMorphClass();

        $matched = Answer::with('question')
            ->whereIn('owner_type', [$userMorph, $guestMorph])
            ->get()
            ->map(fn (Answer $answer): array => [$answer, $pattern->snippet($answer->value)])
            ->filter(fn (array $pair): bool => $pair[1] !== null);

        [$guestRows, $userRows] = $matched->partition(fn (array $pair): bool => $pair[0]->owner_type === $guestMorph);
        $guests = Guest::whereIn('id', $guestRows->map(fn (array $pair): int => $pair[0]->owner_id))->get()->keyBy('id');
        $users = $this->users($userRows->map(fn (array $pair): int => $pair[0]->owner_id)->concat($guests->pluck('user_id')));

        return $matched
            ->map(fn (array $pair): ?SearchHit => $this->hit($pair[0], $pair[1], $pair[0]->owner_type === $guestMorph ? $guests : null, $users))
            ->filter()
            ->sortBy([
                fn (SearchHit $a, SearchHit $b): int => strcasecmp($a->title, $b->title),
                fn (SearchHit $a, SearchHit $b): int => ($a->target->guestId ?? 0) <=> ($b->target->guestId ?? 0),
            ])
            ->values();
    }

    /**
     * The hit for one matching answer, or null when its owner no longer exists.
     *
     * @param  Collection<int, Guest>|null  $guests  the matched guests, when the answer is a guest's
     * @param  Collection<int, Model>  $users
     */
    private function hit(Answer $answer, Snippet $snippet, ?Collection $guests, Collection $users): ?SearchHit
    {
        $guest = $guests?->get($answer->owner_id);
        $user = $users->get($guest ? $guest->user_id : $answer->owner_id);
        if (! $user || ($guests && ! $guest)) {
            return null;
        }

        $key = $answer->question?->key;
        $url = $guest
            ? $this->route('admin.payments.guests.answers.edit', [$user->getKey(), $guest->id, 'highlight' => $key])
            : $this->route('admin.payments.answers.edit', [$user->getKey(), 'highlight' => $key]);

        return new SearchHit(
            title: $this->labels->name($user),
            field: $this->fieldLabel('answer'),
            snippet: $snippet,
            url: $url,
            key: $key,
            context: ($guest ? $this->labels->guest($guest).' › ' : '').$answer->question?->label,
            badges: $this->badges($user, false),
            links: $this->viewLink($user),
            target: new SearchTarget($user->getKey(), $guest?->id),
        );
    }
}
