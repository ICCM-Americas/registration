<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\RoomDesignation;
use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The admin "Rooms" console: the room inventory the assignment first pass
 * draws from. Rooms are entered a wing/floor combo (a "zone") at a time —
 * several room names at once — and the men/women/couples designation is a
 * property of the whole zone, so designating writes every room in it.
 */
class RoomController extends Controller
{
    /** A range beyond this span is left as a literal token rather than expanded, so a typo can't spawn millions of rooms. */
    private const MAX_RANGE_SPAN = 500;

    /** The rooms console, grouped by wing and floor. */
    public function index()
    {
        $rooms = Room::withCount('assignments')
            ->orderBy('wing')->orderBy('floor')->orderBy('name')
            ->get();

        return view('registration::admin.rooms.index', [
            'zones' => $rooms->groupBy(fn (Room $room): string => $room->wing.'|'.$room->floor),
            'designations' => RoomDesignation::cases(),
        ]);
    }

    /** Add rooms to a zone (creating it as needed): a name list with comma/space separators and inclusive numeric ranges like "101-120". */
    public function store(Request $request)
    {
        $data = $request->validate([
            'wing' => ['required', 'string', 'max:64'],
            'floor' => ['required', 'string', 'max:64'],
            'names' => ['required', 'string', 'max:1000'],
            'designation' => ['required', Rule::in(RoomDesignation::values())],
            'capacity' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $existing = Room::inZone($data['wing'], $data['floor'])->pluck('name');

        $this->expandNames($data['names'])
            ->reject(fn (string $name): bool => $existing->contains($name))
            ->each(fn (string $name) => Room::create([
                'wing' => $data['wing'],
                'floor' => $data['floor'],
                'name' => $name,
                'designation' => $data['designation'],
                'capacity' => $data['capacity'],
            ]));

        // The designation belongs to the zone: adding rooms restates it for
        // any rooms the zone already had.
        $this->designateZone($data['wing'], $data['floor'], $data['designation']);

        return $this->back();
    }

    /** Re-designate a whole wing/floor combo. */
    public function updateZone(Request $request)
    {
        $data = $request->validate([
            'wing' => ['required', 'string', 'max:64'],
            'floor' => ['required', 'string', 'max:64'],
            'designation' => ['required', Rule::in(RoomDesignation::values())],
        ]);

        $this->designateZone($data['wing'], $data['floor'], $data['designation']);

        return $this->back();
    }

    /** Remove a whole wing/floor combo (its assignments cascade away). */
    public function destroyZone(Request $request)
    {
        $data = $request->validate([
            'wing' => ['required', 'string', 'max:64'],
            'floor' => ['required', 'string', 'max:64'],
        ]);

        Room::inZone($data['wing'], $data['floor'])->get()->each->delete();

        return $this->back();
    }

    /** Save a room's details. */
    public function update(Request $request, Room $room)
    {
        $room->update($request->validate([
            'name' => [
                'required', 'string', 'max:64',
                Rule::unique($room->getTable(), 'name')
                    ->where('wing', $room->wing)->where('floor', $room->floor)
                    ->ignore($room->id),
            ],
            'capacity' => ['required', 'integer', 'min:1', 'max:20'],
        ]));

        return $this->back();
    }

    /** Remove a room. */
    public function destroy(Room $room)
    {
        $room->delete();

        return $this->back();
    }

    /** Split a names field into individual room names, expanding inclusive numeric ranges like "101-120" into their members. */
    private function expandNames(string $names): Collection
    {
        return collect(preg_split('/[,\s]+/', $names))
            ->map(fn (string $name): string => trim($name))
            ->filter(fn (string $name): bool => $name !== '')
            ->flatMap(fn (string $name): array => $this->expandRange($name))
            ->filter(fn (string $name): bool => mb_strlen($name) <= 64)
            ->unique();
    }

    /** Expand "101-120" into ['101', ..., '120'], zero-padded to the wider bound; anything else, including a reversed or oversized range, passes through unchanged. */
    private function expandRange(string $token): array
    {
        if (! preg_match('/^(\d+)-(\d+)$/', $token, $matches)) {
            return [$token];
        }

        [, $start, $end] = $matches;

        if ((int) $start > (int) $end || (int) $end - (int) $start >= self::MAX_RANGE_SPAN) {
            return [$token];
        }

        $width = max(strlen($start), strlen($end));

        return array_map(
            fn (int $room): string => str_pad((string) $room, $width, '0', STR_PAD_LEFT),
            range((int) $start, (int) $end),
        );
    }

    /** Set every room in a wing/floor zone's designation at once. */
    private function designateZone(string $wing, string $floor, string $designation): void
    {
        Room::inZone($wing, $floor)->update(['designation' => $designation]);
    }

    /** Redirect back to the console with a status message. */
    private function back()
    {
        return redirect()->route($this->routeName('admin.rooms'));
    }
}
