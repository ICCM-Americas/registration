<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Http\Controllers\GuestController;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Services\DraftGuests;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\QuestionnaireValidator;
use ConferenceTools\Registration\Services\QuestionRepository;
use ConferenceTools\Registration\Services\VisibilityEvaluator;
use ConferenceTools\Registration\Support\TestDraft;
use Illuminate\Http\Request;

/**
 * The admin test drive's Guest List hub — mirrors {@see GuestController}
 * exactly, but reads/writes the session-held {@see TestDraft} instead of a
 * real {@see Draft}, so nothing is
 * recorded (see TestRegistrationController's class doc-comment).
 */
class TestGuestController extends Controller
{
    /** The route-name prefix (before the routeName() config prefix) the shared guest views build their links from. */
    private const ROUTE_PREFIX = 'admin.test.guests';

    public function __construct(
        private DraftGuests $draftGuests,
        private GuestQuestions $guestQuestions,
        private QuestionRepository $questions,
        private QuestionnaireValidator $validator,
        private VisibilityEvaluator $visibility,
    ) {}

    /** The test registrant's guest list hub. */
    public function index()
    {
        $draft = TestDraft::fromSession();
        abort_unless($this->triggered($draft->answers ?? []), 404);

        $nameKey = $this->guestQuestions->questionKey(GuestQuestions::NAME_KEY);

        return view('registration::guests.index', [
            'guests' => collect($this->draftGuests->all($draft))->map(fn (array $guest) => [
                'id' => $guest['id'],
                'type' => GuestType::from($guest['type']),
                'name' => $nameKey !== null ? data_get($guest['answers'], $nameKey) : null,
            ]),
            'backUrl' => route($this->routeName('admin.test')),
            'routePrefix' => self::ROUTE_PREFIX,
        ]);
    }

    /** Show the add-guest form. */
    public function create()
    {
        $draft = TestDraft::fromSession();
        abort_unless($this->triggered($draft->answers ?? []), 404);

        return $this->form([
            'action' => route($this->routeName('admin.test.guests.store')),
            'guestType' => null,
            'answers' => [],
        ]);
    }

    /** Add a guest to the test registration. */
    public function store(Request $request)
    {
        $draft = TestDraft::fromSession();
        abort_unless($this->triggered($draft->answers ?? []), 404);

        $type = $this->validateGuestType($request);
        $answers = $this->validator->validate(QuestionScope::Guest, $request->all());

        $this->draftGuests->add($draft, $type, $answers);

        return redirect()->route($this->routeName('admin.test.guests'));
    }

    /** Show the edit form for a drafted guest. */
    public function edit(string $guest)
    {
        $draft = TestDraft::fromSession();
        $entry = $this->draftGuests->find($draft, $guest);
        abort_if($entry === null, 404);

        return $this->form([
            'action' => route($this->routeName('admin.test.guests.update'), $guest),
            'guestType' => GuestType::from($entry['type']),
            'answers' => $entry['answers'],
        ]);
    }

    /** Save a drafted guest's answers. */
    public function update(Request $request, string $guest)
    {
        $draft = TestDraft::fromSession();
        abort_if($this->draftGuests->find($draft, $guest) === null, 404);

        $answers = $this->validator->validate(QuestionScope::Guest, $request->all());
        $this->draftGuests->update($draft, $guest, $answers);

        return redirect()->route($this->routeName('admin.test.guests'));
    }

    /** Remove a drafted guest. */
    public function destroy(string $guest)
    {
        $draft = TestDraft::fromSession();
        abort_if($this->draftGuests->find($draft, $guest) === null, 404);

        $this->draftGuests->remove($draft, $guest);

        return redirect()->route($this->routeName('admin.test.guests'));
    }

    /** Render the guest form with the Guest-scope questionnaire. */
    private function form(array $data)
    {
        return view('registration::guests.form', $data + [
            'sections' => $this->questions->sectionsForScope(QuestionScope::Guest),
            'evaluator' => $this->visibility,
            'def' => Currency::def(),
            'routePrefix' => self::ROUTE_PREFIX,
        ]);
    }

    /** The validated guest type from the request. */
    private function validateGuestType(Request $request): GuestType
    {
        $data = $request->validate(['guest_type' => ['required', 'in:'.implode(',', GuestType::values())]]);

        return GuestType::from($data['guest_type']);
    }

    /** Whether the draft's answers indicate the registrant has at least one non-attending guest coming. */
    private function triggered(array $answers): bool
    {
        return ($answers[Question::GUEST_TRIGGER_KEY] ?? null) === Question::YES_VALUE;
    }
}
