<?php

namespace ConferenceTools\Registration\Services\Search;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Support\Search\SearchHit;
use ConferenceTools\Registration\Support\Search\SearchOptions;
use ConferenceTools\Registration\Support\Search\SearchPattern;
use ConferenceTools\Registration\Support\Search\SearchTarget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/** In-progress (draft) answers of registrants and their draft guests, as the raw values the wizard saved. */
class DraftSource extends RegistrantSource
{
    /** @var array<string, array<string, string>> question labels by key, per "guest" / "registrant" key space */
    private array $questionLabels = [];

    /** {@inheritDoc} */
    public function enabled(SearchOptions $options): bool
    {
        return $options->has(SearchOptions::DRAFTS);
    }

    /** {@inheritDoc} */
    public function search(SearchPattern $pattern, SearchOptions $options): Collection
    {
        $drafts = Draft::all();
        $users = $this->users($drafts->pluck('user_id'));
        $this->questionLabels = $this->questionLabels();

        return $drafts
            ->filter(fn (Draft $draft): bool => $users->has($draft->user_id))
            ->flatMap(fn (Draft $draft): Collection => $this->draftHits($pattern, $draft, $users->get($draft->user_id)))
            ->sortBy(fn (SearchHit $hit): string => mb_strtolower($hit->title))
            ->values();
    }

    /**
     * Question labels by key, split into the guest key space and everyone else's — guest keys may repeat a registrant key.
     *
     * @return array<string, array<string, string>>
     */
    private function questionLabels(): array
    {
        $questions = Question::with('section')->get();
        [$guest, $registrant] = $questions->partition(fn (Question $q): bool => $q->section?->scope === QuestionScope::Guest);

        return [
            'guest' => $guest->pluck('label', 'key')->all(),
            'registrant' => $registrant->pluck('label', 'key')->all(),
        ];
    }

    /** The registrant's own draft answer hits, then each draft guest's. */
    private function draftHits(SearchPattern $pattern, Draft $draft, Model $user): Collection
    {
        return $this->answerHits($pattern, $user, $draft->answers ?? [], null)
            ->concat(collect($draft->guests ?? [])->flatMap(fn (array $guest): Collection => $this->answerHits($pattern, $user, $guest['answers'] ?? [], $guest)));
    }

    /**
     * One hit per matching answer of a draft (or of one of its guests), a multi-value answer matched as one comma-joined text.
     *
     * @param  array<string, mixed>  $answers
     * @param  array{id: string, type: string}|null  $guest
     */
    private function answerHits(SearchPattern $pattern, Model $user, array $answers, ?array $guest): Collection
    {
        $labels = $this->questionLabels[$guest ? 'guest' : 'registrant'];

        return collect($answers)
            ->map(fn (mixed $value, string $key): ?SearchHit => ($snippet = $pattern->snippet(implode(', ', Arr::flatten((array) $value))))
                ? new SearchHit(
                    title: $this->labels->name($user),
                    field: $this->fieldLabel('answer'),
                    snippet: $snippet,
                    url: $this->route('admin.payments.show', $user->getKey()),
                    key: $key,
                    context: ($guest ? $this->labels->guestOfType($guest['type']).' › ' : '').($labels[$key] ?? $key),
                    badges: $this->badges($user, true),
                    target: new SearchTarget($user->getKey(), null, $guest['id'] ?? null),
                )
                : null)
            ->filter()
            ->values();
    }
}
