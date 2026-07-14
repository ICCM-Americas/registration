<?php

namespace ConferenceTools\Registration\Support;

use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Services\RegistrationWizard;
use Illuminate\Support\Collection;

/**
 * One screen of the registration wizard: a section's questions, either all of
 * them (the normal case — a whole section batched together and submitted as
 * one step) or a single reactive trigger question isolated into its own step
 * (see {@see RegistrationWizard}). A
 * step's identity is its first question's id, not its section's id, because
 * one section can now yield more than one step.
 */
final class Step
{
    /** @param  Collection<int, Question>  $questions */
    public function __construct(
        public readonly Section $section,
        public readonly Collection $questions,
    ) {}

    /** This step's stable identity across requests. */
    public function id(): int
    {
        return $this->questions->first()->id;
    }

    /**
     * Whether this step reacts immediately to its single question's answer
     * (an immediate detour to that question's own hub on "Yes") instead of
     * batching with sibling questions.
     */
    public function isReactiveTrigger(): bool
    {
        return $this->questions->count() === 1 && Question::isTriggerKey($this->questions->first()->key);
    }
}
