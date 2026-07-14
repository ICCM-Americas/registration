<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Services\AnswerPurge;

/**
 * The dashboard's data-reset control: wipes every registration answer, group,
 * draft and room assignment, so a fresh testing pass (or a cleared-out demo
 * seed) starts from an empty questionnaire. See {@see AnswerPurge}.
 */
class AnswersController extends Controller
{
    public function __construct(private AnswerPurge $purge) {}

    /** Delete all registration data (never user accounts) from the dashboard button. */
    public function destroy()
    {
        $this->purge->purge();

        return redirect()
            ->route($this->routeName('admin.dashboard'))
            ->with('answers_status', __('registration::admin.answers_deleted'));
    }
}
