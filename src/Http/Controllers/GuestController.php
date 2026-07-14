<?php

namespace ConferenceTools\Registration\Http\Controllers;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Services\DraftGuests;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\QuestionnaireValidator;
use ConferenceTools\Registration\Services\QuestionRepository;
use ConferenceTools\Registration\Services\RegistrationWizard;
use ConferenceTools\Registration\Services\VisibilityEvaluator;
use Illuminate\Http\Request;

/**
 * The "Guest List" hub: lets a registrant who answered "Yes" to the seeded
 * {@see Question::GUEST_TRIGGER_KEY} question add, edit and remove one or
 * more non-attending guests before completing the wizard. Reached as an
 * out-of-band detour from {@see RegistrationController::register()}, not as
 * a step in the wizard's own linear sequence — see that controller's class
 * doc-comment.
 *
 * Guests are held on the registrant's {@see Draft}
 * (via {@see DraftGuests}) until the registration itself is committed, at
 * which point each becomes a real {@see Guest}
 * row (see RegistrationController::commit()).
 */
class GuestController extends Controller
{
    /** The route-name prefix (before the routeName() config prefix) the shared guest views build their links from. */
    private const ROUTE_PREFIX = 'register.guests';

    public function __construct(
        private RegistrationWizard $wizard,
        private DraftGuests $draftGuests,
        private GuestQuestions $guestQuestions,
        private QuestionRepository $questions,
        private QuestionnaireValidator $validator,
        private VisibilityEvaluator $visibility,
    ) {}

    /** The hub: every guest added so far, with Add/Edit/Remove actions. */
    public function index(Request $request)
    {
        $draft = $this->wizard->draftFor($request->user());
        $answers = $this->wizard->answers($draft);
        abort_unless($this->triggered($answers), 404);

        $nameKey = $this->guestQuestions->questionKey(GuestQuestions::NAME_KEY);

        return view('registration::guests.index', [
            'guests' => collect($this->draftGuests->all($draft))->map(fn (array $guest) => [
                'id' => $guest['id'],
                'type' => GuestType::from($guest['type']),
                'name' => $nameKey !== null ? data_get($guest['answers'], $nameKey) : null,
            ]),
            'backUrl' => route($this->routeName('register')),
            'routePrefix' => self::ROUTE_PREFIX,
        ]);
    }

    /** The add-guest form: the guest's type, then every configured guest question. */
    public function create(Request $request)
    {
        $draft = $this->wizard->draftFor($request->user());
        abort_unless($this->triggered($this->wizard->answers($draft)), 404);

        return $this->form([
            'action' => route($this->routeName('register.guests.store')),
            'guestType' => null,
            'answers' => [],
        ]);
    }

    /** Add a guest to the registrant's draft. */
    public function store(Request $request)
    {
        $draft = $this->wizard->draftFor($request->user());
        abort_unless($this->triggered($this->wizard->answers($draft)), 404);

        $type = $this->validateGuestType($request);
        // The guest's own type is visibility context only (see
        // ConditionSubject::GuestType) — never a real question's answer, so
        // it rides alongside $request->all() rather than inside it.
        $answers = $this->validator->validate(QuestionScope::Guest, $request->all(), ['guest_type' => $type->value]);

        $this->draftGuests->add($draft, $type, $answers);

        return redirect()->route($this->routeName('register.guests'));
    }

    /** The edit-guest form: every configured guest question, pre-filled; the type is fixed. */
    public function edit(Request $request, string $guest)
    {
        $draft = $this->wizard->draftFor($request->user());
        $entry = $this->draftGuests->find($draft, $guest);
        abort_if($entry === null, 404);

        return $this->form([
            'action' => route($this->routeName('register.guests.update'), $guest),
            'guestType' => GuestType::from($entry['type']),
            // The fixed type joins the answers passed to the view so a
            // guest_type-conditioned question renders correctly even before
            // any live re-evaluation — see guests/form.blade.php's hidden
            // mirror input for how it stays correct after one too.
            'answers' => array_merge($entry['answers'], ['guest_type' => $entry['type']]),
        ]);
    }

    /** Save a drafted guest's answers. */
    public function update(Request $request, string $guest)
    {
        $draft = $this->wizard->draftFor($request->user());
        $entry = $this->draftGuests->find($draft, $guest);
        abort_if($entry === null, 404);

        $answers = $this->validator->validate(QuestionScope::Guest, $request->all(), ['guest_type' => $entry['type']]);
        $this->draftGuests->update($draft, $guest, $answers);

        return redirect()->route($this->routeName('register.guests'));
    }

    /** Remove a drafted guest. */
    public function destroy(Request $request, string $guest)
    {
        $draft = $this->wizard->draftFor($request->user());
        abort_if($this->draftGuests->find($draft, $guest) === null, 404);

        $this->draftGuests->remove($draft, $guest);

        return redirect()->route($this->routeName('register.guests'));
    }

    /** @param  array{action: string, guestType: ?GuestType, answers: array}  $data */
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
