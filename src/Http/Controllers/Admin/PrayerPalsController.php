<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\Gender;
use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\PrayerPalsAssignment;
use ConferenceTools\Registration\Models\PrayerPalsGroup;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\Registrants;
use ConferenceTools\Registration\Services\ReportQuestions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
        $pool = $this->pool($registrants, $guestQuestions);
        $genderById = $pool->mapWithKeys(fn (Model $o) => [$this->key($o) => $this->genderOf($o, $questions, $guestQuestions)]);

        $groups = PrayerPalsGroup::with('assignments')->orderBy('position')->get()->groupBy(fn (PrayerPalsGroup $g) => $g->sex->value);
        $assignedKeys = PrayerPalsAssignment::all()->map(fn (PrayerPalsAssignment $a) => $a->assignable_type.':'.$a->assignable_id);

        $unassigned = collect(Gender::cases())->mapWithKeys(fn (Gender $gender) => [
            $gender->value => $pool
                ->filter(fn (Model $o) => $genderById[$this->key($o)] === $gender && ! $assignedKeys->contains($this->key($o)))
                ->values(),
        ]);

        return view('registration::admin.logistics.prayer-pals', [
            'genders' => Gender::cases(),
            'groups' => $groups,
            'unassigned' => $unassigned,
            'unknownGender' => $pool->reject(fn (Model $o) => $genderById[$this->key($o)] !== null)->values(),
            'questions' => $questions,
            'guestQuestions' => $guestQuestions,
            'byKey' => $pool->keyBy(fn (Model $o) => $this->key($o)),
            'labelStyle' => PrayerPalsGroup::labelStyle(),
        ]);
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
