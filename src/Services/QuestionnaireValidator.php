<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Enums\ConditionSubject;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\DiscountCode;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Support\Step;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Turns the configured questions for a scope into Laravel validation rules and
 * validates submitted input against them. Rules are built only for the questions
 * that are visible given the current input, so a hidden question (one whose
 * visibility rule fails) is never required and never validated — this is how the
 * "Other, please specify" style follow-up fields stay optional until shown.
 */
class QuestionnaireValidator
{
    public function __construct(
        private QuestionRepository $questions,
        private VisibilityEvaluator $visibility,
    ) {}

    /**
     * Validation rules for the scope's currently-visible questions, keyed by
     * question key.
     *
     * @param  array<string, mixed>  $context  extra visibility-only facts merged
     *                                         alongside the input's own answers —
     *                                         e.g. a guest's own type, which isn't
     *                                         a question/answer at all (see
     *                                         {@see ConditionSubject})
     * @return array<string, mixed>
     */
    public function rules(QuestionScope $scope, array $input, array $context = []): array
    {
        $questions = $this->questions->questionsForScope($scope);
        $answers = array_merge($context, $this->answerMap($questions, $input));

        $rules = [];
        foreach ($questions as $question) {
            if (! $this->visibility->isVisible($question, $answers)) {
                continue;
            }

            foreach ($this->rulesFor($question, $answers) as $field => $fieldRules) {
                $rules[$field] = $fieldRules;
            }
        }

        return $rules;
    }

    /**
     * Validate raw form input against the scope's visible questions.
     *
     * Raw input is canonicalized first (see {@see QuestionRepository::normalizeInput()}),
     * so the returned, validated answers are keyed by question key — ready to hand
     * straight to {@see AnswerStore}. $context (see {@see rules()}) affects only
     * which questions are visible/required, never the validated output.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed> the validated answers, keyed by question key
     *
     * @throws ValidationException
     */
    public function validate(QuestionScope $scope, array $input, array $context = []): array
    {
        $input = $this->questions->normalizeInput($scope, $input);

        return Validator::make($input, $this->rules($scope, $input, $context))->validate();
    }

    /**
     * Validate a single wizard step server-side.
     *
     * Only the step's own visible questions are validated (usually a whole
     * section's worth, but a reactive trigger question is isolated to just
     * itself — see {@see Step}), but visibility is evaluated against the
     * accumulated answers from earlier steps ($context) merged with this
     * step's input — so a cross-step conditional question is required (or
     * skipped) according to answers the browser never sees, and tampering
     * with the client cannot get a hidden field accepted or a required one
     * skipped.
     *
     * @param  array<string, mixed>  $context  accumulated answers, keyed by question key
     * @return array<string, mixed> the validated answers for this step
     *
     * @throws ValidationException
     */
    public function validateStep(Step $step, array $rawInput, array $context = []): array
    {
        $input = $this->questions->normalizeInput($step->section->scope, $rawInput);
        $answers = array_merge($context, $input);

        $rules = [];
        foreach ($step->questions as $question) {
            if (! $question->enabled || ! $this->visibility->isVisible($question, $answers)) {
                continue;
            }

            foreach ($this->rulesFor($question, $answers) as $field => $fieldRules) {
                $rules[$field] = $fieldRules;
            }
        }

        return Validator::make($input, $rules)->validate();
    }

    /**
     * Rules for one question. Returns one or more field => rules pairs (a
     * checkbox/multi question contributes both the array field and its members).
     *
     * Only the options visible for the current answers are accepted, so a value
     * belonging solely to a conditionally-offered option cannot be submitted by
     * an audience it was never offered to. A choice question whose options all
     * fail their rules offers nothing to answer and contributes no rules at all
     * (it is not rendered either).
     *
     * @param  array<string, mixed>  $answers  accumulated answers, for option visibility
     * @return array<string, array<int, mixed>>
     */
    private function rulesFor(Question $question, array $answers): array
    {
        if ($question->usesOptions()
            && $question->options->isNotEmpty()
            && $this->optionValues($question, $answers) === []) {
            return [];
        }

        $presence = $question->required ? 'required' : 'nullable';
        $config = $question->config ?? [];

        if ($question->type === QuestionType::Checkbox) {
            return [
                $question->key => [$presence, 'array'],
                $question->key.'.*' => [Rule::in($this->optionValues($question, $answers))],
            ];
        }

        $rules = [$presence, ...$this->typeRules($question, $config, $answers)];

        return [$question->key => $rules];
    }

    /** Type-specific rules (the presence rule is added by the caller). */
    private function typeRules(Question $question, array $config, array $answers): array
    {
        return match ($question->type) {
            QuestionType::Email => ['string', 'email', 'max:'.($config['max'] ?? 255)],
            QuestionType::Url => ['string', 'url', 'max:'.($config['max'] ?? 255)],
            QuestionType::Number => array_values(array_filter([
                'numeric',
                isset($config['min']) ? 'min:'.$config['min'] : null,
                isset($config['max']) ? 'max:'.$config['max'] : null,
            ])),
            QuestionType::Date => ['date'],
            QuestionType::Textarea => ['string', 'max:'.($config['max'] ?? 5000)],
            QuestionType::Select, QuestionType::Radio, QuestionType::YesNo => ['string', Rule::in($this->optionValues($question, $answers))],
            QuestionType::DiscountCode => ['string', 'max:'.($config['max'] ?? 255), $this->discountCodeRule()],
            default => ['string', 'max:'.($config['max'] ?? 255)],
        };
    }

    /**
     * A discount-code value must match a configured, enabled code — matched
     * case-insensitively and ignoring surrounding whitespace. Blank values never
     * reach the closure (Laravel skips non-implicit rules for them). The failure
     * message is intentionally generic so it never reveals which codes exist.
     */
    private function discountCodeRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (DiscountCode::lookup((string) $value) === null) {
                $fail(__('registration::admin.invalid_discount'));
            }
        };
    }

    /** @return array<int, string> the values of the options visible for these answers */
    private function optionValues(Question $question, array $answers): array
    {
        return $this->visibility->visibleOptions($question, $answers)->pluck('value')->map('strval')->all();
    }

    /**
     * Map of question key => submitted value, used to evaluate visibility. Absent
     * keys map to null so conditions on unanswered questions evaluate correctly.
     *
     * @param  Collection<int, Question>  $questions
     * @return array<string, mixed>
     */
    private function answerMap(Collection $questions, array $input): array
    {
        $map = [];
        foreach ($questions as $question) {
            $map[$question->key] = $input[$question->key] ?? null;
        }

        return $map;
    }
}
