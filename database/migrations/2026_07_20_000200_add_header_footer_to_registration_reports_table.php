<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A report-wide header and footer: admin-authored plain text (interpolated
 * the same way as other admin texts, but never with question tokens — a
 * report has no single row for those to answer) shown above/below the report,
 * on screen and in the PDF export.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->text('header')->nullable()->after('description');
            $table->text('footer')->nullable()->after('header');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn(['header', 'footer']);
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'reports';
    }
};
