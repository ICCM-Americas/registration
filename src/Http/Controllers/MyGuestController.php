<?php

namespace ConferenceTools\Registration\Http\Controllers;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Services\AnswerStore;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\QuestionnaireValidator;
use ConferenceTools\Registration\Services\QuestionRepository;
use ConferenceTools\Registration\Services\RegistrationStatus;
use ConferenceTools\Registration\Services\VisibilityEvaluator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The registrant-facing "Manage Guests" hub for an already-committed
 * registration: add, edit and remove non-attending guests against real
 * {@see Guest} rows directly — mirrors {@see GuestController} (the pre-commit
 * hub, which operates on a wizard {@see Draft}'s
 * JSON "guests" column instead), reusing the same
 * `resources/views/guests/{index,form}.blade.php` views unmodified. Gated by
 * {@see RegistrationStatus::eligibleToModify()} — the same gate
 * {@see MyRegistrationController} uses for its own "Modify" — on every
 * action, since this hub has no wizard-draft/trigger-question concept to
 * detour from; a guest here is a first-class thing regardless of what the
 * registrant originally answered to the guest trigger question.
 */
class MyGuestController extends Controller
{
    /** The route-name prefix (before the routeName() config prefix) the shared guest views build their links from. */
    private const ROUTE_PREFIX = 'mine.guests';

    public function __construct(
        private GuestQuestions $guestQuestions,
        private QuestionRepository $questions,
        private QuestionnaireValidator $validator,
        private AnswerStore $answers,
        private VisibilityEvaluator $visibility,
        private RegistrationStatus $status,
    ) {}

    /** The hub: every committed guest, with Add/Edit/Remove actions. */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->status->eligibleToModify($user), 404);

        return view('registration::guests.index', [
            'guests' => $user->guests->map(fn (Guest $g) => [
                'id' => (string) $g->id,
                'type' => $g->type,
                'name' => $this->guestQuestions->displayName($g),
            ]),
            'backUrl' => route($this->routeName('mine')),
            'routePrefix' => self::ROUTE_PREFIX,
        ]);
    }

    /** The add-guest form: the guest's type, then every configured guest question. */
    public function create(Request $request)
    {
        abort_unless($this->status->eligibleToModify($request->user()), 404);

        return $this->form([
            'action' => route($this->routeName('mine.guests.store')),
            'guestType' => null,
            'answers' => [],
        ]);
    }

    /** Add a guest to the committed registration. */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($this->status->eligibleToModify($user), 404);

        $type = $this->validateGuestType($request);
        $answers = $this->validator->validate(QuestionScope::Guest, $request->all(), ['guest_type' => $type->value]);

        $guest = Guest::create([
            'user_id' => $user->getKey(),
            'type' => $type,
            'position' => ($user->guests()->max('position') ?? -1) + 1,
        ]);
        $this->answers->store(QuestionScope::Guest, $guest, $answers);

        return redirect()->route($this->routeName('mine.guests'));
    }

    /** The edit-guest form: every configured guest question, pre-filled; the type is fixed. */
    public function edit(Request $request, Guest $guest)
    {
        $user = $request->user();
        abort_unless($this->status->eligibleToModify($user), 404);
        $this->ensureOwnsGuest($user, $guest);

        return $this->form([
            'action' => route($this->routeName('mine.guests.update'), $guest),
            'guestType' => $guest->type,
            'answers' => [...$guest->registrationAnswers()->values(), 'guest_type' => $guest->type->value],
        ]);
    }

    /** Save a committed guest's answers. */
    public function update(Request $request, Guest $guest)
    {
        $user = $request->user();
        abort_unless($this->status->eligibleToModify($user), 404);
        $this->ensureOwnsGuest($user, $guest);

        $answers = $this->validator->validate(QuestionScope::Guest, $request->all(), ['guest_type' => $guest->type->value]);
        $this->answers->store(QuestionScope::Guest, $guest, $answers);

        return redirect()->route($this->routeName('mine.guests'));
    }

    /** Remove a committed guest. */
    public function destroy(Request $request, Guest $guest)
    {
        $user = $request->user();
        abort_unless($this->status->eligibleToModify($user), 404);
        $this->ensureOwnsGuest($user, $guest);

        $guest->delete();

        return redirect()->route($this->routeName('mine.guests'));
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

    /** 404 unless the guest actually belongs to this registrant. */
    private function ensureOwnsGuest(Model $user, Guest $guest): void
    {
        abort_unless((int) $guest->user_id === (int) $user->getKey(), 404);
    }
}
