<?php

namespace ConferenceTools\Registration\Http\Controllers;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\GroupInvite;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\InfoStep;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Services\AnswerStore;
use ConferenceTools\Registration\Services\CostSummaryBuilder;
use ConferenceTools\Registration\Services\DraftGroupMembers;
use ConferenceTools\Registration\Services\DraftGuests;
use ConferenceTools\Registration\Services\GroupRegistrationService;
use ConferenceTools\Registration\Services\RegistrationMailer;
use ConferenceTools\Registration\Services\RegistrationWizard;
use ConferenceTools\Registration\Services\VariableInterpolator;
use ConferenceTools\Registration\Services\VisibilityEvaluator;
use ConferenceTools\Registration\Support\Step;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** The registrant-facing wizard: step rendering, answer drafting, and the final commit. */
class RegistrationController extends Controller
{
    public function __construct(
        protected GroupRegistrationService $registration,
        protected RegistrationWizard $wizard,
        protected AnswerStore $answers,
        protected VisibilityEvaluator $visibility,
        protected RegistrationMailer $mailer,
        protected VariableInterpolator $interpolator,
        protected CostSummaryBuilder $costs,
        protected DraftGuests $draftGuests,
        protected DraftGroupMembers $draftGroupMembers,
    ) {}

    /** Public registration information / landing page. */
    public function info()
    {
        return view('registration::registration', [
            'def' => Currency::def(),
            'steps' => InfoStep::shown()->with('translations')->get(),
        ]);
    }

    /**
     * Show the current wizard step (a whole configured section, or a single
     * reactive trigger question — see {@see Step}).
     *
     * Reaching this form requires an account: the route is auth-gated, so the
     * host application's login / account creation happens first. The wizard walks
     * the registrant's participant-scope sections one step at a time (group-scope
     * questions are not shown while the group flow is parked); each step
     * is posted back to {@see register()} so validation and cross-step visibility
     * are decided on the server. An invited group member (see {@see invite()})
     * never sees the group-registration question at all.
     */
    public function showForm(Request $request)
    {
        $invite = $this->invite($request);

        $draft = $this->wizard->draftFor($request->user());
        $step = $this->wizard->currentStep($draft);

        // The draft's answers are the {q:...value/.cost} context for variable
        // tokens in the step's question texts.
        $this->interpolator->setAnswers($this->wizard->answers($draft));

        $isLast = $step ? $this->wizard->isLast($draft, $step) : true;
        $answers = $this->wizard->answers($draft);

        return view('registration::register-step', [
            'step' => $step,
            'answers' => $answers,
            'evaluator' => $this->visibility,
            'def' => Currency::def(),
            'stepNumber' => $step ? $this->wizard->stepNumber($draft, $step) : 0,
            'totalSteps' => $this->wizard->totalSteps($draft),
            'isFirst' => $step ? $this->wizard->isFirst($draft, $step) : true,
            'isLast' => $isLast,
            // The final step recaps the costs (from the answers and drafted
            // guests so far) before the registrant commits — a group member
            // sees the leader/member split instead of the plain summary.
            'costSummary' => $step && $isLast && $invite === null ? $this->costs->fromAnswers($answers, $this->draftGuests->all($draft)) : null,
            'memberCostSummary' => $step && $isLast && $invite !== null ? $this->costs->fromAnswersForMember($answers, $this->draftGuests->all($draft)) : null,
            // A persistent link to the Guest List / Group Member hubs, shown
            // on every step once the registrant has answered "Yes" to the
            // corresponding trigger question — covers back/next navigation
            // that skips the automatic detour in register().
            'guestsLinkVisible' => ($answers[Question::GUEST_TRIGGER_KEY] ?? null) === Question::YES_VALUE,
            'groupMembersLinkVisible' => ($answers[Question::GROUP_TRIGGER_KEY] ?? null) === Question::YES_VALUE,
        ]);
    }

    /**
     * Process a submitted wizard step: validate it server-side, advance (or step
     * back), and on the final step commit the registration.
     *
     * The submitted "_step" is only honored if it is a currently-valid step
     * (anti-tamper); validation and cross-step visibility run server-side, so a
     * tampered client cannot skip a required field or reveal a hidden one.
     *
     * @throws \Exception
     */
    public function register(Request $request)
    {
        $invite = $this->invite($request);

        $draft = $this->wizard->draftFor($request->user());

        $step = $this->wizard->findStep($draft, (int) $request->input('_step'));
        abort_if($step === null, 422);

        if ($request->input('_direction') === 'back') {
            if ($previous = $this->wizard->previous($draft, $step)) {
                $this->wizard->setCurrent($draft, $previous);
            }

            return redirect()->route($this->routeName('register'));
        }

        // Captured before submit() folds this step's own input in, so a
        // reactive trigger question's hub only detours on the actual
        // transition to "Yes" — never on a resubmission of an already-"Yes"
        // step (see below, where that resubmission is how the wizard ever
        // finishes when the trigger has no next step at all).
        $alreadyYes = $step->isReactiveTrigger()
            && ($this->wizard->answers($draft)[$step->questions->first()->key] ?? null) === Question::YES_VALUE;

        // Validates the step server-side and saves progress to the draft; a
        // failure throws and redirects back to this step with errors and old input.
        $this->wizard->submit($draft, $step, $request->all());
        $answers = $this->wizard->answers($draft);

        // A detour, not wizard state: when a next step exists, current_question_id
        // is already advanced to it above, so the hub's own "Continue" link is a
        // plain link back to this route, landing exactly on $next with no
        // bookkeeping needed. Checked independently of whether a next step
        // exists — a reactive trigger question can just as well be the
        // wizard's very last step (e.g. nothing else is configured after the
        // seeded system-questions section's default position), and a "Yes"
        // there must still reach the hub rather than commit immediately. When
        // the trigger has no next step, current_question_id is left pointing
        // at it, so the hub's "Continue" re-shows this same (already "Yes")
        // question once more; submitting it again is the $alreadyYes case
        // above, which skips the detour and falls through to commit below —
        // that's how the wizard ever finishes in this shape.
        if ($next = $this->wizard->next($draft, $step)) {
            $this->wizard->setCurrent($draft, $next);
        }

        if (! $alreadyYes && $hub = $this->triggeredHub($step, $answers)) {
            return redirect()->route($this->routeName($hub));
        }

        if ($next) {
            return redirect()->route($this->routeName('register'));
        }

        $isGroup = $invite === null && ($answers[Question::GROUP_TRIGGER_KEY] ?? null) === Question::YES_VALUE;
        $registered = $this->commit($request->user(), $answers, $isGroup, $this->draftGuests->all($draft), $invite, $this->draftGroupMembers->all($draft));
        $this->wizard->discard($draft);
        $request->session()->forget(GroupInviteController::SESSION_KEY);

        // The committed registration triggers the configured confirmation and
        // admin-notification emails (see RegistrationMailer).
        $this->mailer->sendRegistrationEmails($registered);

        // A leader's own commit is also when their drafted group members'
        // invite emails go out (never for a member's own commit).
        if ($invite === null && $isGroup) {
            $this->mailer->sendGroupMemberInvites(
                $registered,
                GroupInvite::query()->where('group_id', $registered->group_id)->whereNull('consumed_at')->get(),
            );
        }

        // Registration is recorded (it stays pending — there is no check-out step
        // for registrants); the landing page confirms it via the flash below.
        return redirect()->route($this->routeName('info'))->with('registered', true);
    }

    /**
     * Commit the accumulated answers: attach the registrant to a group (a
     * new one they administer, or — for an invited member — the leader's
     * existing group), then store the full answer set as EAV for both
     * scopes. The wizard asks no group-scope questions while the group flow
     * is parked, so the group store is a no-op that keeps the
     * replace-on-save semantics. Each drafted guest becomes a real Guest
     * row, its own answers stored the same way; each drafted group member
     * becomes a real, still-unconsumed GroupInvite row (see register(),
     * which emails it). Returns the registered user.
     *
     * @param  list<array{id: string, type: string, answers: array}>  $guests
     * @param  list<array{id: string, answers: array}>  $groupMembers
     */
    private function commit(Model $user, array $answers, bool $isGroup, array $guests, ?GroupInvite $invite, array $groupMembers): Model
    {
        if ($invite !== null) {
            $user = $this->registration->registerUser($answers, $invite->group, $user, false);
            $invite->update(['consumed_at' => now(), 'user_id' => $user->getKey()]);
        } else {
            // is_group is recorded on the group (not an answer), so it is passed only to
            // the registration service, leaving the stored answer set untouched.
            $user = $this->registration->registerGroup($answers + ['is_group' => $isGroup], $user);
        }

        $this->answers->store(QuestionScope::Participant, $user, $answers);
        $this->answers->store(QuestionScope::Group, $user->group, $answers);

        foreach (array_values($guests) as $position => $entry) {
            $guest = Guest::create([
                'user_id' => $user->getKey(),
                'type' => $entry['type'],
                'position' => $position,
            ]);
            $this->answers->store(QuestionScope::Guest, $guest, $entry['answers']);
        }

        foreach ($groupMembers as $entry) {
            $memberInvite = GroupInvite::create([
                'group_id' => $user->group->getKey(),
                'token' => bin2hex(random_bytes(8)),
            ]);
            $this->answers->store(QuestionScope::GroupMember, $memberInvite, $entry['answers']);
        }

        return $user;
    }

    /**
     * The hub route to detour to, if $step just isolated a reactive trigger
     * question (see {@see Step::isReactiveTrigger()}) and it was answered
     * "Yes" — null otherwise, meaning no detour.
     *
     * @param  array<string, mixed>  $answers
     */
    private function triggeredHub(Step $step, array $answers): ?string
    {
        if (! $step->isReactiveTrigger()) {
            return null;
        }

        $key = $step->questions->first()->key;
        if (($answers[$key] ?? null) !== Question::YES_VALUE) {
            return null;
        }

        return match ($key) {
            Question::GUEST_TRIGGER_KEY => 'register.guests',
            Question::GROUP_TRIGGER_KEY => 'register.group_members',
        };
    }

    /**
     * The pending group invite for this browser session, if any (see
     * {@see GroupInviteController::accept()}) — an invited member never
     * answers the group-registration question (see {@see excludeKey()}) and
     * commits by joining the leader's existing group instead of creating a
     * new one. A stale or already-consumed token is forgotten so the
     * registrant falls back to the normal flow.
     */
    private function invite(Request $request): ?GroupInvite
    {
        $token = $request->session()->get(GroupInviteController::SESSION_KEY);
        if ($token === null) {
            return null;
        }

        $invite = GroupInvite::with('group')->where('token', $token)->whereNull('consumed_at')->first();
        if ($invite === null) {
            $request->session()->forget(GroupInviteController::SESSION_KEY);

            return null;
        }

        $this->wizard->excludeKey(Question::GROUP_TRIGGER_KEY);

        return $invite;
    }
}
