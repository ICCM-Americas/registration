<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\ConferenceArchive;
use ConferenceTools\Registration\Services\ConferenceEdition;
use ConferenceTools\Registration\Services\CsvZipExport;
use ConferenceTools\Registration\Services\RegistrationArchiver;
use ConferenceTools\Registration\Services\RegistrationExportBundle;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The conference edition controls on the admin dashboard: the name and year
 * every admin-authored text references via {conference_name}/{conference_year},
 * and the "start next conference" rollover that archives the outgoing
 * edition's name for the closed registration page (see ConferenceEdition)
 * and its registration data (see RegistrationArchiver). Also the .ZIP of CSVs
 * exports of that same registration data, current and archived (see
 * RegistrationExportBundle).
 */
class ConferenceController extends Controller
{
    public function __construct(private ConferenceEdition $edition, private RegistrationArchiver $archiver) {}

    /** Save the conference's name and year. */
    public function update(Request $request)
    {
        $data = $request->validate([
            'conference_name' => ['nullable', 'string', 'max:255'],
            'conference_year' => ['nullable', 'digits:4'],
        ]);

        $this->edition->update($data['conference_name'] ?? null, $data['conference_year'] ?? null);

        return $this->backToDashboard(__('registration::admin.conference_saved'));
    }

    /** Roll over to the next conference, archiving the outgoing edition and its registration data. */
    public function startNext()
    {
        $this->archiver->archive();
        $this->edition->startNext();

        return $this->backToDashboard(__('registration::admin.conference_next_started'));
    }

    /** The current registration data as a .ZIP of flattened CSVs: answers, groups, payments, room assignments, Prayer Pals. */
    public function csvRegistrations(RegistrationExportBundle $bundle, CsvZipExport $exporter): BinaryFileResponse
    {
        return $this->downloadCsvZip($exporter, 'registrations', $bundle->sheets($this->archiver->snapshot()));
    }

    /** The single retained archive's data as the same .ZIP of CSVs shape. */
    public function csvArchive(RegistrationExportBundle $bundle, CsvZipExport $exporter): BinaryFileResponse
    {
        $archive = ConferenceArchive::first();
        abort_if($archive === null, 404);

        return $this->downloadCsvZip($exporter, 'archive', $bundle->sheets($archive->data));
    }

    /** Redirect to the admin dashboard with a status message. */
    private function backToDashboard(string $message)
    {
        return redirect()
            ->route($this->routeName('admin.dashboard'))
            ->with('conference_status', $message);
    }
}
