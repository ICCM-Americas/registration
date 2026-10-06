<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Room;
use ConferenceTools\Registration\Models\RoomAssignment;
use ConferenceTools\Registration\Services\CsvExport;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\Registrants;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Services\RoomAssigner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The admin "Room Assignments" console: run the first pass, then correct it —
 * the questions never carry quite enough information, so every placement can
 * be moved or removed by hand. Occupants are registrants and their
 * non-attending guests (both types — see RoomAssigner). Doubles as the
 * room list, exported as a CSV or a client-side PDF.
 */
class RoomAssignmentController extends Controller
{
    /** The room assignments console, with the first-pass runner. */
    public function index(Registrants $registrants, ReportQuestions $questions, GuestQuestions $guestQuestions)
    {
        $data = $this->data($registrants, $questions, $guestQuestions);

        return view('registration::admin.rooms.assignments', $data + [
            'pdfPayload' => $this->pdfPayload($data),
            'pdfPaper' => $this->pdfPaperSize(),
        ]);
    }

    /**
     * The room list as a CSV download: rooms in console order (empty ones
     * included), then the unassigned. Empty-room rows aren't people, so the
     * count footer carries the occupant total instead of the usual row-count
     * formula.
     */
    public function csv(Registrants $registrants, ReportQuestions $questions, GuestQuestions $guestQuestions, CsvExport $exporter): StreamedResponse
    {
        $data = $this->data($registrants, $questions, $guestQuestions);

        return $exporter->download(
            'room-assignments-'.now()->format('Ymd-His').'.csv',
            [
                __('registration::admin.rooms_wing'),
                __('registration::admin.rooms_floor'),
                __('registration::admin.export_room'),
                __('registration::admin.export_name'),
                __('registration::admin.export_occupant_type'),
                __('registration::admin.export_gender'),
            ],
            $this->exportRows($data)->push([])->push([$this->countLabel(), count($data['info'])]),
        );
    }

    /** The rooms, every occupant, and what the admin needs at a glance about each. */
    private function data(Registrants $registrants, ReportQuestions $questions, GuestQuestions $guestQuestions): array
    {
        $rooms = Room::with('assignments')
            ->orderBy('wing')->orderBy('floor')->orderBy('name')
            ->get();

        $all = $registrants->all();
        $occupants = $all->concat($all->flatMap(fn (Model $u) => $u->guests))->values();

        $assignedKeys = $rooms->flatMap(fn (Room $room) => $room->assignments)
            ->map(fn (RoomAssignment $a) => $a->assignable_type.':'.$a->assignable_id);

        return [
            'rooms' => $rooms,
            'questions' => $questions,
            'guestQuestions' => $guestQuestions,
            'occupantsByKey' => $occupants->keyBy(fn (Model $o) => $this->key($o)),
            'unassigned' => $occupants->reject(fn (Model $o): bool => $assignedKeys->contains($this->key($o)))->values(),
            // What the admin needs at a glance to place someone sensibly.
            'info' => $occupants->mapWithKeys(fn (Model $o) => [$this->key($o) => [
                'name' => $guestQuestions->occupantFullName($o, $questions),
                'type' => $o instanceof Guest ? 'guest' : 'user',
                'entryType' => $this->entryType($o),
                'gender' => ($o instanceof Guest ? $guestQuestions->gender($o) : $questions->gender($o))?->label(),
                'roommate' => $o instanceof Guest ? $guestQuestions->roommateName($o) : $questions->roommateName($o),
                // Which attendee brought this guest — irrelevant for a registrant.
                'host' => $o instanceof Guest ? $questions->fullName($o->user) : null,
            ]])->all(),
        ];
    }

    /** One row per placement (or per empty room) in room order, then one per unassigned occupant. */
    private function exportRows(array $data): Collection
    {
        $rows = collect();
        foreach ($data['rooms'] as $room) {
            $place = [$room->wing, $room->floor, $room->name];
            if ($room->assignments->isEmpty()) {
                $rows->push([...$place, '', '', '']);
            }
            foreach ($room->assignments as $assignment) {
                $rows->push([...$place, ...$this->occupantCells($data['info'], $assignment->assignable_type.':'.$assignment->assignable_id)]);
            }
        }

        foreach ($data['unassigned'] as $occupant) {
            $rows->push(['', '', '', ...$this->occupantCells($data['info'], $this->key($occupant))]);
        }

        return $rows;
    }

    /** An occupant's name, type and gender cells; "#id" alone for a placement whose occupant no longer exists. */
    private function occupantCells(array $info, string $key): array
    {
        $occupant = $info[$key] ?? null;

        return $occupant
            ? [$occupant['name'], $occupant['entryType'], $occupant['gender']]
            : ['#'.Str::afterLast($key, ':'), '', ''];
    }

    /**
     * Everything the view's client-side PDF generator needs: a bar per
     * wing/floor zone, a headline and name list per room, then the
     * unassigned — mirroring the on-screen cards.
     */
    private function pdfPayload(array $data): array
    {
        $names = fn (Collection $keys): string => $keys->map(fn (string $key) => $this->pdfName($data['info'], $key))->implode(', ');

        $zones = $data['rooms']->groupBy(fn (Room $room) => $room->wing.'|'.$room->floor)
            ->map(fn (Collection $zoneRooms): array => [
                'label' => __('registration::admin.rooms_zone', ['wing' => $zoneRooms->first()->wing, 'floor' => $zoneRooms->first()->floor])
                    .' — '.$zoneRooms->first()->designation->label(),
                'items' => $zoneRooms->map(fn (Room $room): array => [
                    'headline' => $room->name.' ('.trans_choice('registration::admin.assignments_vacancies', $room->vacancies(), ['count' => $room->vacancies()]).')',
                    'text' => $names($room->assignments->map(fn (RoomAssignment $a) => $a->assignable_type.':'.$a->assignable_id)),
                ])->values()->all(),
            ])->values()->all();

        $sections = [['heading' => null, 'bars' => $zones]];
        if ($data['unassigned']->isNotEmpty()) {
            $sections[] = [
                'heading' => __('registration::admin.assignments_unassigned'),
                'bars' => [['label' => null, 'items' => [['headline' => null, 'text' => $names($data['unassigned']->map(fn (Model $o) => $this->key($o)))]]]],
            ];
        }

        return [
            'filename' => 'room-assignments',
            'title' => __('registration::admin.assignments_title'),
            'sections' => $sections,
            'countLabel' => $this->countLabel(),
            'count' => count($data['info']),
        ];
    }

    /** A name for the PDF lists, with a guest's type marked. */
    private function pdfName(array $info, string $key): string
    {
        [$name, $entryType] = $this->occupantCells($info, $key);

        return ($info[$key]['type'] ?? null) === 'guest' ? $name.' ('.$entryType.')' : $name;
    }

    /** The occupant-type label the conference export also uses: attendee, adult guest or minor guest. */
    private function entryType(Model $occupant): string
    {
        if (! $occupant instanceof Guest) {
            return __('registration::admin.report_entry_attendee');
        }

        return $occupant->type === GuestType::Adult
            ? __('registration::admin.report_entry_adult_guest')
            : __('registration::admin.report_entry_minor_guest');
    }

    /** A stable string key for a mixed User/Guest collection — ids from different tables can collide numerically. */
    private function key(Model $occupant): string
    {
        return $occupant->getMorphClass().':'.$occupant->getKey();
    }

    /** Place (or move) an occupant into a room. */
    public function assign(Request $request)
    {
        $data = $request->validate([
            'occupant_type' => ['required', Rule::in(['user', 'guest'])],
            'occupant_id' => ['required', 'integer'],
            'room_id' => ['required', 'integer', Rule::exists(Room::make()->getTable(), 'id')],
        ]);

        if ($data['occupant_type'] === 'guest') {
            $request->validate(['occupant_id' => [Rule::exists(Guest::make()->getTable(), 'id')]]);
            $assignableType = Guest::class;
        } else {
            $request->validate(['occupant_id' => [Rule::exists(config('registration.user_table'), 'id')->whereNotNull('group_id')]]);
            $assignableType = config('registration.user_model');
        }

        RoomAssignment::updateOrCreate(
            ['assignable_type' => $assignableType, 'assignable_id' => $data['occupant_id']],
            ['room_id' => $data['room_id']],
        );

        return $this->back();
    }

    /** Remove one occupant from their room; the AJAX remove button expects no content back, other callers get the usual redirect. */
    public function unassign(Request $request, RoomAssignment $assignment)
    {
        $assignment->delete();

        return $request->wantsJson() ? response()->noContent() : $this->back();
    }

    /** Remove every placement, e.g. to redo the first pass from scratch. */
    public function unassignAll()
    {
        RoomAssignment::query()->delete();

        return $this->back();
    }

    /** Run the first pass; it only fills vacancies (see RoomAssigner). */
    public function firstPass(RoomAssigner $assigner)
    {
        $result = $assigner->assign();

        return $this->back(__('registration::admin.assignments_first_pass_done', [
            'assigned' => $result['assigned'],
            'unassigned' => $result['unassigned']->count(),
        ]));
    }

    /** Redirect back to the console with a status message. */
    private function back(?string $status = null)
    {
        $redirect = redirect()->route($this->routeName('admin.rooms.assignments'));

        return $status ? $redirect->with('assignments_status', $status) : $redirect;
    }
}
