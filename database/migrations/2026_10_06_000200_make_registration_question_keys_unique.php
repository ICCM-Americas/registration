<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Question keys become unique across every scope, not just within one: the
 * answer lookups, {q:key} tokens and nominated settings all name a question
 * by its bare key. Existing duplicates stop the migration instead of being
 * renamed, since a renamed key would silently break whatever names it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table($this->table())
            ->select('key')
            ->groupBy('key')
            ->havingRaw('count(*) > 1')
            ->orderBy('key')
            ->pluck('key');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(__('registration::admin.question_keys_not_unique', ['keys' => $duplicates->implode(', ')]));
        }

        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropIndex(['key']);
            $table->unique('key');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->index('key');
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'questions';
    }
};
