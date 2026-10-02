<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Payment;
use ConferenceTools\Registration\Services\AnswerStore;
use ConferenceTools\Registration\Services\CostSummaryBuilder;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\QuestionnaireValidator;
use ConferenceTools\Registration\Services\QuestionRepository;
use ConferenceTools\Registration\Services\Registrants;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Services\VisibilityEvaluator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The admin "Payments" console: every registrant (and their guests) with their
 * computed cost, a manually-recorded paid/amount/notes state per registrant,
 * and links to review or correct their answers. Names shown throughout are the
 * nominated first/last (or guest) name — never the badge name, which is
 * formatted for printing rather than for confirming who's who.
 */
class PaymentsController extends Controller
{
    /** The registrant list: computed cost, payment state, and Group/Leader labels, in the requested sort order. */
    public function index(Request $request, Registrants $registrants, ReportQuestions $questions, GuestQuestions $guestQuestions)
    {
        $sort = in_array($request->query('sort'), ['first', 'group'], true) ? $request->query('sort') : 'last';
        $all = $registrants->all()->load('payment');

        $ordered = match ($sort) {
            'first' => $all->sortBy(fn (Model $u) => mb_strtolower((string) $questions->firstName($u)))->values(),
            'group' => $this->sortByGroup($all, $questions),
            // Registrants::all() is already ordered last name, then first name.
            default => $all,
        };

        return view('registration::admin.payments.index', [
            'registrants' => $ordered,
            'sort' => $sort,
            'questions' => $questions,
            'guestQuestions' => $guestQuestions,
            'def' => Currency::def(),
        ]);
    }

    /** Read-only view of a registrant's (and their guests') answers, plus their itemized cost summary. */
    public function show(int $user, ReportQuestions $questions, GuestQuestions $guestQuestions, CostSummaryBuilder $builder)
    {
        $registrant = $this->registrant($user)->load('payment', 'guests');

        return view('registration::admin.payments.show', [
            'registrant' => $registrant,
            'name' => $questions->fullName($registrant),
            'fields' => $registrant->registrationAnswers()->fields(),
            'guestQuestions' => $guestQuestions,
            'costSummary' => $builder->fromAnswers($this->fullAnswers($registrant), $this->guestsPayload($registrant)),
            'def' => Currency::def(),
        ]);
    }

    /** The registrant's own answers, editable. */
    public function editAnswers(int $user, ReportQuestions $questions, QuestionRepository $repository, VisibilityEvaluator $visibility)
    {
        $registrant = $this->registrant($user);

        return view('registration::admin.payments.answers-edit', [
            'ownerName' => $questions->fullName($registrant),
            'sections' => $repository->sectionsForScope(QuestionScope::Participant),
            'answers' => $registrant->registrationAnswers()->values(),
            'evaluator' => $visibility,
            'def' => Currency::def(),
            'action' => route($this->routeName('admin.payments.answers.update'), $user),
            'previewAction' => route($this->routeName('admin.payments.answers.preview'), $user),
            'backAction' => $this->searchReturn(request()) ?? route($this->routeName('admin.payments.show'), $user),
            'returnTo' => $this->searchReturn(request()),
            'baselineTotal' => $registrant->cost(),
            'baselineFormatted' => $this->formattedTotal($registrant->cost()),
        ]);
    }

    /** Save the registrant's edited answers. No server-side cost-confirmation gate — see the edit view's cost-change modal for why. */
    public function updateAnswers(Request $request, int $user, QuestionnaireValidator $validator, AnswerStore $store)
    {
        $registrant = $this->registrant($user);
        $validated = $validator->validate(QuestionScope::Participant, $request->all());
        $store->store(QuestionScope::Participant, $registrant, $validated);

        return $this->answersSaved($request, $user);
    }

    /** Recompute the registrant's total cost against a candidate (not-yet-saved) set of Participant-scope answers, for the cost-change modal. */
    public function costPreview(Request $request, int $user, QuestionnaireValidator $validator, CostSummaryBuilder $builder)
    {
        $registrant = $this->registrant($user);

        try {
            $validated = $validator->validate(QuestionScope::Participant, $request->all());
        } catch (ValidationException) {
            // Best-effort preview only: another field mid-edit may be invalid.
            // Real validation still gates Save.
            return response()->noContent();
        }

        $groupAnswers = $registrant->group?->registrationAnswers()->values() ?? [];
        $total = $builder->fromAnswers([...$groupAnswers, ...$validated], $this->guestsPayload($registrant))->total;

        return $this->totalResponse($total);
    }

    /** One of the registrant's guests' own answers, editable. */
    public function editGuestAnswers(int $user, Guest $guest, GuestQuestions $guestQuestions, QuestionRepository $repository, VisibilityEvaluator $visibility)
    {
        $registrant = $this->registrant($user);
        $this->ensureOwnsGuest($registrant, $guest);

        return view('registration::admin.payments.answers-edit', [
            'ownerName' => $guestQuestions->displayName($guest),
            'sections' => $repository->sectionsForScope(QuestionScope::Guest),
            // The guest's fixed type rides alongside the answers, exactly as
            // the registrant-facing GuestController does, so a
            // guest_type-conditioned question renders correctly.
            'answers' => [...$guest->registrationAnswers()->values(), 'guest_type' => $guest->type->value],
            'evaluator' => $visibility,
            'def' => Currency::def(),
            'action' => route($this->routeName('admin.payments.guests.answers.update'), [$user, $guest]),
            'previewAction' => route($this->routeName('admin.payments.guests.answers.preview'), [$user, $guest]),
            'backAction' => $this->searchReturn(request()) ?? route($this->routeName('admin.payments.show'), $user),
            'returnTo' => $this->searchReturn(request()),
            'baselineTotal' => $registrant->cost(),
            'baselineFormatted' => $this->formattedTotal($registrant->cost()),
        ]);
    }

    /** Save a guest's edited answers. */
    public function updateGuestAnswers(Request $request, int $user, Guest $guest, QuestionnaireValidator $validator, AnswerStore $store)
    {
        $registrant = $this->registrant($user);
        $this->ensureOwnsGuest($registrant, $guest);

        $validated = $validator->validate(QuestionScope::Guest, $request->all(), ['guest_type' => $guest->type->value]);
        $store->store(QuestionScope::Guest, $guest, $validated);

        return $this->answersSaved($request, $user);
    }

    /** Recompute the owning registrant's total cost against a candidate set of one guest's answers, for the cost-change modal. */
    public function guestCostPreview(Request $request, int $user, Guest $guest, QuestionnaireValidator $validator, CostSummaryBuilder $builder)
    {
        $registrant = $this->registrant($user);
        $this->ensureOwnsGuest($registrant, $guest);

        try {
            $validated = $validator->validate(QuestionScope::Guest, $request->all(), ['guest_type' => $guest->type->value]);
        } catch (ValidationException) {
            return response()->noContent();
        }

        $answers = $this->fullAnswers($registrant);
        $guests = $registrant->guests->map(fn (Guest $g) => [
            'id' => (string) $g->id,
            'type' => $g->type->value,
            'answers' => $g->is($guest) ? $validated : $g->registrationAnswers()->values(),
        ])->all();

        return $this->totalResponse($builder->fromAnswers($answers, $guests)->total);
    }

    /** Upsert a registrant's paid/amount/notes. */
    public function updatePayment(Request $request, int $user)
    {
        $registrant = $this->registrant($user);

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payment = Payment::firstOrNew(['user_id' => $registrant->getKey()]);
        $payment->forceFill([
            'is_paid' => $request->boolean('is_paid'),
            'amount' => $data['amount'] ?? null,
            'notes' => $data['notes'] ?? null,
        ])->save();

        return redirect()->back()->with('payments_status', __('registration::admin.payments_payment_updated'));
    }

    /** After saving answers: back to the search results they were opened from, else the registrant's page. */
    private function answersSaved(Request $request, int $user)
    {
        if ($return = $this->searchReturn($request)) {
            return redirect($return);
        }

        return redirect()->route($this->routeName('admin.payments.show'), $user)
            ->with('payments_status', __('registration::admin.payments_answers_updated'));
    }

    /** The host user behind a Payments route's {user} segment — the host's user model is configurable, so it can't be implicitly route-bound. */
    private function registrant(int $id): Model
    {
        return config('registration.user_model')::findOrFail($id);
    }

    /** 404 unless the guest actually belongs to this registrant — a Payments guest route is always nested under its attendee. */
    private function ensureOwnsGuest(Model $registrant, Guest $guest): void
    {
        abort_unless((int) $guest->user_id === (int) $registrant->getKey(), 404);
    }

    /**
     * A registrant's Participant-scope answers merged with their group's
     * Group-scope answers into one flat map — the shape {@see CostSummaryBuilder::fromAnswers()}
     * expects (it prices both scopes together, exactly as the wizard's own
     * draft answers do before commit).
     *
     * @return array<string, mixed>
     */
    private function fullAnswers(Model $registrant): array
    {
        return [...$registrant->group?->registrationAnswers()->values() ?? [], ...$registrant->registrationAnswers()->values()];
    }

    /**
     * A registrant's guests in the shape {@see CostSummaryBuilder::fromAnswers()} expects.
     *
     * @return list<array{id: string, type: string, answers: array}>
     */
    private function guestsPayload(Model $registrant): array
    {
        return $registrant->guests->map(fn (Guest $g) => [
            'id' => (string) $g->id,
            'type' => $g->type->value,
            'answers' => $g->registrationAnswers()->values(),
        ])->all();
    }

    /** The cost-change modal's JSON response: the recomputed total, raw and formatted. */
    private function totalResponse(float $total)
    {
        return response()->json([
            'total' => $total,
            'formatted' => $this->formattedTotal($total),
        ]);
    }

    /** An amount formatted in the default currency (a plain decimal string when none is configured). */
    private function formattedTotal(float $total): string
    {
        return Currency::def()?->format($total) ?? number_format($total, 2);
    }

    /**
     * Re-partition registrants by group: within each group the leader sorts
     * first, then remaining members alphabetically; the groups themselves are
     * then ordered by their leader's name. A solo registrant is a group of
     * one, so this naturally leaves them at their own alphabetical position.
     *
     * @param  Collection<int, Model>  $registrants
     * @return Collection<int, Model>
     */
    private function sortByGroup(Collection $registrants, ReportQuestions $questions): Collection
    {
        return $registrants
            ->groupBy(fn (Model $u) => $u->group_id)
            ->map(fn (Collection $members) => $members->sortBy(fn (Model $u) => [
                $u->is_group_admin ? 0 : 1,
                mb_strtolower((string) $questions->lastName($u)),
                mb_strtolower((string) $questions->firstName($u)),
            ])->values())
            ->sortBy(fn (Collection $members) => [
                mb_strtolower((string) $questions->lastName($members->first())),
                mb_strtolower((string) $questions->firstName($members->first())),
            ])
            ->flatMap(fn (Collection $members) => $members)
            ->values();
    }
}
