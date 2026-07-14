<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Builds a section's visibility rule — when the whole wizard step is shown. A
 * failing rule or the "never show" flag skips the step and with it every
 * question on it, overriding the questions' own visibility; a section with no
 * rule ("always show") imposes nothing, leaving visibility to each question.
 * The tree editing is shared with the question and option editors
 * ({@see VisibilityRuleController}); "never show" maps to the section's
 * existing enabled flag, which already excludes the step from the wizard.
 */
class SectionVisibilityController extends VisibilityRuleController
{
    /** Sections stay editable while answers are locked. */
    protected function honorsAnswerLock(): bool
    {
        return false;
    }

    /** Open the editor for a section's rule. */
    public function edit(Section $section)
    {
        return $this->editor($section);
    }

    /** Start a rule on a section. */
    public function storeRoot(Section $section)
    {
        return $this->storeRootFor($section);
    }

    /** Remove a section's whole rule. */
    public function destroyRule(Section $section)
    {
        return $this->destroyRuleFor($section);
    }

    /** Mark the section never-visible (disabled); any rule is discarded. */
    public function hide(Section $section)
    {
        $section->conditionGroups()->get()->each->delete();
        $section->update(['enabled' => false]);

        return $this->editor($section);
    }

    /** Clear the never-visible flag — the section becomes always shown. */
    public function show(Section $section)
    {
        $section->update(['enabled' => true]);

        return $this->editor($section);
    }

    /** Add a subgroup to a section's rule. */
    public function storeGroup(Request $request, Section $section)
    {
        return $this->storeGroupFor($request, $section);
    }

    /** Switch a group's AND/OR operator. */
    public function updateGroup(Request $request, Section $section, ConditionGroup $group)
    {
        return $this->updateGroupFor($request, $section, $group);
    }

    /** Remove a group from a section's rule. */
    public function destroyGroup(Section $section, ConditionGroup $group)
    {
        return $this->destroyGroupFor($section, $group);
    }

    /** Add a leaf condition to a section's rule. */
    public function storeCondition(Request $request, Section $section)
    {
        return $this->storeConditionFor($request, $section);
    }

    /** Remove a leaf condition from a section's rule. */
    public function destroyCondition(Section $section, Condition $condition)
    {
        return $this->destroyConditionFor($section, $condition);
    }

    /**
     * Questions whose answers this section's rule may test: same scope, but not
     * the section's own questions — the step's visibility is decided before it
     * is shown, when its own questions cannot have answers yet.
     */
    protected function controllingQuestions(Model $node): Collection
    {
        return $this->questions->questionsForScope($node->scope)
            ->reject(fn (Question $q) => $q->section_id === $node->id)
            ->values();
    }

    /** @return array<string, mixed> */
    protected function editorData(Model $node): array
    {
        return [
            'visPrefix' => 'admin.sections',
            'canHide' => true,
            'noun' => __('registration::admin.visibility_noun_section'),
            'tags' => ['hidden' => $node->isHidden(), 'conditional' => $node->conditionGroups->isNotEmpty()],
            'intro' => __('registration::admin.visibility_intro_section'),
            'subjectLabel' => $node->title,
            'subjectKey' => $node->key,
        ];
    }
}
