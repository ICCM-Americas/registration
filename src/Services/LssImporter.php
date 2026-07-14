<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Models\Translation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use SimpleXMLElement;

/**
 * Imports a LimeSurvey structure export (.lss XML) into the configurable question
 * infrastructure: survey groups become {@see Section}s, questions become
 * {@see Question}s, their answers/subquestions become options, and LimeSurvey
 * conditions become visibility rules. As much of the survey as this package can
 * model is brought across; everything that has no equivalent here (array/matrix
 * questions, equations, sliders, ranking, file upload, regex conditions …) is
 * skipped and recorded in the returned report rather than guessed at. LimeSurvey
 * answer references in texts ({Code.value}, {Code.shown}) are rewritten to this
 * package's {q:key.value} tokens (see {@see rewriteAnswerTokens()}).
 *
 * The .lss format changed across LimeSurvey versions: 2.x kept question/answer
 * text on the main table rows (duplicated per language in a multilingual
 * survey), while 3.x+ moved it to per-language *_l10ns tables. Both layouts are
 * handled — text is read from the l10n table when one is present and falls back
 * to the main row otherwise.
 *
 * Multilingual surveys import fully: the survey's base language (the surveys
 * row's "language") lands in the section/question/option columns themselves,
 * and every other language's group/question/answer text is stored as a
 * {@see Translation} row, exactly as if an
 * admin had entered it on the translations screen. Rendering then resolves the
 * registrant's locale and falls back to the base text when a translation is
 * missing (see TranslatesFields) — so a single-language survey simply shows its
 * one language everywhere, whatever language that is.
 *
 * All scopes import as {@see QuestionScope::Participant}: a .lss has no notion of
 * the package's participant-vs-group split, and no option pricing, so priced
 * options are NOT derived from the survey (set option costs in the form builder
 * afterwards). Re-running is idempotent: sections and questions are keyed by a
 * stable machine name (the survey's group/question code) and updated in place.
 */
class LssImporter
{
    /**
     * LimeSurvey question type code => the package question type it maps to.
     * Choice types draw options from answers (L, !, O, 5, G, Y) or subquestions
     * (M, P); free-text/number/date map straight across. Anything not listed
     * here has no equivalent and is skipped.
     */
    private const TYPE_MAP = [
        'S' => QuestionType::Text,      // short free text
        'T' => QuestionType::Textarea,  // long free text
        'U' => QuestionType::Textarea,  // huge free text
        'N' => QuestionType::Number,    // numerical input
        'D' => QuestionType::Date,      // date / time
        'L' => QuestionType::Radio,     // list (radio)
        'O' => QuestionType::Radio,     // list with comment (comment dropped)
        '!' => QuestionType::Select,    // list (dropdown)
        '5' => QuestionType::Radio,     // 5 point choice
        'G' => QuestionType::Radio,     // gender
        'Y' => QuestionType::Radio,     // yes / no (fixed Yes/No options)
        'M' => QuestionType::Checkbox,  // multiple choice
        'P' => QuestionType::Checkbox,  // multiple choice with comments (comments dropped)
    ];

    /** LimeSurvey question type code => why it cannot be imported. */
    private const UNSUPPORTED_TYPES = [
        'F' => 'array', 'A' => 'array (5 point)', 'B' => 'array (10 point)',
        'C' => 'array (yes/no/uncertain)', 'E' => 'array (increase/same/decrease)',
        'H' => 'array by column', '1' => 'array dual scale', ':' => 'array (numbers)',
        ';' => 'array (texts)', 'K' => 'multiple numerical', 'Q' => 'multiple short text',
        'R' => 'ranking', '|' => 'file upload', '*' => 'equation',
        'I' => 'language switch', 'X' => 'text display (boilerplate)',
    ];

    /** LimeSurvey condition comparison method => package condition operator. */
    private const METHOD_MAP = [
        '==' => ConditionOperator::Equals,
        '=' => ConditionOperator::Equals,
        '!=' => ConditionOperator::NotEquals,
        '<' => ConditionOperator::LessThan,
        '>' => ConditionOperator::GreaterThan,
    ];

    /** @var array<int, string> Human-readable notes about what could not be imported. */
    private array $skipped = [];

    /** @var array<string, int> Counts of what was created, for the summary. */
    private array $counts = ['sections' => 0, 'questions' => 0, 'options' => 0, 'conditions' => 0, 'translations' => 0];

    /** @var array<int, Question> Map of LimeSurvey qid => the imported Question. */
    private array $byQid = [];

    /** @var array<string, string> Lowercased LimeSurvey question code => the key it imports under. */
    private array $codeKeys = [];

    /** @var array<int, true> qids of yes/no (Y) questions, whose condition values need Y/N => true/false. */
    private array $yesNoQids = [];

    private string $baseLanguage = '';

    /**
     * Main-table rows deduplicated to one per entity, plus the non-base-language
     * rows a LimeSurvey 2.x multilingual export duplicates inline (see
     * {@see dedupeByLanguage()}). Answers are keyed by "qid|code".
     *
     * @var array<int, array<string, string>>
     */
    private array $groupRows = [];

    /** @var array<string, array<string, array<string, string>>> id => language => row */
    private array $groupInline = [];

    /** @var array<int, array<string, string>> */
    private array $questionRows = [];

    /** @var array<string, array<string, array<string, string>>> */
    private array $questionInline = [];

    /** @var array<int, array<string, string>> */
    private array $answerRows = [];

    /** @var array<string, array<string, array<string, string>>> */
    private array $answerInline = [];

    /**
     * Parse and import the given .lss XML, returning a report of what happened.
     *
     * @return array{counts: array<string, int>, skipped: array<int, string>, survey: ?string}
     */
    public function import(string $xml): array
    {
        $this->skipped = [];
        $this->counts = ['sections' => 0, 'questions' => 0, 'options' => 0, 'conditions' => 0, 'translations' => 0];
        $this->byQid = [];
        $this->yesNoQids = [];

        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA);
        if ($doc === false) {
            throw new \InvalidArgumentException('The .lss file is not valid XML.');
        }

        $this->baseLanguage = $this->rows($doc, 'surveys')[0]['language'] ?? '';

        // Collapse the LimeSurvey 2.x per-language duplication of the main rows
        // (subquestions may also live in their own block in LS6 exports; merge
        // them so the rest of the import sees one set of question rows).
        [$this->groupRows, $this->groupInline] = $this->dedupeByLanguage(
            $this->rows($doc, 'groups'),
            fn (array $row) => $row['gid'] ?? null,
        );
        [$this->questionRows, $this->questionInline] = $this->dedupeByLanguage(
            array_merge($this->rows($doc, 'questions'), $this->rows($doc, 'subquestions')),
            fn (array $row) => $row['qid'] ?? null,
        );
        [$this->answerRows, $this->answerInline] = $this->dedupeByLanguage(
            $this->rows($doc, 'answers'),
            fn (array $row) => isset($row['qid'], $row['code']) ? $row['qid'].'|'.$row['code'] : null,
        );

        // Known before anything is written, so answer references in any text —
        // including forward references — can be rewritten as it is imported.
        $this->codeKeys = $this->questionCodeKeys();

        $survey = $this->surveyTitle($doc);
        $this->importGroups($doc);
        $this->importConditions($doc);

        return ['counts' => $this->counts, 'skipped' => $this->skipped, 'survey' => $survey];
    }

    /** Each group becomes a participant-scope section; its questions are imported in order. */
    private function importGroups(SimpleXMLElement $doc): void
    {
        $groupText = $this->l10n($doc, 'group_l10ns', 'gid');
        $questionsByGroup = $this->groupBy($this->questionRows, 'gid');

        $groups = $this->groupRows;
        usort($groups, fn ($a, $b) => (int) ($a['group_order'] ?? 0) <=> (int) ($b['group_order'] ?? 0));

        foreach (array_values($groups) as $position => $group) {
            $gid = $group['gid'] ?? null;
            if ($gid === null) {
                continue;
            }

            $byLanguage = $groupText[$gid] ?? [];
            $l10n = $this->baseText($byLanguage);
            $title = $this->text($l10n['group_name'] ?? $group['group_name'] ?? "Group {$gid}");
            $key = $this->slug($title, "group-{$gid}");

            $section = Section::updateOrCreate(
                ['scope' => QuestionScope::Participant->value, 'key' => $key],
                [
                    'title' => $title,
                    'description' => $this->text($l10n['description'] ?? $group['description'] ?? '') ?: null,
                    'position' => $position,
                    'enabled' => true,
                ],
            );
            $this->counts['sections']++;

            $sources = [$byLanguage, $this->groupInline[$gid] ?? []];
            $this->writeTranslations($section, 'title', $this->languageTexts($sources, 'group_name'));
            $this->writeTranslations($section, 'description', $this->languageTexts($sources, 'description'));

            $this->importQuestions($doc, $section, $questionsByGroup[$gid] ?? []);
        }
    }

    /** @param array<int, array<string, string>> $questions all rows (incl. subquestions) for one group. */
    private function importQuestions(SimpleXMLElement $doc, Section $section, array $questions): void
    {
        $questionText = $this->l10n($doc, 'question_l10ns', 'qid');
        // Subquestions are ordinary rows with parent_qid set to their parent's qid.
        $subByParent = $this->groupBy(
            array_filter($questions, fn ($q) => (int) ($q['parent_qid'] ?? 0) !== 0),
            'parent_qid',
        );

        $top = array_filter($questions, fn ($q) => (int) ($q['parent_qid'] ?? 0) === 0);
        usort($top, fn ($a, $b) => (int) ($a['question_order'] ?? 0) <=> (int) ($b['question_order'] ?? 0));

        foreach (array_values($top) as $position => $row) {
            $this->importQuestion($doc, $section, $row, $position, $questionText, $subByParent);
        }
    }

    /**
     * @param  array<int, array<string, string>>  $questionText  qid => l10n fields
     * @param  array<string, array<int, array<string, string>>>  $subByParent  parent qid => subquestion rows
     */
    private function importQuestion(
        SimpleXMLElement $doc,
        Section $section,
        array $row,
        int $position,
        array $questionText,
        array $subByParent,
    ): void {
        $qid = $row['qid'] ?? null;
        $lsType = $row['type'] ?? '';
        $code = $row['title'] ?? "q{$qid}";

        $type = self::TYPE_MAP[$lsType] ?? null;
        if ($type === null) {
            $reason = self::UNSUPPORTED_TYPES[$lsType] ?? "unknown type '{$lsType}'";
            $this->skipped[] = "Question '{$code}' skipped: {$reason} is not supported.";

            return;
        }

        $byLanguage = $questionText[$qid] ?? [];
        $l10n = $this->baseText($byLanguage);
        $label = $this->text($l10n['question'] ?? $row['question'] ?? $code) ?: $code;
        $help = $this->text($l10n['help'] ?? $row['help'] ?? '');

        $question = Question::updateOrCreate(
            ['section_id' => $section->id, 'key' => $this->slug($code, "q{$qid}")],
            [
                'type' => $type->value,
                'label' => $label,
                'help_text' => $help ?: null,
                'placeholder' => null,
                'required' => ($row['mandatory'] ?? 'N') === 'Y',
                'position' => $position,
                'config' => null,
                'enabled' => true,
            ],
        );
        $this->counts['questions']++;
        if ($qid !== null) {
            $this->byQid[(int) $qid] = $question;
            if ($lsType === 'Y') {
                $this->yesNoQids[(int) $qid] = true;
            }
        }

        $sources = [$byLanguage, $this->questionInline[$qid] ?? []];
        $this->writeTranslations($question, 'label', $this->languageTexts($sources, 'question'));
        $this->writeTranslations($question, 'help_text', $this->languageTexts($sources, 'help'));

        if ($type->usesOptions()) {
            $this->importOptions($doc, $question, $row, $lsType, $subByParent[$qid] ?? []);
        }
    }

    /**
     * Build a choice question's options from the survey, appending an "Other"
     * option when the LimeSurvey question allowed one, and reconcile them onto
     * the question.
     *
     * @param  array<int, array<string, string>>  $subquestions
     */
    private function importOptions(
        SimpleXMLElement $doc,
        Question $question,
        array $row,
        string $lsType,
        array $subquestions,
    ): void {
        $options = $this->optionSource($doc, $row['qid'] ?? null, $lsType, $subquestions);

        if (($row['other'] ?? 'N') === 'Y' && in_array($lsType, ['L', '!', 'M', 'P', 'O'], true)) {
            $options[] = ['-oth-', 'Other', []];
        }

        $this->reconcileOptions($question, $options);
    }

    /**
     * The option rows a LimeSurvey question type draws from: subquestions for
     * the multiple-choice types, the answers table for the list types, or the
     * type's fixed implicit set.
     *
     * @param  array<int, array<string, string>>  $subquestions
     * @return array<int, array{0: string, 1: string, 2: array<string, string>}> value, base label, language => label
     */
    private function optionSource(SimpleXMLElement $doc, mixed $qid, string $lsType, array $subquestions): array
    {
        return match (true) {
            in_array($lsType, ['M', 'P'], true) => $this->subquestionOptions($doc, $subquestions),
            $lsType === '5' => array_map(fn (int $n): array => [(string) $n, (string) $n, []], range(1, 5)),
            $lsType === 'G' => [['M', 'Male', []], ['F', 'Female', []]],
            $lsType === 'Y' => [['true', 'Yes', []], ['false', 'No', []]],
            default => $this->answerTableOptions($doc, $qid), // L, !, O
        };
    }

    /**
     * Option rows built from a multiple-choice question's subquestions, in
     * their survey order.
     *
     * @param  array<int, array<string, string>>  $subquestions
     * @return array<int, array{0: string, 1: string, 2: array<string, string>}>
     */
    private function subquestionOptions(SimpleXMLElement $doc, array $subquestions): array
    {
        $subText = $this->l10n($doc, 'question_l10ns', 'qid');
        usort($subquestions, fn ($a, $b) => (int) ($a['question_order'] ?? 0) <=> (int) ($b['question_order'] ?? 0));

        $options = [];
        foreach ($subquestions as $sub) {
            $byLanguage = $subText[$sub['qid'] ?? ''] ?? [];
            $sl = $this->baseText($byLanguage);
            $options[] = [
                $sub['title'] ?? '',
                $this->text($sl['question'] ?? $sub['question'] ?? ($sub['title'] ?? '')),
                $this->languageTexts([$byLanguage, $this->questionInline[$sub['qid'] ?? ''] ?? []], 'question'),
            ];
        }

        return $options;
    }

    /**
     * Option rows built from a list question's answers-table entries, in
     * their survey sort order.
     *
     * @return array<int, array{0: string, 1: string, 2: array<string, string>}>
     */
    private function answerTableOptions(SimpleXMLElement $doc, mixed $qid): array
    {
        $answerText = $this->l10n($doc, 'answer_l10ns', 'aid');
        $answers = array_filter(
            $this->answerRows,
            fn ($a) => ($a['qid'] ?? null) === $qid && (int) ($a['scale_id'] ?? 0) === 0,
        );
        usort($answers, fn ($a, $b) => (int) ($a['sortorder'] ?? 0) <=> (int) ($b['sortorder'] ?? 0));

        $options = [];
        foreach ($answers as $answer) {
            $byLanguage = $answerText[$answer['aid'] ?? ''] ?? [];
            $al = $this->baseText($byLanguage);
            $options[] = [
                $answer['code'] ?? '',
                $this->text($al['answer'] ?? $answer['answer'] ?? ($answer['code'] ?? '')),
                $this->languageTexts([$byLanguage, $this->answerInline[($answer['qid'] ?? '').'|'.($answer['code'] ?? '')] ?? []], 'answer'),
            ];
        }

        return $options;
    }

    /**
     * Persist the built option rows onto the question, reconciled by value
     * (not deleted and recreated) so re-running the import keeps each
     * option's row id and anything the admin set on it afterwards.
     *
     * @param  array<int, array{0: string, 1: string, 2: array<string, string>}>  $options
     */
    private function reconcileOptions(Question $question, array $options): void
    {
        $values = [];
        foreach (array_values($options) as $pos => [$value, $optLabel, $labels]) {
            $option = $question->options()->updateOrCreate(['value' => (string) $value], [
                'label' => $optLabel !== '' ? $optLabel : (string) $value,
                'position' => $pos,
            ]);
            $this->counts['options']++;
            $this->writeTranslations($option, 'label', $labels);
            $values[] = (string) $value;
        }

        // Model-deletes so a removed option's translations go with it.
        $question->options()->whereNotIn('value', $values)->get()->each->delete();
    }

    /**
     * Turn LimeSurvey conditions into visibility rules. A condition's qid is the
     * dependent (shown/hidden) question and cqid the controlling one. LimeSurvey
     * ANDs conditions within a scenario and ORs across scenarios, which maps onto
     * this package's nested groups: one AND group per scenario under an OR root
     * (collapsed to a single AND group when there is only one scenario).
     */
    private function importConditions(SimpleXMLElement $doc): void
    {
        $byDependent = $this->groupBy($this->rows($doc, 'conditions'), 'qid');

        foreach ($byDependent as $qid => $conditions) {
            $question = $this->byQid[(int) $qid] ?? null;
            if ($question === null) {
                continue; // dependent question was itself skipped
            }

            $usable = [];
            foreach ($conditions as $condition) {
                $prepared = $this->prepareCondition($condition);
                if ($prepared !== null) {
                    $usable[(int) ($condition['scenario'] ?? 1)][] = $prepared;
                }
            }
            if ($usable === []) {
                continue;
            }

            $question->conditionGroups()->delete();
            $this->writeRule($question, $usable);
        }
    }

    /**
     * Resolve one LimeSurvey condition row to the fields this package stores, or
     * null when it cannot be represented (controlling question skipped, regex /
     * unsupported comparison, or a value that references another field).
     *
     * @param  array<string, string>  $condition
     * @return array{question_id: int, operator: string, value: ?string}|null
     */
    private function prepareCondition(array $condition): ?array
    {
        $controlling = $this->byQid[(int) ($condition['cqid'] ?? 0)] ?? null;
        if ($controlling === null) {
            $this->skipped[] = "Condition skipped: controlling question (cqid {$condition['cqid']}) was not imported.";

            return null;
        }

        $value = (string) ($condition['value'] ?? '');
        // A yes/no question imports as a radio storing "true"/"false", so a
        // comparison against LimeSurvey's Y/N is mapped onto those values.
        if (isset($this->yesNoQids[(int) ($condition['cqid'] ?? 0)])) {
            $value = ['Y' => 'true', 'N' => 'false'][$value] ?? $value;
        }
        if (str_starts_with($value, '@')) {
            $this->skipped[] = "Condition on '{$controlling->key}' skipped: compares against another field.";

            return null;
        }

        $method = $condition['method'] ?? '==';
        // A condition on a multiple-choice question targets one checkbox (its
        // subquestion code is suffixed onto cfieldname) with value "Y"; model
        // that as the question's answer set containing that option code.
        if ($controlling->type->isMultiValue()) {
            $sub = $this->subquestionCode($condition['cfieldname'] ?? '');
            if ($sub !== null && in_array($method, ['==', '='], true) && $value === 'Y') {
                $this->counts['conditions']++;

                return ['question_id' => $controlling->id, 'operator' => ConditionOperator::Contains->value, 'value' => $sub];
            }
            $this->skipped[] = "Condition on multiple-choice '{$controlling->key}' skipped: cannot be expressed.";

            return null;
        }

        $operator = self::METHOD_MAP[$method] ?? null;
        if ($operator === null) {
            $this->skipped[] = "Condition on '{$controlling->key}' skipped: comparison '{$method}' is not supported.";

            return null;
        }

        $this->counts['conditions']++;

        return ['question_id' => $controlling->id, 'operator' => $operator->value, 'value' => $value];
    }

    /**
     * Persist the prepared conditions as a visibility rule on the question.
     *
     * @param  array<int, array<int, array{question_id: int, operator: string, value: ?string}>>  $byScenario
     */
    private function writeRule(Question $question, array $byScenario): void
    {
        if (count($byScenario) === 1) {
            $group = $question->conditionGroups()->create(['operator' => BooleanOperator::And->value]);
            $this->addConditions($group, reset($byScenario));

            return;
        }

        $root = $question->conditionGroups()->create(['operator' => BooleanOperator::Or->value]);
        $position = 0;
        foreach ($byScenario as $scenario) {
            $child = $root->children()->create([
                'operator' => BooleanOperator::And->value,
                'position' => $position++,
            ]);
            $this->addConditions($child, $scenario);
        }
    }

    /**
     * @param  ConditionGroup  $group
     * @param  array<int, array{question_id: int, operator: string, value: ?string}>  $conditions
     */
    private function addConditions($group, array $conditions): void
    {
        foreach (array_values($conditions) as $position => $condition) {
            $group->conditions()->create($condition + ['position' => $position]);
        }
    }

    /** The subquestion code suffixed onto a multiple-choice cfieldname (e.g. "123X4X5SQ001" => "SQ001"). */
    private function subquestionCode(string $cfieldname): ?string
    {
        if (preg_match('/^\d+X\d+X\d+(.+)$/', $cfieldname, $m)) {
            return $m[1];
        }

        return null;
    }

    /** The survey's display title, for the import summary. */
    private function surveyTitle(SimpleXMLElement $doc): ?string
    {
        foreach ($this->rows($doc, 'surveys_languagesettings') as $row) {
            if (($this->baseLanguage === '' || ($row['surveyls_language'] ?? '') === $this->baseLanguage)) {
                return $this->text($row['surveyls_title'] ?? '') ?: null;
            }
        }

        return null;
    }

    /**
     * Read a .lss table dump into a list of associative rows. Each table block is
     * <name><fields><fieldname/>…</fields><rows><row><col/>…</row></rows></name>;
     * the row children are named by column, so casting them to string is enough.
     *
     * @return array<int, array<string, string>>
     */
    private function rows(SimpleXMLElement $doc, string $table): array
    {
        if (! isset($doc->{$table}->rows->row)) {
            return [];
        }

        $rows = [];
        foreach ($doc->{$table}->rows->row as $row) {
            $record = [];
            foreach ($row->children() as $column) {
                $record[$column->getName()] = (string) $column;
            }
            $rows[] = $record;
        }

        return $rows;
    }

    /**
     * Build a localization lookup (foreign key => language => fields) from a
     * *_l10ns table, keeping every language's row. An absent table (older .lss
     * layout) yields an empty map, and callers fall back to the main row's own
     * columns.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    private function l10n(SimpleXMLElement $doc, string $table, string $key): array
    {
        $map = [];
        foreach ($this->rows($doc, $table) as $row) {
            $id = $row[$key] ?? null;
            if ($id === null) {
                continue;
            }
            $map[$id][$row['language'] ?? ''] = $row;
        }

        return $map;
    }

    /**
     * The base-language row from a per-language map, falling back to whichever
     * language is there when the base is absent (a survey has ONE language then
     * — its texts land in the entity columns and every locale falls back to it).
     *
     * @param  array<string, array<string, string>>  $byLanguage
     * @return array<string, string>
     */
    private function baseText(array $byLanguage): array
    {
        return $byLanguage[$this->baseLanguage] ?? (reset($byLanguage) ?: []);
    }

    /**
     * Collect one column's non-base-language texts (language => text) across the
     * given per-language row maps (the l10n rows and any LS2 inline rows).
     *
     * @param  array<int, array<string, array<string, string>>>  $sources
     * @return array<string, string>
     */
    private function languageTexts(array $sources, string $column): array
    {
        $texts = [];
        foreach ($sources as $byLanguage) {
            foreach ($byLanguage as $language => $row) {
                if ($language === '' || $language === $this->baseLanguage) {
                    continue;
                }
                $text = $this->text($row[$column] ?? '');
                if ($text !== '') {
                    $texts[$language] = $text;
                }
            }
        }

        return $texts;
    }

    /**
     * Store one field's per-language texts as translation rows on the imported
     * entity (idempotent — updated in place on re-import).
     *
     * @param  Model  $model  a TranslatesFields model
     * @param  array<string, string>  $byLanguage
     */
    private function writeTranslations($model, string $field, array $byLanguage): void
    {
        foreach ($byLanguage as $language => $value) {
            $model->storeTranslation($language, $field, $value);
            $this->counts['translations']++;
        }
    }

    /**
     * Collapse a LimeSurvey 2.x multilingual main-table dump — which repeats
     * each row once per language — to one canonical row per entity (preferring
     * the base language) plus a per-entity map of the other languages' rows.
     * Rows without an id, and LS3+ rows (no language column), pass through
     * untouched.
     *
     * @param  array<int, array<string, string>>  $rows
     * @param  callable(array<string, string>): ?string  $idOf
     * @return array{array<int, array<string, string>>, array<string, array<string, array<string, string>>>}
     */
    private function dedupeByLanguage(array $rows, callable $idOf): array
    {
        $main = [];
        $inline = [];
        $seen = [];

        foreach ($rows as $row) {
            $id = $idOf($row);
            $language = $row['language'] ?? '';

            if ($id === null) {
                $main[] = $row;

                continue;
            }

            if (! isset($seen[$id])) {
                $seen[$id] = count($main);
                $main[] = $row;
            } elseif ($language === $this->baseLanguage) {
                $main[$seen[$id]] = $row;
            }

            if ($language !== '' && $language !== $this->baseLanguage) {
                $inline[$id][$language] = $row;
            }
        }

        return [$main, $inline];
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array<string, array<int, array<string, string>>>
     */
    private function groupBy(array $rows, string $key): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            if (isset($row[$key])) {
                $grouped[$row[$key]][] = $row;
            }
        }

        return $grouped;
    }

    /** Strip the HTML LimeSurvey wraps question/answer text in down to plain text. */
    private function text(string $value): string
    {
        return $this->rewriteAnswerTokens(trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5)));
    }

    /**
     * The codes of the questions this import will create, mapped (lowercased,
     * since LimeSurvey references are case-insensitive) to the key each imports
     * under — subquestions and unsupported types are not importable targets.
     *
     * @return array<string, string>
     */
    private function questionCodeKeys(): array
    {
        $map = [];
        foreach ($this->questionRows as $row) {
            if ((int) ($row['parent_qid'] ?? 0) !== 0 || ! isset(self::TYPE_MAP[$row['type'] ?? ''])) {
                continue;
            }
            $code = $row['title'] ?? '';
            if ($code !== '') {
                $map[mb_strtolower($code)] = $this->slug($code, 'q'.($row['qid'] ?? ''));
            }
        }

        return $map;
    }

    /**
     * Rewrite LimeSurvey answer references — {Code.value}, {Code.shown} and the
     * .NAOK variants — into this package's {q:key.value} tokens, so imported
     * texts show the registrant's answers. Only references to questions this
     * import creates are rewritten; anything else in braces ({Variable} tokens,
     * {if(...)} expressions) is left untouched for the admin to resolve.
     */
    private function rewriteAnswerTokens(string $text): string
    {
        if ($this->codeKeys === [] || ! str_contains($text, '{')) {
            return $text;
        }

        return preg_replace_callback(
            '/\{([A-Za-z][A-Za-z0-9_]*)\.(?:value|shown|naok|valuenaok|shownnaok)\}/i',
            function (array $m): string {
                $key = $this->codeKeys[mb_strtolower($m[1])] ?? null;

                return $key === null ? $m[0] : '{q:'.$key.'.value}';
            },
            $text,
        );
    }

    /** A stable machine key from a label, falling back when it slugs to nothing. */
    private function slug(string $value, string $fallback): string
    {
        $slug = Str::slug($value);

        return $slug !== '' ? Str::limit($slug, 120, '') : $fallback;
    }
}
