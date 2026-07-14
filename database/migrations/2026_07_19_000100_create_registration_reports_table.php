<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An admin-defined report: a named, described listing of registrants (and
 * optionally their non-attending guests, adults and minors toggled
 * separately) built from configured questions' answers. Which rows appear is
 * decided by a visibility-style rule tree attached polymorphically (see
 * registration_condition_groups); which columns appear (and when each cell
 * shows) lives in registration_report_columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('include_adult_guests')->default(false);
            $table->boolean('include_minor_guests')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'reports';
    }
};
