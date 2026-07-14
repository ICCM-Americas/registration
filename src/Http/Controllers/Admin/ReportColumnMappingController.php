<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\ReportColumnMappingGuest;
use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\ReportColumn;
use ConferenceTools\Registration\Services\ReportRunner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A question column's own "Custom Values" mapping: an ordered list of
 * {value, guest, text} entries (see {@see ReportColumnMappingGuest}),
 * consulted by {@see ReportRunner}
 * only while the column's display mode is "mapped", but stored regardless so
 * switching modes on and off never loses it. Independent per column: two
 * columns on the same question may carry different mappings, or none.
 */
class ReportColumnMappingController extends Controller
{
    /** The mapping editor fragment for one column. */
    public function edit(ReportColumn $column)
    {
        return $this->editor($column);
    }

    /** Append one mapping entry. */
    public function store(Request $request, ReportColumn $column)
    {
        $data = $request->validate([
            'value' => ['nullable', 'string', 'max:255'],
            'guest' => ['required', Rule::in(ReportColumnMappingGuest::values())],
            'text' => ['required', 'string', 'max:1024'],
        ]);

        $mapping = $column->mapping ?? [];
        $mapping[] = ['value' => $data['value'] ?: null, 'guest' => $data['guest'], 'text' => $data['text']];
        $column->update(['mapping' => $mapping]);

        return $this->editor($column);
    }

    /** Remove one mapping entry by its position in the list. */
    public function destroy(Request $request, ReportColumn $column)
    {
        $data = $request->validate(['index' => ['required', 'integer', 'min:0']]);

        $mapping = $column->mapping ?? [];
        unset($mapping[$data['index']]);
        $column->update(['mapping' => array_values($mapping)]);

        return $this->editor($column);
    }

    /**
     * The editor fragment the console fetches into its modal; mutations
     * return it refreshed so the modal swaps content in place.
     */
    private function editor(ReportColumn $column)
    {
        return view('registration::admin.reports.mapping-editor', [
            'column' => $column,
            'guestOptions' => ReportColumnMappingGuest::cases(),
        ]);
    }
}
