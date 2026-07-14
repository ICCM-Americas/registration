<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Services\CsvExport;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Services\ShuttlePlanner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The airport shuttle schedules — pickups batched so no one waits over three
 * hours, and the two daily return runs (see ShuttlePlanner). Passengers are
 * registrants and their non-attending guests. View online, print, or export;
 * the seats, fleet size, and travel-time settings live on the Reports hub. A
 * passenger whose flight-time answer couldn't be read, or who a return run
 * has no seat left for, is listed by name for hand scheduling — clicking the
 * name opens the answer, exactly as entered, in the shared editor modal, so
 * the admin can fix it into something readable (see {@see editFlight()}).
 */
class ShuttleScheduleController extends Controller
{
    /** The shuttle schedule report page. */
    public function index(ShuttlePlanner $planner, ReportQuestions $questions, GuestQuestions $guestQuestions)
    {
        $data = $this->data($planner, $questions, $guestQuestions);

        return view('registration::admin.logistics.shuttles', $data + [
            'totalPassengers' => $this->totalPassengers($data),
            'pdfPayload' => $this->pdfPayload($data, $questions, $guestQuestions),
        ]);
    }

    /**
     * Everything the view's client-side PDF generator needs — strings
     * localized and each run's headline and passenger list pre-formatted
     * here, so the script stays presentation-only.
     */
    private function pdfPayload(array $data, ReportQuestions $questions, GuestQuestions $guestQuestions): array
    {
        $names = fn ($passengers): string => $passengers
            ->map(fn ($passenger) => $guestQuestions->occupantFullName($passenger, $questions))
            ->implode(', ');

        $sections = [];
        foreach ([
            [__('registration::admin.shuttles_pickups'), 'arrival', $data['arrivalRuns']],
            [__('registration::admin.shuttles_returns'), 'departure', $data['returnRuns']],
        ] as [$heading, $flight, $days]) {
            if ($days->isEmpty()) {
                continue;
            }

            $sections[] = [
                'heading' => $heading,
                'days' => $days->map(fn (array $day): array => [
                    'label' => $day['label'] !== ''
                        ? $day['label'].' — '.trans_choice('registration::admin.shuttles_passengers', $day['count'])
                        : '',
                    'runs' => collect($day['runs'])->map(fn (array $run): array => [
                        'headline' => __('registration::admin.shuttles_run_at', ['time' => $run['time']])
                            .' — '.trans_choice('registration::admin.shuttles_passengers', $run['passengers']->count())
                            .(($run['shuttles'] ?? 1) > 1 ? ' — '.trans_choice('registration::admin.shuttles_vehicles', $run['shuttles']) : ''),
                        'names' => $data['planner']->passengerList($run['passengers'], $flight, $guestQuestions, $questions),
                        'overflow' => ($run['overflow'] ?? collect())->isNotEmpty()
                            ? __('registration::admin.shuttles_overflow').' '.$names($run['overflow'])
                            : null,
                    ])->values()->all(),
                    'unscheduled' => $day['unscheduled']->isNotEmpty()
                        ? __('registration::admin.shuttles_unscheduled').' '.$names($day['unscheduled'])
                        : null,
                ])->values()->all(),
            ];
        }

        return [
            'filename' => 'shuttle-schedule',
            'paper' => $this->pdfPaperSize(),
            'title' => __('registration::admin.shuttles_title'),
            'sections' => $sections,
            'countLabel' => $this->countLabel(),
            'count' => $this->totalPassengers($data),
        ];
    }

    /** The shuttle schedule as a CSV download. */
    public function csv(ShuttlePlanner $planner, ReportQuestions $questions, GuestQuestions $guestQuestions, CsvExport $exporter): StreamedResponse
    {
        $data = $this->data($planner, $questions, $guestQuestions);
        $rows = [];

        foreach ([
            __('registration::admin.shuttles_pickups') => $data['arrivalRuns'],
            __('registration::admin.shuttles_returns') => $data['returnRuns'],
        ] as $type => $days) {
            foreach ($days as $day) {
                foreach ($day['runs'] as $run) {
                    foreach ($run['passengers'] as $passenger) {
                        $rows[] = [$type, $day['label'], $run['time'], $guestQuestions->occupantFullName($passenger, $questions)];
                    }

                    foreach ($run['overflow'] ?? [] as $passenger) {
                        $rows[] = [$type, $day['label'], __('registration::admin.shuttles_overflow'), $guestQuestions->occupantFullName($passenger, $questions)];
                    }
                }

                foreach ($day['unscheduled'] as $passenger) {
                    $rows[] = [$type, $day['label'], __('registration::admin.shuttles_unscheduled'), $guestQuestions->occupantFullName($passenger, $questions)];
                }
            }
        }

        return $this->downloadCsv($exporter, 'shuttle-schedule',
            ['Type', 'Day', 'Run', 'Name'],
            $rows,
        );
    }

    /**
     * The travel-answer editor fragment for one registrant, fetched into the
     * shared editor modal from an "unreadable" name on the schedule. A guest's
     * name links here with their registrant's id — a guest rides on their
     * registrant's flights, so the registrant's answer is the one to fix.
     */
    public function editFlight(ReportQuestions $questions, ShuttlePlanner $planner, string $flight, int $user)
    {
        return $this->flightEditor($questions, $planner, $flight, $this->registrant($user));
    }

    /**
     * Save the corrected travel answer and return the editor refreshed, its
     * read-back status lines showing whether the schedules can read it now.
     * The value is stored the way {@see AnswerStore} stores free text —
     * trimmed, never interpolated — updating the first existing row in place
     * (keeping any pricing snapshot) and dropping extras; an emptied answer
     * deletes the rows, removing the passenger from the shuttle schedules.
     */
    public function updateFlight(Request $request, ReportQuestions $questions, ShuttlePlanner $planner, string $flight, int $user)
    {
        $registrant = $this->registrant($user);
        $data = $request->validate(['value' => ['nullable', 'string', 'max:5000']]);
        $question = $this->flightQuestion($questions, $flight);

        $rows = $question->answers()
            ->where('owner_type', $registrant->getMorphClass())
            ->where('owner_id', $registrant->getKey())
            ->orderBy('id')
            ->get();

        $value = trim((string) ($data['value'] ?? ''));
        if ($value === '') {
            $rows->each->delete();
        } elseif ($rows->isEmpty()) {
            $question->answers()->create([
                'owner_type' => $registrant->getMorphClass(),
                'owner_id' => $registrant->getKey(),
                'value' => $value,
            ]);
        } else {
            $rows->first()->update(['value' => $value]);
            $rows->slice(1)->each->delete();
        }

        $registrant->refreshRegistrationAnswers();

        return $this->flightEditor($questions, $planner, $flight, $registrant, saved: true);
    }

    /** Show the per-registrant flight-times correction form. */
    private function flightEditor(ReportQuestions $questions, ShuttlePlanner $planner, string $flight, Model $registrant, bool $saved = false)
    {
        $question = $this->flightQuestion($questions, $flight);
        $shared = $questions->questionKey(ReportQuestions::FLIGHT_ARRIVAL_KEY)
            === $questions->questionKey(ReportQuestions::FLIGHT_DEPARTURE_KEY);

        // What the schedules read from the answer as it stands: normalized
        // times, or null where still unreadable. A shared travel-plans
        // question feeds both flights; separate questions each feed one.
        $statuses = [];
        foreach ([
            'arrival' => fn (): ?string => $questions->flightArrivalTime($registrant),
            'departure' => fn (): ?string => $questions->flightDepartureTime($registrant),
        ] as $which => $time) {
            if (($shared || $which === $flight) && ($answer = $time()) !== null) {
                $statuses[$which] = $planner->readableTime($answer);
            }
        }

        $value = $registrant->registrationAnswers()->value($question->key);

        return view('registration::admin.logistics.flight-editor', [
            'flight' => $flight,
            'registrant' => $registrant,
            'question' => $question,
            'value' => is_array($value) ? implode("\n", $value) : (string) $value,
            'name' => $questions->fullName($registrant) ?? $registrant->name,
            'statuses' => $statuses,
            'saved' => $saved,
        ]);
    }

    /** The question the flight setting nominates — 404 while unnominated or dangling. */
    private function flightQuestion(ReportQuestions $questions, string $flight): Question
    {
        $key = $questions->questionKey(
            $flight === 'arrival' ? ReportQuestions::FLIGHT_ARRIVAL_KEY : ReportQuestions::FLIGHT_DEPARTURE_KEY
        );
        abort_if($key === null, 404);

        return Question::where('key', $key)->firstOrFail();
    }

    /** The registrant (host user) by id, or 404. */
    private function registrant(int $id): Model
    {
        return config('registration.user_model')::findOrFail($id);
    }

    /** @return array{arrivalRuns: Collection, returnRuns: Collection, planner: ShuttlePlanner, questions: ReportQuestions, guestQuestions: GuestQuestions} */
    private function data(ShuttlePlanner $planner, ReportQuestions $questions, GuestQuestions $guestQuestions): array
    {
        return [
            'arrivalRuns' => $planner->arrivalRuns(),
            'returnRuns' => $planner->returnRuns(),
            'planner' => $planner,
            'questions' => $questions,
            'guestQuestions' => $guestQuestions,
        ];
    }

    /** The total passengers across every pickup and return day, unscheduled included. */
    private function totalPassengers(array $data): int
    {
        $countDays = fn (Collection $days): int => $days->sum(fn (array $day): int => $day['count']);

        return $countDays($data['arrivalRuns']) + $countDays($data['returnRuns']);
    }
}
