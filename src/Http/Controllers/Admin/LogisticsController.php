<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Services\ShuttlePlanner;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * The admin "Logistics" hub: links to the interactive consoles (badges, room
 * assignments, shuttle schedule, Prayer Pals), plus the settings that feed
 * them — which configured questions carry each derived fact (see
 * ReportQuestions for registrants, GuestQuestions for their non-attending
 * guests' own facts), which answer values count as opt-in/opt-outs, and the
 * shuttle fleet's seats, count, and airport travel time (see ShuttlePlanner).
 * The minors-get-badges toggle lives here too, alongside every other
 * question nomination. The Guest List hub's own trigger is not a nomination
 * — it's the fixed, seeded {@see Question::GUEST_TRIGGER_KEY}
 * question, edited on the Questions page like any other question.
 *
 * Each question-nominating field may carry one or more paired "matching
 * answer" fields (see ReportQuestions::VALUE_QUESTION_KEYS) — rendered right
 * under it, disabled until the question is chosen, and widgeted to the
 * question's type: a droplist for Radio, a move-between list for other
 * options-based types, plain text otherwise.
 */
class LogisticsController extends Controller
{
    /** The Logistics console: question nominations, matching values, and guest options. */
    public function index(ReportQuestions $questions, GuestQuestions $guestQuestions, ShuttlePlanner $planner)
    {
        $questionsByKey = Question::with('options')->orderBy('key')->get()->keyBy('key');

        return view('registration::admin.logistics.index', [
            'planner' => $planner,
            'guestQuestions' => $guestQuestions,
            'questionChoices' => $this->questionChoices(QuestionScope::Participant),
            'guestQuestionChoices' => $this->questionChoices(QuestionScope::Guest),
            'questionMeta' => $questionsByKey->map(fn (Question $q): array => [
                'kind' => $this->widgetFor($q),
                'options' => $q->usesOptions() ? $q->options->pluck('value')->all() : [],
            ]),
            'pages' => $this->pages(),
            'questionFields' => $this->questionFields(
                $questions,
                $questionsByKey,
                ReportQuestions::QUESTION_KEYS,
                ReportQuestions::VALUE_QUESTION_KEYS,
                ReportQuestions::VALUE_DEFAULTS,
            ),
            'guestQuestionFields' => $this->questionFields(
                $guestQuestions,
                $questionsByKey,
                GuestQuestions::REPORT_QUESTION_KEYS,
                GuestQuestions::REPORT_VALUE_QUESTION_KEYS,
                GuestQuestions::REPORT_VALUE_DEFAULTS,
            ),
        ]);
    }

    /**
     * Build the generic field descriptors {@see resources/views/admin/logistics/index.blade.php}
     * renders — shared by ReportQuestions (registrants) and GuestQuestions
     * (non-attending guests), since both expose the same
     * questionKey()/rawValueList()/staleValues() shape.
     *
     * @param  ReportQuestions|GuestQuestions  $questions
     * @param  Collection<string, Question>  $questionsByKey
     * @param  array<int, string>  $questionKeys
     * @param  array<string, string>  $valueQuestionKeys
     * @param  array<string, string>  $valueDefaults
     */
    private function questionFields($questions, Collection $questionsByKey, array $questionKeys, array $valueQuestionKeys, array $valueDefaults): Collection
    {
        // Labels/hints are resolved here (keys derived from the setting names)
        // so the views reference only static translation keys.
        $field = fn (string $setting): array => [
            'name' => $setting,
            'label' => __('registration::admin.setting_'.$setting),
            'hint' => __('registration::admin.setting_'.$setting.'_hint'),
        ];

        $valueField = function (string $setting, string $questionSetting) use ($field, $questions, $questionsByKey, $valueDefaults): array {
            $question = $questionsByKey->get($questions->questionKey($questionSetting));

            return $field($setting) + [
                'questionSetting' => $questionSetting,
                'value' => Setting::get($setting),
                'default' => $valueDefaults[$setting],
                'selected' => $questions->rawValueList($setting),
                'optionValues' => $question?->usesOptions() ? $question->options->pluck('value')->all() : null,
                'widget' => $this->widgetFor($question),
                'stale' => $questions->staleValues($setting) !== [],
            ];
        };

        $valueFieldsFor = fn (string $questionSetting): Collection => collect($valueQuestionKeys)
            ->filter(fn (string $paired): bool => $paired === $questionSetting)
            ->keys()
            ->map(fn (string $valueSetting): array => $valueField($valueSetting, $questionSetting))
            ->values();

        return collect($questionKeys)
            ->map(fn (string $setting): array => $field($setting) + [
                'current' => $questions->questionKey($setting),
                'valueFields' => $valueFieldsFor($setting),
            ]);
    }

    /** Which matching-answer widget a question's type calls for. */
    private function widgetFor(?Question $question): string
    {
        return match (true) {
            $question === null => 'text',
            $question->type === QuestionType::Radio => 'radio',
            $question->usesOptions() => 'arrows',
            default => 'text',
        };
    }

    /** Save the nominations and matching-value settings. */
    public function update(Request $request, ReportQuestions $questions, GuestQuestions $guestQuestions, ShuttlePlanner $planner)
    {
        $questionRule = ['nullable', 'string', Rule::in($this->questionChoices(QuestionScope::Participant)->keys())];
        $guestQuestionRule = ['nullable', 'string', Rule::in($this->questionChoices(QuestionScope::Guest)->keys())];

        $validator = Validator::make($request->all(), [
            ...array_fill_keys(ReportQuestions::QUESTION_KEYS, $questionRule),
            ...array_fill_keys(array_keys(ReportQuestions::VALUE_DEFAULTS), ['nullable', 'string', 'max:255']),
            ...array_fill_keys(GuestQuestions::REPORT_QUESTION_KEYS, $guestQuestionRule),
            ...array_fill_keys(array_keys(GuestQuestions::REPORT_VALUE_DEFAULTS), ['nullable', 'string', 'max:255']),
            'guest_minors_get_badges' => ['boolean'],
            'shuttle_seats' => ['nullable', 'integer', 'min:1'],
            'shuttle_travel_minutes' => ['nullable', 'integer', 'min:1'],
            'shuttle_count' => ['nullable', 'integer', 'min:1'],
        ]);

        $validator->after(fn ($validator) => $this->rejectStaleMatches($validator, $request, ReportQuestions::VALUE_QUESTION_KEYS));
        $validator->after(fn ($validator) => $this->rejectStaleMatches($validator, $request, GuestQuestions::REPORT_VALUE_QUESTION_KEYS));

        $data = $validator->validate();

        $questions->update($data);
        $guestQuestions->update($data);
        $guestQuestions->updateMinorsGetBadges($request->boolean('guest_minors_get_badges'));
        $planner->updateSettings(
            isset($data['shuttle_seats']) ? (int) $data['shuttle_seats'] : null,
            isset($data['shuttle_travel_minutes']) ? (int) $data['shuttle_travel_minutes'] : null,
            isset($data['shuttle_count']) ? (int) $data['shuttle_count'] : null,
        );

        return redirect()
            ->route($this->routeName('admin.logistics'))
            ->with('logistics_status', __('registration::admin.logistics_settings_saved'));
    }

    /**
     * Refuse to save a matching-answer setting whose submitted value(s) don't
     * match a current option of its (possibly newly chosen) question — the
     * admin must fix or clear it in the same submission.
     *
     * @param  array<string, string>  $valueQuestionKeys
     */
    private function rejectStaleMatches(ValidatorInstance $validator, Request $request, array $valueQuestionKeys): void
    {
        $questionsByKey = Question::with('options')->orderBy('key')->get()->keyBy('key');

        foreach ($valueQuestionKeys as $valueSetting => $questionSetting) {
            $question = $questionsByKey->get($request->input($questionSetting));
            if ($question === null || ! $question->usesOptions()) {
                continue;
            }

            $optionValues = $question->options->map(fn ($option): string => mb_strtolower($option->value))->all();

            $submitted = collect(explode(',', (string) $request->input($valueSetting)))
                ->map(fn (string $value): string => trim($value))
                ->filter(fn (string $value): bool => $value !== '');

            if ($submitted->contains(fn (string $value): bool => ! in_array(mb_strtolower($value), $optionValues, true))) {
                $validator->errors()->add($valueSetting, __('registration::admin.report_answer_invalid'));
            }
        }
    }

    /** Every configured question of the given scope an admin can nominate, key => key (only the key is ever shown). */
    private function questionChoices(QuestionScope $scope): Collection
    {
        return Question::whereHas('section', fn ($query) => $query->where('scope', $scope->value))
            ->orderBy('key')
            ->pluck('key', 'key');
    }

    /** The console pages the hub links, each with its resolved label and blurb. */
    private function pages(): array
    {
        $pages = [
            'badges' => 'admin.logistics.badges',
            'assignments' => 'admin.rooms.assignments',
            'shuttles' => 'admin.logistics.shuttles',
            'prayer_pals' => 'admin.logistics.prayer_pals',
        ];

        $links = [];
        foreach ($pages as $key => $route) {
            $links[] = [
                'label' => __('registration::admin.report_'.$key),
                'description' => __('registration::admin.report_'.$key.'_desc'),
                'url' => route($this->routeName($route)),
            ];
        }

        return $links;
    }
}
