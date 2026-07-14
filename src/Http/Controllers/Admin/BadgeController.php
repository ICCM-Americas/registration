<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\BadgeSheetSize;
use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\BadgeLayoutElement;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\PrayerPalsAssignment;
use ConferenceTools\Registration\Services\CsvExport;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\Registrants;
use ConferenceTools\Registration\Services\ReportQuestions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The printable name badges: conference name and logo at the top, the
 * occupant's name big and bold in the middle, their organization at the
 * bottom. One badge per registrant (as they asked for it on the nominated
 * badge-name question), plus one per badge-eligible non-attending guest —
 * every adult guest, and a minor guest only when the admin has enabled
 * "minors get a badge" (some host locations require one for meal access; see
 * {@see Guest::isBadgeEligible()}). Printable on any of the sheet sizes in
 * {@see BadgeSheetSize}, chosen via the "size" query param and defaulting by
 * locale. The PDF itself is generated client-side (see the badges view's
 * conferenceReportPdf), so the exact card grid is placed by coordinate, not
 * by an HTML layout engine.
 */
class BadgeController extends Controller
{
    /** The badges report page with the print layout. */
    public function index(Registrants $registrants, ReportQuestions $questions, GuestQuestions $guestQuestions, Request $request)
    {
        $size = $this->badgeSheetSize($request);

        return view('registration::admin.logistics.badges', [
            'badges' => $this->badges($registrants, $questions, $guestQuestions),
            'sizes' => BadgeSheetSize::cases(),
            'size' => $size,
            'elements' => BadgeLayoutElement::where('sheet_size', $size)->get(),
        ]);
    }

    /** The requested sheet size, defaulting by locale. */
    private function badgeSheetSize(Request $request): BadgeSheetSize
    {
        return BadgeSheetSize::tryFrom((string) $request->query('size'))
            ?? BadgeSheetSize::default($this->isUsLocale());
    }

    /** The badge list as a CSV download. */
    public function csv(Registrants $registrants, ReportQuestions $questions, GuestQuestions $guestQuestions, CsvExport $exporter): StreamedResponse
    {
        $rows = $this->badges($registrants, $questions, $guestQuestions)
            ->map(fn (array $badge): array => [$badge['name'], $badge['organization']]);

        return $this->downloadCsv($exporter, 'badges',
            ['Name', 'Organization'],
            $rows,
        );
    }

    /** @return Collection<int, array{name: ?string, organization: ?string, prayerPals: ?string}> */
    private function badges(Registrants $registrants, ReportQuestions $questions, GuestQuestions $guestQuestions): Collection
    {
        $prayerPalsLabels = PrayerPalsAssignment::with('group')->get()
            ->mapWithKeys(fn (PrayerPalsAssignment $a): array => [$a->assignable_type.':'.$a->assignable_id => $a->group->label()]);
        $key = fn (Model $occupant): string => $occupant->getMorphClass().':'.$occupant->getKey();
        $minorsGetBadges = $guestQuestions->minorsGetBadges();

        $badges = collect();
        foreach ($registrants->all() as $user) {
            $badges->push([
                'name' => $questions->badgeName($user),
                'organization' => $questions->organization($user),
                'prayerPals' => $prayerPalsLabels->get($key($user)),
            ]);

            foreach ($user->guests->filter(fn (Guest $g) => $g->isBadgeEligible($minorsGetBadges)) as $guest) {
                $badges->push([
                    'name' => $guestQuestions->occupantName($guest, $questions),
                    'organization' => $questions->organization($guest),
                    'prayerPals' => $prayerPalsLabels->get($key($guest)),
                ]);
            }
        }

        return $badges;
    }
}
