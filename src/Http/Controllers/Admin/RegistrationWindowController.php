<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use Carbon\CarbonImmutable;
use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Services\RegistrationStatus;
use Illuminate\Http\Request;

/**
 * The registration window controls on the admin dashboard: schedule the
 * open/close dates, or open/close registration right now (which writes the
 * same dates — see RegistrationStatus).
 */
class RegistrationWindowController extends Controller
{
    public function __construct(private RegistrationStatus $status) {}

    /** Save the scheduled open and close datetimes. */
    public function update(Request $request)
    {
        $data = $request->validate([
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after:opens_at'],
        ]);

        $this->status->schedule(
            isset($data['opens_at']) ? CarbonImmutable::parse($data['opens_at']) : null,
            isset($data['closes_at']) ? CarbonImmutable::parse($data['closes_at']) : null,
        );

        return $this->backToDashboard(__('registration::admin.window_saved'));
    }

    /** Open registration now, refused while prerequisites are unmet. */
    public function open()
    {
        // The manual open is refused while the registration email addresses
        // are unconfigured, a required name question isn't nominated, or a
        // Reports matching-answer setting has gone stale (the same
        // conditions that banner every admin page).
        if ($this->status->reportAnswersStale()) {
            return redirect()
                ->route($this->routeName('admin.dashboard'))
                ->with('window_error', __('registration::admin.window_report_answers_stale'));
        }

        if (! $this->status->requiredNameQuestionsConfigured()) {
            return redirect()
                ->route($this->routeName('admin.dashboard'))
                ->with('window_error', __('registration::admin.window_name_questions_required'));
        }

        if (! $this->status->open()) {
            return redirect()
                ->route($this->routeName('admin.dashboard'))
                ->with('window_error', __('registration::admin.window_email_required'));
        }

        return $this->backToDashboard(__('registration::admin.window_opened'));
    }

    /** Close registration now. */
    public function close()
    {
        $this->status->close();

        return $this->backToDashboard(__('registration::admin.window_closed'));
    }

    /** Redirect to the admin dashboard with a status message. */
    private function backToDashboard(string $message)
    {
        return redirect()
            ->route($this->routeName('admin.dashboard'))
            ->with('window_status', $message);
    }
}
