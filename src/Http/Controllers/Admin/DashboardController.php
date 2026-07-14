<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Services\DashboardSummary;

/** The package's admin dashboard: registration counts and the management cards. */
class DashboardController extends Controller
{
    /** The registration admin dashboard. */
    public function index(DashboardSummary $summary)
    {
        return view('registration::admin.index', [
            'registrationsComplete' => Group::registeredParticipantCount(),
            // Started but not yet committed: one wizard draft per registrant.
            'registrationsIncomplete' => Draft::incompleteCount(),
            'attendeesCount' => $summary->attendeesCount(),
            'guestsCount' => $summary->guestsCount(),
            'specialNeedsCount' => $summary->specialNeedsCount(),
            'arrivalsByDay' => $summary->arrivalsByDay(),
            'shuttleRunsCount' => $summary->shuttleRunsCount(),
            'roomAssignmentCoverage' => $summary->roomAssignmentCoverage(),
            'prayerPalsCoverage' => $summary->prayerPalsCoverage(),
        ]);
    }
}
