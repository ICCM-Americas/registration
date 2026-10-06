<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\Gender;
use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\PrayerPalsAssignment;
use ConferenceTools\Registration\Models\PrayerPalsGroup;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Services\CsvExport;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\Registrants;
use ConferenceTools\Registration\Services\ReportQuestions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The "Prayer Pals" console: every registrant, plus any adult non-attending
 * guest who has opted in (see {@see GuestQuestions::prayerPalsOptedIn()} —
 * minor guests are never included, unconditionally), split into male/female,
 * that an admin manually clusters into small (typically 3-4 person)
 * single-sex groups by dragging them into group cards. Deliberately does
 * none of the grouping itself — see the class docblock on PrayerPalsGroup
 * for why the label an admin sees is never something they pick directly.
 */
class PrayerPalsController extends Controller
{
    /** The Prayer Pals grouping console: unassigned opt-ins and the current groups. */
    public function index(Registrants $registrants, ReportQuestions $questions, GuestQuestions $guestQuestions)
    {
        $data = $this->data($registrants, $questions, $guestQuestions);

        return view('registration::admin.logistics.prayer-pals', $data + [
            'pdfPayload' => $this->pdfPayload($data),
            'pdfPaper' => $this->pdfPaperSize(),
        ]);
    }

    /** The groups as a CSV download: every member by sex and group, then the unassigned. */
    public function csv(Registrants $registrants, ReportQuestions $questions, GuestQuestions $guestQuestions, CsvExport $exporter): StreamedResponse
    {
        $data = $this->data($registrants, $questions, $guestQuestions);
        $rows = collect();

        foreach ($data['genders'] as $gender) {
            foreach ($data['groups']->get($gender->value, collect()) as $group) {
                foreach ($this->memberKeys($group) as $key) {
                    $rows->push([$gender->label(), $group->label(), ...$this->personCells($data, $key)]);
                }
            }
        }

        foreach ($this->unassignedBySex($data) as [$gender, $people]) {
            foreach ($people as $person) {
                $rows->push([$gender?->label() ?? '', '', ...$this->personCells($data, $this->key($person))]);
            }
        }

        return $this->downloadCsv($exporter, 'prayer-pals',
            [
                __('registration::admin.export_sex'),
                __('registration::admin.export_prayer_pals_group'),
                __('registration::admin.export_name'),
                __('registration::admin.report_builtin_organization'),
            ],
            $rows,
        );
    }

    /** The pool split into each sex's groups and unassigned people, plus anyone with no recorded sex. */
    private function data(Registrants $registrants, ReportQuestions $questions, GuestQuestions $guestQuestions): array
    {
        $pool = $this->pool($registrants, $guestQuestions);
        $genderById = $pool->mapWithKeys(fn (Model $o) => [$this->key($o) => $this->genderOf($o, $questions, $guestQuestions)]);

        $groups = PrayerPalsGroup::with('assignments')->orderBy('position')->get()->groupBy(fn (PrayerPalsGroup $g) => $g->sex->value);
        $assignedKeys = PrayerPalsAssignment::all()->map(fn (PrayerPalsAssignment $a) => $a->assignable_type.':'.$a->assignable_id);

        $unassigned = collect(Gender::cases())->mapWithKeys(fn (Gender $gender) => [
            $gender->value => $pool
                ->filter(fn (Model $o) => $genderById[$this->key($o)] === $gender && ! $assignedKeys->contains($this->key($o)))
                ->values(),
        ]);

        return [
            'genders' => Gender::cases(),
            'groups' => $groups,
            'unassigned' => $unassigned,
            'unknownGender' => $pool->reject(fn (Model $o) => $genderById[$this->key($o)] !== null)->values(),
            'assignedKeys' => $assignedKeys,
            'questions' => $questions,
            'guestQuestions' => $guestQuestions,
            'byKey' => $pool->keyBy(fn (Model $o) => $this->key($o)),
            'labelStyle' => PrayerPalsGroup::labelStyle(),
        ];
    }

    /**
     * The unassigned as [sex, people] pairs: each sex in turn, then anyone
     * with no recorded sex (null) who isn't somehow in a group already.
     *
     * @return list<array{0: ?Gender, 1: Collection}>
     */
    private function unassignedBySex(array $data): array
    {
        $pairs = array_map(fn (Gender $gender): array => [$gender, $data['unassigned'][$gender->value]], $data['genders']);
        $pairs[] = [null, $data['unknownGender']->reject(fn (Model $o): bool => $data['assignedKeys']->contains($this->key($o)))->values()];

        return $pairs;
    }

    /** @return Collection<int, string> a group's member keys, in assignment order */
    private function memberKeys(PrayerPalsGroup $group): Collection
    {
        return $group->assignments->map(fn (PrayerPalsAssignment $a): string => $a->assignable_type.':'.$a->assignable_id);
    }

    /** A person's name and organization cells; "#id" alone for a member who is no longer in the pool. */
    private function personCells(array $data, string $key): array
    {
        $person = $data['byKey']->get($key);

        return $person
            ? [$data['guestQuestions']->occupantFullName($person, $data['questions']), $data['questions']->organization($person) ?? '']
            : ['#'.Str::afterLast($key, ':'), ''];
    }

    /**
     * Everything the view's client-side PDF generator needs: a section per
     * sex with a bar per group over its members, then the unassigned by sex
     * — mirroring the on-screen cards.
     */
    private function pdfPayload(array $data): array
    {
        $name = function (string $key) use ($data): string {
            [$name, $organization] = $this->personCells($data, $key);

            return $organization !== '' ? $name.' ('.$organization.')' : $name;
        };
        $sexLabels = [
            Gender::Male->value => __('registration::admin.prayer_pals_male'),
            Gender::Female->value => __('registration::admin.prayer_pals_female'),
        ];

        $sections = [];
        foreach ($data['genders'] as $gender) {
            $sections[] = [
                'heading' => $sexLabels[$gender->value],
                'bars' => $data['groups']->get($gender->value, collect())->map(fn (PrayerPalsGroup $group): array => [
                    'label' => __('registration::admin.prayer_pals_group_label', ['label' => $group->label()])
                        .' — '.trans_choice('registration::admin.prayer_pals_members_count', $group->assignments->count(), ['count' => $group->assignments->count()]),
                    'items' => [['headline' => null, 'text' => $this->memberKeys($group)->map($name)->implode(', ')]],
                ])->values()->all(),
            ];
        }

        $unassigned = collect($this->unassignedBySex($data))
            ->reject(fn (array $pair): bool => $pair[1]->isEmpty())
            ->map(fn (array $pair): array => [
                'label' => $pair[0] ? $sexLabels[$pair[0]->value] : __('registration::admin.prayer_pals_unknown_sex'),
                'items' => [['headline' => null, 'text' => $pair[1]->map(fn (Model $o) => $name($this->key($o)))->implode(', ')]],
            ])->values()->all();
        if ($unassigned !== []) {
            $sections[] = ['heading' => __('registration::admin.prayer_pals_unassigned'), 'bars' => $unassigned];
        }

        return [
            'filename' => 'prayer-pals',
            'title' => __('registration::admin.prayer_pals_title'),
            'sections' => $sections,
            'countLabel' => $this->countLabel(),
            'count' => $data['byKey']->count(),
        ];
    }

    /** Persist the numbered-vs-lettered display choice. */
    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'label_style' => ['required', Rule::in([PrayerPalsGroup::LABEL_STYLE_NUMBER, PrayerPalsGroup::LABEL_STYLE_LETTER])],
        ]);

        Setting::put(PrayerPalsGroup::LABEL_STYLE_SETTING, $data['label_style']);

        return $this->back(__('registration::admin.prayer_pals_settings_saved'));
    }

    /** A new, empty group for one sex — appended after the sex's highest position. */
    public function storeGroup(Request $request)
    {
        $data = $request->validate(['sex' => ['required', Rule::in(Gender::values())]]);
        $sex = Gender::from($data['sex']);

        PrayerPalsGroup::create([
            'sex' => $sex,
            'position' => 1 + (int) (PrayerPalsGroup::where('sex', $sex)->max('position') ?? 0),
        ]);

        return $this->back();
    }

    /** Deleting a group scatters its members back to unassigned and closes the label gap it leaves. */
    public function destroyGroup(PrayerPalsGroup $group)
    {
        $sex = $group->sex;
        $group->delete();

        PrayerPalsGroup::where('sex', $sex)->orderBy('position')->get()
            ->each(function (PrayerPalsGroup $remaining, int $index): void {
                if ($remaining->position !== $index + 1) {
                    $remaining->update(['position' => $index + 1]);
                }
            });

        return $this->back();
    }

    /** Place (or move) a registrant or opted-in adult guest into a group — driven by the page's drag-and-drop. */
    public function assign(Request $request, ReportQuestions $questions, GuestQuestions $guestQuestions)
    {
        $validator = Validator::make($request->all(), [
            'occupant_type' => ['required', Rule::in(['user', 'guest'])],
            'occupant_id' => ['required', 'integer'],
            'prayer_pals_group_id' => ['required', 'integer', Rule::exists(PrayerPalsGroup::make()->getTable(), 'id')],
        ]);

        $validator->after(function ($validator) use ($request, $questions, $guestQuestions) {
            $occupant = $this->resolveOccupant((string) $request->input('occupant_type'), (int) $request->input('occupant_id'));
            $group = PrayerPalsGroup::find($request->input('prayer_pals_group_id'));

            if ($occupant === null) {
                $validator->errors()->add('occupant_id', __('registration::admin.prayer_pals_sex_mismatch'));

                return;
            }

            if ($occupant instanceof Guest && ! $guestQuestions->prayerPalsOptedIn($occupant)) {
                $validator->errors()->add('occupant_id', __('registration::admin.prayer_pals_guest_not_opted_in'));

                return;
            }

            $gender = $this->genderOf($occupant, $questions, $guestQuestions);
            if ($group && $gender !== $group->sex) {
                $validator->errors()->add('occupant_id', __('registration::admin.prayer_pals_sex_mismatch'));
            }
        });

        $data = $validator->validate();
        $assignableType = $data['occupant_type'] === 'guest' ? Guest::class : config('registration.user_model');

        PrayerPalsAssignment::updateOrCreate(
            ['assignable_type' => $assignableType, 'assignable_id' => $data['occupant_id']],
            ['prayer_pals_group_id' => $data['prayer_pals_group_id']],
        );

        return response()->json(['status' => 'ok']);
    }

    /** Remove one member from their Prayer Pals group. */
    public function unassign(PrayerPalsAssignment $assignment)
    {
        $assignment->delete();

        return $this->back();
    }

    /** Registrants plus every adult guest who has opted in to Prayer Pals (never minors). */
    private function pool(Registrants $registrants, GuestQuestions $guestQuestions): Collection
    {
        $all = $registrants->all();

        return $all->concat(
            $all->flatMap(fn (Model $u) => $u->guests)->filter(fn (Guest $g) => $guestQuestions->prayerPalsOptedIn($g))
        )->values();
    }

    /** An occupant's gender answer, guest or registrant. */
    private function genderOf(Model $occupant, ReportQuestions $questions, GuestQuestions $guestQuestions): ?Gender
    {
        return $occupant instanceof Guest ? $guestQuestions->gender($occupant) : $questions->gender($occupant);
    }

    /** A stable string key for a mixed User/Guest collection — ids from different tables can collide numerically. */
    private function key(Model $occupant): string
    {
        return $occupant->getMorphClass().':'.$occupant->getKey();
    }

    /** The registrant or guest a console row refers to. */
    private function resolveOccupant(string $type, int $id): ?Model
    {
        if ($type === 'guest') {
            return Guest::find($id);
        }

        return config('registration.user_model')::whereNotNull('group_id')->find($id);
    }

    /** Redirect back to the console. */
    private function back(?string $status = null)
    {
        $redirect = redirect()->route($this->routeName('admin.logistics.prayer_pals'));

        return $status ? $redirect->with('prayer_pals_status', $status) : $redirect;
    }
}
