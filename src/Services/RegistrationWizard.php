<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Http\Controllers\MyRegistrationController;
use ConferenceTools\Registration\Http\Controllers\RegistrationController;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Support\Step;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Drives the registration form as a server-side, multi-request wizard: one
 * screen ({@see Step}) at a time, with each step posted to the backend so
 * validation and cross-step visibility are decided on the server (never
 * trusting the browser). A step is usually a whole configured section, but a
 * seeded trigger question (see {@see Question::isTriggerKey()}) is always
 * isolated into its own single-question step so it can react immediately —
 * detouring straight to its hub — without waiting for sibling questions;
 * see {@see expandSection()}.
 *
 * Progress is persisted to a {@see Draft} (one per registrant) after every step,
 * so a registrant can leave and come back later — even on another device — and
 * resume where they left off. The draft holds the accumulated, validated answers
 * and a pointer to the current step (its first question's id). The ordered list
 * of steps is recomputed from the accumulated answers on every request, so a
 * section (or question) made conditional appears or disappears based on answers
 * given on earlier steps.
 */
class RegistrationWizard
{
    /** @var array<int, string> question keys excluded from the step list entirely for the current request — see {@see excludeKey()}. */
    private array $excludedKeys = [];

    /**
     * @param  array<int, QuestionScope>  $scopes  the scopes whose sections make
     *                                             up this flow, in step order.
     *                                             Group-scope sections are excluded
     *                                             while the group flow is parked —
     *                                             group questions must not be shown
     *                                             to registrants.
     */
    public function __construct(
        private QuestionRepository $questions,
        private QuestionnaireValidator $validator,
        private VisibilityEvaluator $visibility,
        private array $scopes = [QuestionScope::Participant],
    ) {}

    /**
     * Exclude a question from the step list entirely for the rest of this
     * request — it is never rendered, never validated, and never reachable
     * even by a tampered step id. Used to keep an invited group member from
     * ever being asked the group-registration trigger question (see
     * {@see RegistrationController::invite()}).
     */
    public function excludeKey(string $key): void
    {
        $this->excludedKeys[] = $key;
    }

    /**
     * The registrant's draft — their saved progress. Returned unsaved when none
     * exists yet, so merely viewing the form creates no row; it is persisted only
     * once the registrant actually saves a step.
     */
    public function draftFor(Model $user): Draft
    {
        return Draft::firstOrNew(['user_id' => $user->getKey()], ['answers' => [], 'guests' => [], 'group_members' => []]);
    }

    /**
     * The registrant's draft for editing an already-committed registration:
     * an in-progress edit (started earlier, not yet saved as a real row)
     * resumes normally via {@see draftFor()}; otherwise a fresh draft is
     * seeded with the registrant's already-committed answers, so the wizard
     * shows their current values instead of a blank form. Guest and
     * group-member questions are excluded from this flow entirely (see
     * {@see MyRegistrationController}),
     * so their drafted lists are deliberately left empty.
     */
    public function draftForEdit(Model $user): Draft
    {
        $draft = $this->draftFor($user);

        if (! $draft->exists) {
            $draft->answers = $user->registrationAnswers()->values();
        }

        return $draft;
    }

    /** The accumulated, validated answers gathered so far (key => value). */
    public function answers(Draft $draft): array
    {
        return $draft->answers ?? [];
    }

    /**
     * The ordered, currently-visible steps for the flow, given the answers so
     * far. A section is included only when its own visibility rule passes,
     * and is split into one step per reactive trigger question plus one
     * batched step for the rest (see {@see expandSection()}).
     *
     * @return Collection<int, Step>
     */
    public function steps(Draft $draft): Collection
    {
        $answers = $this->answers($draft);

        return collect($this->scopes)
            ->flatMap(fn (QuestionScope $scope) => $this->questions->sectionsForScope($scope))
            ->filter(fn (Section $section) => $this->visibility->isVisible($section, $answers))
            ->flatMap(fn (Section $section) => $this->expandSection($section, $answers))
            ->values();
    }

    /**
     * Split a section's questions into steps: a reactive trigger question
     * (see {@see Question::isTriggerKey()}) always becomes its own
     * single-question step, in its actual configured position among its
     * siblings, so an admin controls detour order simply by reordering the
     * questions (drag-and-drop, already-existing functionality); any
     * consecutive run of ordinary questions is batched into one step exactly
     * as before. An excluded key (see {@see excludeKey()}) is dropped
     * before either grouping, so it produces no step at all.
     *
     * A batched question's own visibility is deliberately NOT checked here
     * (unchanged from the pre-split behavior, where a whole section's
     * questions rendered together regardless of individual visibility): it
     * may depend on a sibling answered later in the very same step (e.g. a
     * "preferred roommate" field conditioned on this step's own
     * accommodation choice), which isn't yet in $answers while steps are
     * being computed — same-step conditionals are toggled client-side and
     * enforced server-side by the validator once the step is actually
     * submitted. A trigger question is checked, since it is always the
     * step's only content — an invisible one should produce no step at all,
     * exactly as it would have produced no visible question in the old
     * batched rendering.
     *
     * @param  array<string, mixed>  $answers
     * @return Collection<int, Step>
     */
    private function expandSection(Section $section, array $answers): Collection
    {
        $steps = collect();
        $batch = collect();

        foreach ($section->questions as $question) {
            if (in_array($question->key, $this->excludedKeys, true)) {
                continue;
            }

            if (Question::isTriggerKey($question->key)) {
                if (! $this->visibility->isVisible($question, $answers)) {
                    continue;
                }

                if ($batch->isNotEmpty()) {
                    $steps->push(new Step($section, $batch));
                    $batch = collect();
                }
                $steps->push(new Step($section, collect([$question])));
            } else {
                $batch->push($question);
            }
        }

        if ($batch->isNotEmpty()) {
            $steps->push(new Step($section, $batch));
        }

        return $steps;
    }

    /**
     * The current step (where the registrant left off), defaulting to the first
     * step. Null when the flow has no steps at all. Pure — never writes.
     */
    public function currentStep(Draft $draft): ?Step
    {
        $steps = $this->steps($draft);
        if ($steps->isEmpty()) {
            return null;
        }

        return $steps->first(fn (Step $step) => $step->id() === $draft->current_question_id) ?? $steps->first();
    }

    /** Resolve a step by id, but only if it is a valid step right now (anti-tamper). */
    public function findStep(Draft $draft, int $id): ?Step
    {
        return $this->steps($draft)->first(fn (Step $step) => $step->id() === $id);
    }

    /** Whether this is the first wizard step. */
    public function isFirst(Draft $draft, Step $step): bool
    {
        return optional($this->steps($draft)->first())->id() === $step->id();
    }

    /** Whether this is the last wizard step. */
    public function isLast(Draft $draft, Step $step): bool
    {
        return optional($this->steps($draft)->last())->id() === $step->id();
    }

    /** The current step's position, 1-based. */
    public function stepNumber(Draft $draft, Step $step): int
    {
        return (int) $this->steps($draft)->search(fn (Step $s) => $s->id() === $step->id()) + 1;
    }

    /** How many steps the wizard has. */
    public function totalSteps(Draft $draft): int
    {
        return $this->steps($draft)->count();
    }

    /** The next visible step after $step, or null if it is the last. */
    public function next(Draft $draft, Step $step): ?Step
    {
        $steps = $this->steps($draft);
        $index = $steps->search(fn (Step $s) => $s->id() === $step->id());

        return $index === false ? null : $steps->get($index + 1);
    }

    /** The previous visible step before $step, or null if it is the first. */
    public function previous(Draft $draft, Step $step): ?Step
    {
        $steps = $this->steps($draft);
        $index = $steps->search(fn (Step $s) => $s->id() === $step->id());

        return $index === false || $index === 0 ? null : $steps->get($index - 1);
    }

    /** Remember the step the registrant is on, persisting the draft. */
    public function setCurrent(Draft $draft, Step $step): void
    {
        $draft->current_question_id = $step->id();
        $draft->save();
    }

    /**
     * Validate a submitted step server-side, fold its answers into the draft and
     * persist it. Visibility is evaluated with the answers from earlier steps as
     * context, so cross-step conditional fields are enforced here.
     *
     * @return array<string, mixed> the validated answers for this step
     *
     * @throws ValidationException
     */
    public function submit(Draft $draft, Step $step, array $input): array
    {
        $validated = $this->validator->validateStep($step, $input, $this->answers($draft));

        $draft->answers = array_merge($this->answers($draft), $validated);
        $draft->save();

        return $validated;
    }

    /** Discard the draft (after a successful commit, or to restart). */
    public function discard(Draft $draft): void
    {
        if ($draft->exists) {
            $draft->delete();
        }
    }
}
