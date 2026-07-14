<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Room;
use ConferenceTools\Registration\Models\RoomAssignment;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\Registrants;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Services\RoomAssigner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The admin "Room Assignments" console: run the first pass, then correct it —
 * the questions never carry quite enough information, so every placement can
 * be moved or removed by hand. Occupants are registrants and their
 * non-attending guests (both types — see RoomAssigner). Doubles as the
 * printable room list (the page carries print styling like the other report
 * pages).
 */
class RoomAssignmentController extends Controller
{
    /** The room assignments console, with the first-pass runner. */
    public function index(Registrants $registrants, ReportQuestions $questions, GuestQuestions $guestQuestions)
    {
        $rooms = Room::with('assignments')
            ->orderBy('wing')->orderBy('floor')->orderBy('name')
            ->get();

        $all = $registrants->all();
        $occupants = $all->concat($all->flatMap(fn (Model $u) => $u->guests))->values();
        $key = fn (Model $o): string => $o->getMorphClass().':'.$o->getKey();

        $assignedKeys = $rooms->flatMap(fn (Room $room) => $room->assignments)
            ->map(fn (RoomAssignment $a) => $a->assignable_type.':'.$a->assignable_id);

        return view('registration::admin.rooms.assignments', [
            'rooms' => $rooms,
            'questions' => $questions,
            'guestQuestions' => $guestQuestions,
            'occupantsByKey' => $occupants->keyBy($key),
            'unassigned' => $occupants->reject(fn (Model $o): bool => $assignedKeys->contains($key($o)))->values(),
            // What the admin needs at a glance to place someone sensibly.
            'info' => $occupants->mapWithKeys(fn (Model $o) => [$key($o) => [
                'name' => $guestQuestions->occupantFullName($o, $questions),
                'type' => $o instanceof Guest ? 'guest' : 'user',
                'gender' => ($o instanceof Guest ? $guestQuestions->gender($o) : $questions->gender($o))?->label(),
                'roommate' => $o instanceof Guest ? $guestQuestions->roommateName($o) : $questions->roommateName($o),
                // Which attendee brought this guest — irrelevant for a registrant.
                'host' => $o instanceof Guest ? $questions->fullName($o->user) : null,
            ]])->all(),
        ]);
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
