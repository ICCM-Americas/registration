<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The individual-vs-group choice is no longer made on its own opening screen
 * — it's derived at commit time from the answer to the seeded "are you
 * registering a group?" system question (see SystemQuestionsSeeder), so the
 * draft no longer needs to carry it separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('is_group');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->boolean('is_group')->nullable();
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'drafts';
    }
};
