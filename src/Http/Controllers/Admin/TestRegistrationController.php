<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Services\CostSummaryBuilder;
use ConferenceTools\Registration\Services\DraftGuests;
use ConferenceTools\Registration\Services\RegistrationMailer;
use ConferenceTools\Registration\Services\RegistrationWizard;
use ConferenceTools\Registration\Services\VariableInterpolator;
use ConferenceTools\Registration\Services\VisibilityEvaluator;
use ConferenceTools\Registration\Support\Step;
use ConferenceTools\Registration\Support\TestDraft;
use Illuminate\Http\Request;

/**
 * The admin test drive of the registration process: the same wizard, views,
 * validation and emails as the real flow (see RegistrationController), but
 * nothing is recorded — progress lives only in the session ({@see TestDraft}),
 * no group or answers are committed, and both completion emails go to the
 * signed-in admin instead of the registrant/administrator addresses. The routes
 * sit outside EnsureRegistrationIsOpen on purpose: since a test run records
 * nothing, the registration window need not (and must not) gate it.
 */
class TestRegistrationController extends Controller
{
    public function __construct(
        protected RegistrationWizard $wizard,
        protected VisibilityEvaluator $visibility,
        protected RegistrationMailer $mailer,
        protected VariableInterpolator $interpolator,
        protected CostSummaryBuilder $costs,
        protected DraftGuests $draftGuests,
    ) {}

    /** Show the current test-run step; mirrors RegistrationController::showForm(). */
    public function showForm()
    {
        $draft = TestDraft::fromSession();
        $step = $this->wizard->currentStep($draft);

        $answers = $this->wizard->answers($draft);
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
            'costSummary' => $step && $isLast ? $this->costs->fromAnswers($answers, $this->draftGuests->all($draft)) : null,
            'memberCostSummary' => null,
            'guestsLinkVisible' => ($answers[Question::GUEST_TRIGGER_KEY] ?? null) === Question::YES_VALUE,
            'guestsUrl' => route($this->routeName('admin.test.guests')),
            'groupMembersLinkVisible' => ($answers[Question::GROUP_TRIGGER_KEY] ?? null) === Question::YES_VALUE,
            'groupMembersUrl' => route($this->routeName('admin.test.group_members')),
            'formAction' => route($this->routeName('admin.test.store')),
        ] + $this->testMode($step?->section->id));
    }

    /**
     * Process a submitted test step exactly as the real flow would, except the
     * final step commits nothing: the run is discarded and both registration
     * emails are sent to the signed-in admin for review.
     */
    public function register(Request $request)
    {
        $draft = TestDraft::fromSession();

        $step = $this->wizard->findStep($draft, (int) $request->input('_step'));
        abort_if($step === null, 422);

        if ($request->input('_direction') === 'back') {
            if ($previous = $this->wizard->previous($draft, $step)) {
                $this->wizard->setCurrent($draft, $previous);
            }

            return redirect()->route($this->routeName('admin.test'));
        }

        // See RegistrationController::register()'s doc-comment for why this
        // is captured before submit() and used to skip a resubmission's detour.
        $alreadyYes = $step->isReactiveTrigger()
            && ($this->wizard->answers($draft)[$step->questions->first()->key] ?? null) === Question::YES_VALUE;

        $this->wizard->submit($draft, $step, $request->all());
        $answers = $this->wizard->answers($draft);

        // Mirrors RegistrationController::register()'s hub detour, checked
        // independently of whether a next step exists — see its doc-comment
        // for why. Nothing is recorded either way in a test run; only the
        // destination differs.
        if ($next = $this->wizard->next($draft, $step)) {
            $this->wizard->setCurrent($draft, $next);
        }

        if (! $alreadyYes && $hub = $this->triggeredHub($step, $answers)) {
            return redirect()->route($this->routeName($hub));
        }

        if ($next) {
            return redirect()->route($this->routeName('admin.test'));
        }

        $this->mailer->sendTestRegistrationEmails($request->user(), $answers);
        $this->wizard->discard($draft);

        return redirect()->route($this->routeName('admin.dashboard'))
            ->with('test_status', __('registration::admin.test_completed', ['email' => $request->user()->email]));
    }

    /**
     * The test-drive hub route to detour to, if $step just isolated a
     * reactive trigger question and it was answered "Yes" — mirrors
     * RegistrationController::triggeredHub() (null means no detour).
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
            Question::GUEST_TRIGGER_KEY => 'admin.test.guests',
            Question::GROUP_TRIGGER_KEY => 'admin.test.group_members',
        };
    }

    /**
     * Marks the shared wizard views as a test run: the banner shows, with an
     * exit back to the Questions console — anchored (a plain HTML #fragment,
     * so no scripted scrolling) to the section being tested when on a step.
     */
    private function testMode(?int $sectionId): array
    {
        return [
            'testing' => true,
            'exitUrl' => route($this->routeName('admin.questions')).($sectionId ? '#section-'.$sectionId : ''),
        ];
    }
}
