<?php

namespace ConferenceTools\Registration\Http\Controllers;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Http\Controllers\Admin\PaymentsController;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\GroupInvite;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Services\AnswerStore;
use ConferenceTools\Registration\Services\CostSummaryBuilder;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\RegistrationEmails;
use ConferenceTools\Registration\Services\RegistrationStatus;
use ConferenceTools\Registration\Services\RegistrationWizard;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Services\VariableInterpolator;
use ConferenceTools\Registration\Services\VisibilityEvaluator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The registrant-facing "My Registration" page: view an already-committed
 * registration (reusing the same rendering the admin's Payments "show" page
 * uses — see {@see PaymentsController}
 * and partials.registration-summary), and — while eligible — modify it by
 * reseeding the wizard from the committed answers (see
 * {@see RegistrationWizard::draftForEdit()}).
 *
 * Deliberately not behind EnsureRegistrationIsOpen: a registrant must be able
 * to view their registration (and find the administrator's address) even
 * once registration has closed.
 *
 * "Modify" (the edit wizard below) is scoped to the registrant's own
 * Participant-scope answers only: the guest and group-member trigger
 * questions are excluded from the reseeded wizard entirely, so those steps
 * (and their hub detours) never appear here. Re-running the whole wizard for
 * edits would otherwise risk creating duplicate Guest/GroupInvite rows, since
 * the original commit path always creates them fresh, assuming a first-time
 * commit. Guest and group-member membership changes after commit are handled
 * separately by {@see MyGuestController} and {@see MyGroupMemberController},
 * which operate on the real, already-committed rows directly rather than the
 * wizard, and share this controller's own modify gate (see
 * {@see RegistrationStatus::eligibleToModify()}).
 */
class MyRegistrationController extends Controller
{
    public function __construct(
        protected RegistrationWizard $wizard,
        protected AnswerStore $answers,
        protected VisibilityEvaluator $visibility,
        protected CostSummaryBuilder $costs,
        protected RegistrationStatus $status,
        protected RegistrationEmails $emails,
        protected VariableInterpolator $interpolator,
    ) {}

    /** View the signed-in registrant's own registration, read-only. */
    public function show(Request $request, ReportQuestions $questions, GuestQuestions $guestQuestions)
    {
        $user = $request->user();
        abort_unless($user->group !== null, 404);
        $user->load('payment', 'guests');

        return view('registration::mine', [
            'registrant' => $user,
            'name' => $questions->fullName($user),
            'fields' => $user->registrationAnswers()->fields(),
            'guestQuestions' => $guestQuestions,
            'costSummary' => $this->costs->fromAnswers($this->fullAnswers($user), $this->guestsPayload($user)),
            'def' => Currency::def(),
            'canModify' => $this->status->eligibleToModify($user),
            'adminEmail' => $this->emails->adminEmail(),
            'groupMembers' => $user->is_group_admin ? $this->groupMembers($user, $questions) : null,
        ]);
    }

    /** Show the current edit-wizard step, reseeded from the committed answers. */
    public function edit(Request $request)
    {
        $user = $request->user();
        if (! $this->status->eligibleToModify($user)) {
            return redirect()->route($this->routeName('mine'));
        }

        $this->excludeGuestAndGroupSteps();
        $draft = $this->wizard->draftForEdit($user);
        $step = $this->wizard->currentStep($draft);
        $answers = $this->wizard->answers($draft);

        // The draft's answers are the {q:...value/.cost} context for variable
        // tokens in the step's question texts.
        $this->interpolator->setAnswers($answers);

        $isLast = $step ? $this->wizard->isLast($draft, $step) : true;

        return view('registration::register-step', [
            'step' => $step,
            'answers' => $answers,
            'evaluator' => $this->visibility,
            'def' => Currency::def(),
            'stepNumber' => $step ? $this->wizard->stepNumber($draft, $step) : 0,
            'totalSteps' => $this->wizard->totalSteps($draft),
            'isFirst' => $step ? $this->wizard->isFirst($draft, $step) : true,
            'isLast' => $isLast,
            'costSummary' => $step && $isLast ? $this->costs->fromAnswers($answers) : null,
            'memberCostSummary' => null,
            'guestsLinkVisible' => false,
            'groupMembersLinkVisible' => false,
            'formAction' => route($this->routeName('mine.update')),
        ]);
    }

    /** Process a submitted edit-wizard step; on the final step, re-store the answers in place. */
    public function update(Request $request)
    {
        $user = $request->user();
        if (! $this->status->eligibleToModify($user)) {
            return redirect()->route($this->routeName('mine'));
        }

        $this->excludeGuestAndGroupSteps();
        $draft = $this->wizard->draftForEdit($user);

        $step = $this->wizard->findStep($draft, (int) $request->input('_step'));
        abort_if($step === null, 422);

        if ($request->input('_direction') === 'back') {
            if ($previous = $this->wizard->previous($draft, $step)) {
                $this->wizard->setCurrent($draft, $previous);
            }

            return redirect()->route($this->routeName('mine.edit'));
        }

        $this->wizard->submit($draft, $step, $request->all());

        if ($next = $this->wizard->next($draft, $step)) {
            $this->wizard->setCurrent($draft, $next);

            return redirect()->route($this->routeName('mine.edit'));
        }

        $this->recommit($user, $this->wizard->answers($draft));
        $this->wizard->discard($draft);

        return redirect()->route($this->routeName('mine'))->with('mine_status', __('registration::mine.updated'));
    }

    /** Keep the guest/group-member trigger questions (and their hub detours) out of the edit wizard entirely — see the class docblock. */
    private function excludeGuestAndGroupSteps(): void
    {
        $this->wizard->excludeKey(Question::GUEST_TRIGGER_KEY);
        $this->wizard->excludeKey(Question::GROUP_TRIGGER_KEY);
    }

    /** Re-store the edited answers in place: no new Group/Guest/GroupInvite rows, just the existing owners' answers (replace-on-save — see AnswerStore). */
    private function recommit(Model $user, array $answers): void
    {
        $this->answers->store(QuestionScope::Participant, $user, $answers);
        $this->answers->store(QuestionScope::Group, $user->group, $answers);
    }

    /**
     * The group leader's members: completed registrants (excluding the
     * leader) and any invitees who haven't finished registering yet.
     *
     * @return list<array{name: ?string, registered: bool}>
     */
    private function groupMembers(Model $user, ReportQuestions $questions): array
    {
        $completed = $user->group->users()
            ->where($user->getKeyName(), '!=', $user->getKey())
            ->get()
            ->map(fn (Model $member) => ['name' => $questions->fullName($member), 'registered' => true]);

        $pending = GroupInvite::query()
            ->where('group_id', $user->group_id)
            ->whereNull('consumed_at')
            ->get()
            ->map(fn (GroupInvite $invite) => [
                'name' => $invite->registrationAnswers()->value(Question::GROUP_MEMBER_NAME_KEY),
                'registered' => false,
            ]);

        return $completed->concat($pending)->all();
    }

    /**
     * A registrant's Participant-scope answers merged with their group's
     * Group-scope answers into one flat map — the shape
     * {@see CostSummaryBuilder::fromAnswers()} expects.
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
}
