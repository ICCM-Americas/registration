<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\QuestionOption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Persists validated answers for one owner as EAV rows. The owner is the host
 * user for participant-scoped questions, or a Group for group-scoped ones.
 *
 * Storage is replace-on-save: the owner's existing answers for the scope's
 * questions are cleared and rewritten from the supplied data, so re-saving a form
 * is idempotent. A multi-value question records one row per selected option; a
 * question that is hidden or unanswered for this submission stores nothing.
 *
 * A choice answer resolves its matched option ({@see VisibilityEvaluator::optionFor()})
 * and snapshots its cost/per_diem_days/per_diem_scope onto the Answer row —
 * there is no option_id, so this snapshot is the only link back to pricing
 * (later edits to, or deletion of, the option never change what was already
 * recorded). The text stored is either the raw selected value (the default),
 * or — when the question's translate_value flag is on and an option matched —
 * that option's own translated "value" field resolved for the current app
 * locale; either way it is then run through {@see VariableInterpolator::interpolate()}.
 * A chosen option's value may itself be a template (e.g. an option offering
 * "{q:name1.value} {q:name2.value}" as a computed badge name) — the option is
 * matched against the *raw* submitted value (that's what the form validated
 * against), but the value actually recorded is interpolated first, so the
 * stored answer is the resolved text, not the template. Interpolation only
 * substitutes text; the insert below still goes through Eloquent's parameter
 * binding, so no manual SQL escaping is needed.
 *
 * A free-text (non-option) answer is trimmed before storing; it is never
 * interpolated or translated.
 */
class AnswerStore
{
    public function __construct(
        private QuestionRepository $questions,
        private VisibilityEvaluator $visibility,
        private VariableInterpolator $interpolator,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated answers, keyed by question key
     */
    public function store(QuestionScope $scope, Model $owner, array $data): void
    {
        $questions = $this->questions->questionsForScope($scope);

        // Lets {q:key.value} tokens in a chosen option's value resolve against
        // this owner's own answers.
        $this->interpolator->setAnswers($data);

        DB::transaction(function () use ($questions, $owner, $data) {
            foreach ($questions as $question) {
                $this->clear($question, $owner);

                if (! $this->visibility->isVisible($question, $data)) {
                    continue;
                }

                $this->writeAnswer($question, $owner, $data[$question->key] ?? null, $data);
            }
        });

        $this->interpolator->setAnswers(null);

        // Drop any answers the owner memoized before this write.
        if (method_exists($owner, 'refreshRegistrationAnswers')) {
            $owner->refreshRegistrationAnswers();
        }
    }

    /** Remove the owner's stored answer rows for a question. */
    private function clear(Question $question, Model $owner): void
    {
        $question->answers()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $answers  the full answer set, to resolve which
     *                                         of several same-valued option variants was offered
     */
    private function writeAnswer(Question $question, Model $owner, mixed $value, array $answers): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        if ($question->usesOptions()) {
            foreach ((array) $value as $selected) {
                $option = $this->visibility->optionFor($question, (string) $selected, $answers);

                $text = ($question->translate_value && $option !== null)
                    ? (string) $option->translate('value')
                    : (string) $selected;

                $interpolated = $this->interpolator->interpolate($text);
                $this->record($question, $owner, $interpolated, $option);
            }

            return;
        }

        $this->record($question, $owner, trim((string) $value));
    }

    /** Write one answer row for the owner, snapshotting option details. */
    private function record(Question $question, Model $owner, mixed $value, ?QuestionOption $option = null): void
    {
        $question->answers()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'value' => is_scalar($value) ? (string) $value : json_encode($value),
            'cost' => $option?->cost,
            'per_diem_days' => $option?->per_diem_days,
            'per_diem_scope' => $option?->per_diem_scope,
        ]);
    }
}
