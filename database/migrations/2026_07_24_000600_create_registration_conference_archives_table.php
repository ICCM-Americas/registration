<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single retained snapshot of an outgoing conference's registration data
 * (see RegistrationArchiver), taken when an admin starts the next conference.
 * Only one archive is ever kept — the previous row is deleted before a new
 * one is written — so this never grows into a per-year history table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('conference_name', 255)->nullable();
            $table->string('conference_year', 4)->nullable();
            $table->timestamp('archived_at');
            $table->json('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'conference_archives';
    }
};
