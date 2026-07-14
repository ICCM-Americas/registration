<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\ConferenceArchive;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Support\AnswerBag;
use Illuminate\Support\Collection;

/**
 * Who everyone in a registration snapshot is: every registrant and every
 * guest, resolved to a display name / email / organization / entry type —
 * the identity columns {@see RegistrationAnswerFlattener}'s answers sheet
 * needs, and the person-labeling every other export sheet (payments, room
 * assignments, Prayer Pals) needs too, computed once here and shared rather
 * than re-derived per sheet. Works equally from a freshly gathered snapshot
 * or a decoded {@see ConferenceArchive}
 * one, since Question/Section definitions are never archived or purged.
 */
class RegistrationSnapshotIdentities
{
    public function __construct(private ReportQuestions $questions, private GuestQuestions $guestQuestions) {}

    /**
     * @param  array{answers?: array, groups?: array, guests?: array, registrants?: array}  $snapshot
     * @return Collection<string, array{name: ?string, email: ?string, organization: ?string, entryType: string}> keyed by ownerKey()
     */
    public function resolve(array $snapshot): Collection
    {
        $bags = $this->bagsByOwner($snapshot['answers'] ?? []);
        $groupNamesById = collect($snapshot['groups'] ?? [])->keyBy('id')->map(fn (array $g) => $g['name']);
        $registrants = collect($snapshot['registrants'] ?? []);
        $userModel = config('registration.user_model');
        $emailsById = $userModel::query()->whereIn('id', $registrants->pluck('id'))->pluck('email', 'id');

        $registrantIdentities = $registrants->mapWithKeys(function (array $registrant) use ($bags, $groupNamesById, $emailsById, $userModel): array {
            $key = $this->ownerKey($userModel, $registrant['id']);
            $participant = $bags->get($key) ?? AnswerBag::fromAnswers(collect());
            $groupId = $registrant['group_id'];
            $group = $groupId !== null ? $bags->get($this->ownerKey(Group::class, $groupId)) : null;
            $groupName = $groupId !== null ? $groupNamesById->get($groupId) : null;

            return [$key => $this->registrantIdentity($participant, $group, $groupName, $emailsById->get($registrant['id']))];
        });

        $guestIdentities = collect($snapshot['guests'] ?? [])->mapWithKeys(function (array $guest) use ($bags, $registrantIdentities, $userModel): array {
            $key = $this->ownerKey(Guest::class, $guest['id']);
            $bag = $bags->get($key) ?? AnswerBag::fromAnswers(collect());
            $registrant = $registrantIdentities->get($this->ownerKey($userModel, $guest['user_id']));

            return [$key => $this->guestIdentity($guest, $bag, $registrant)];
        });

        return $registrantIdentities->merge($guestIdentities);
    }

    /**
     * Raw answer rows hydrated into non-persisted Answer models (their
     * `question` relation attached) and grouped by owner — also reused by
     * {@see RegistrationAnswerFlattener} to resolve each question's cell.
     *
     * @param  array<int, array<string, mixed>>  $answerRows
     * @return Collection<string, AnswerBag>
     */
    public function bagsByOwner(array $answerRows): Collection
    {
        $questionIds = collect($answerRows)->pluck('question_id')->unique()->filter()->values();
        $questionsById = Question::with('section')->whereIn('id', $questionIds)->get()->keyBy('id');

        return collect($answerRows)
            ->groupBy(fn (array $row): string => $this->ownerKey($row['owner_type'], $row['owner_id']))
            ->map(function (Collection $rows) use ($questionsById): AnswerBag {
                $answers = $rows->map(function (array $row) use ($questionsById): Answer {
                    $answer = (new Answer)->forceFill($row);
                    $answer->setRelation('question', $questionsById->get($row['question_id']));

                    return $answer;
                });

                return AnswerBag::fromAnswers($answers);
            });
    }

    /** The key answers/groups/guests/registrants are all grouped/looked up under, live or archived alike — a plain class+id pair, since this package configures no morph map. */
    public function ownerKey(string $class, int $id): string
    {
        return $class.':'.$id;
    }

    /** A registrant's identity: badge name, account email, resolved organization, and the fixed "Attendee" entry type. */
    private function registrantIdentity(AnswerBag $participant, ?AnswerBag $group, ?string $groupName, ?string $email): array
    {
        $nameKey = $this->questions->questionKey(ReportQuestions::BADGE_NAME_KEY);

        return [
            'name' => $nameKey !== null ? $participant->display($nameKey) : null,
            'email' => $email,
            'organization' => $this->organization($participant, $group, $groupName),
            'entryType' => __('registration::admin.report_entry_attendee'),
        ];
    }

    /** A guest's identity: their own badge name, no email, their registrant's organization, and an entry type from their guest type. */
    private function guestIdentity(array $guest, AnswerBag $bag, ?array $registrant): array
    {
        return [
            'name' => $this->guestName($bag),
            'email' => null,
            'organization' => $registrant['organization'] ?? null,
            'entryType' => GuestType::from($guest['type']) === GuestType::Adult
                ? __('registration::admin.report_entry_adult_guest')
                : __('registration::admin.report_entry_minor_guest'),
        ];
    }

    /** The nominated badge-name answer, falling back to the plain display-name answer while blank — mirrors GuestQuestions::badgeName()'s existing fallback, against a bag instead of a Guest model. */
    private function guestName(AnswerBag $bag): ?string
    {
        $badgeKey = $this->guestQuestions->questionKey(GuestQuestions::BADGE_NAME_KEY);
        $value = $badgeKey !== null ? $bag->display($badgeKey) : null;
        if ($value !== null && $value !== '') {
            return $value;
        }

        $nameKey = $this->guestQuestions->questionKey(GuestQuestions::NAME_KEY);

        return $nameKey !== null ? $bag->display($nameKey) : null;
    }

    /** Own answer → group's answer → group name — mirrors ReportQuestions::organization()'s 3-tier fallback, against bags/a name string instead of Models. */
    private function organization(AnswerBag $participant, ?AnswerBag $group, ?string $groupName): ?string
    {
        $key = $this->questions->questionKey(ReportQuestions::ORGANIZATION_KEY);
        if ($key !== null && ($own = $participant->display($key))) {
            return $own;
        }

        return ($group !== null && $key !== null ? $group->display($key) : null) ?: $groupName;
    }
}
