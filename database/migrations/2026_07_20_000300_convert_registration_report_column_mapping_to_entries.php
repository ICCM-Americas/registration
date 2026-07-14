<?php

use ConferenceTools\Registration\Enums\ReportColumnMappingGuest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Converts every existing report column's "Custom Values" mapping from a flat
 * {stored value: shown text} object into a list of entries, each now able to
 * carry its own guest filter and an optionally blank (any-value) stored
 * value — see {@see ReportColumnMappingGuest}.
 * Every converted entry keeps its old unconditional ("any" row) behavior.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table($this->table())->whereNotNull('mapping')->get(['id', 'mapping'])->each(function ($row) {
            $old = json_decode($row->mapping, true) ?? [];
            if (array_is_list($old)) {
                return;
            }

            $entries = [];
            foreach ($old as $value => $text) {
                $entries[] = ['value' => $value, 'guest' => 'any', 'text' => $text];
            }

            DB::table($this->table())->where('id', $row->id)->update(['mapping' => json_encode($entries)]);
        });
    }

    public function down(): void
    {
        DB::table($this->table())->whereNotNull('mapping')->get(['id', 'mapping'])->each(function ($row) {
            $entries = json_decode($row->mapping, true) ?? [];
            if (! array_is_list($entries)) {
                return;
            }

            // Lossy: any wildcard (blank value) or guest-filtered entry has no
            // flat-map equivalent and is dropped.
            $old = [];
            foreach ($entries as $entry) {
                if (($entry['value'] ?? null) !== null) {
                    $old[$entry['value']] = $entry['text'] ?? '';
                }
            }

            DB::table($this->table())->where('id', $row->id)->update(['mapping' => json_encode($old)]);
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'report_columns';
    }
};
