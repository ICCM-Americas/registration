<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\ReportField;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Support\AnswerBag;
use Illuminate\Support\Collection;

/**
 * Builds the export bundle's "answers" sheet: one row per registrant, then
 * their guests, with an identity column set followed by one column per
 * Participant/Group/Guest-scope question — a full-fidelity dump (every
 * question, regardless of enabled/hidden state), not a curated report. Reads
 * from a snapshot in the shape {@see RegistrationArchiver::snapshot()}
 * produces (or a superset, e.g. a decoded ConferenceArchive), so a live
 * export and an archived export produce identical rows for the same people.
 */
class RegistrationAnswerFlattener
{
    /** @var ?Collection<int, Question> */
    private ?Collection $cachedQuestions = null;

    public function __construct(private RegistrationSnapshotIdentities $identities) {}

    /** Identity columns, then one per configured question, in section/question order. */
    public function headers(): array
    {
        return [
            ReportField::EntryType->label(),
            ReportField::BadgeName->label(),
            ReportField::Email->label(),
            ReportField::Organization->label(),
            ...$this->questions()->map(fn (Question $q): string => $q->label)->all(),
        ];
    }

    /**
     * @param  array{answers?: array, groups?: array, guests?: array, registrants?: array}  $snapshot
     * @return Collection<int, array<int, ?string>>
     */
    public function rows(array $snapshot): Collection
    {
        $userModel = config('registration.user_model');
        $bags = $this->identities->bagsByOwner($snapshot['answers'] ?? []);
        $identities = $this->identities->resolve($snapshot);
        $questions = $this->questions();
        $guestsByRegistrant = collect($snapshot['guests'] ?? [])->groupBy('user_id');

        $registrants = collect($snapshot['registrants'] ?? [])
            ->sortBy(fn (array $r) => [
                mb_strtolower((string) ($identities->get($this->identities->ownerKey($userModel, $r['id']))['name'] ?? '')),
                $r['id'],
            ])
            ->values();

        $rows = collect();

        foreach ($registrants as $registrant) {
            $ownerKey = $this->identities->ownerKey($userModel, $registrant['id']);
            $participant = $bags->get($ownerKey) ?? AnswerBag::fromAnswers(collect());
            $groupId = $registrant['group_id'];
            $group = $groupId !== null ? $bags->get($this->identities->ownerKey(Group::class, $groupId)) : null;

            $rows->push($this->row($questions, $identities->get($ownerKey), $participant, $group, null));

            foreach ($guestsByRegistrant->get($registrant['id'], collect()) as $guest) {
                $guestKey = $this->identities->ownerKey(Guest::class, $guest['id']);
                $guestBag = $bags->get($guestKey) ?? AnswerBag::fromAnswers(collect());

                $rows->push($this->row($questions, $identities->get($guestKey), $participant, $group, $guestBag));
            }
        }

        return $rows->values();
    }

    /** Every Participant/Group/Guest-scope question, ordered by [section.position, question.position]; memoized since it's independent of any snapshot. */
    private function questions(): Collection
    {
        return $this->cachedQuestions ??= Question::with('section')->get()
            ->filter(fn (Question $q): bool => in_array($q->section?->scope, [
                QuestionScope::Participant, QuestionScope::Group, QuestionScope::Guest,
            ], true))
            ->sortBy(fn (Question $q): array => [$q->section->position, $q->position])
            ->values();
    }

    /** One row: identity columns, then each configured question's cell. */
    private function row(Collection $questions, ?array $identity, AnswerBag $participant, ?AnswerBag $group, ?AnswerBag $guest): array
    {
        return [
            $identity['entryType'] ?? null,
            $identity['name'] ?? null,
            $identity['email'] ?? null,
            $identity['organization'] ?? null,
            ...$questions->map(fn (Question $q): ?string => $this->cell($q, $participant, $group, $guest))->all(),
        ];
    }

    /**
     * One question's cell: Participant/Group scope always resolve against
     * the row's own participant/group bag, on both a registrant's row and
     * their guests' rows; Guest scope resolves against the row's own guest
     * bag and stays blank on the registrant's own row (no single guest to
     * point at). Mirrors ReportRunner::bagFor()'s existing convention.
     */
    private function cell(Question $question, AnswerBag $participant, ?AnswerBag $group, ?AnswerBag $guest): ?string
    {
        return match ($question->section?->scope) {
            QuestionScope::Participant => $participant->display($question->key),
            QuestionScope::Group => $group?->display($question->key),
            QuestionScope::Guest => $guest?->display($question->key),
            default => null,
        };
    }
}
