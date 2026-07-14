<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the bare "user_id" (assumed to always mean a host user) with a
 * polymorphic assignable_type/assignable_id pair, so an adult non-attending
 * guest who opts in to Prayer Pals (see GuestQuestions::prayerPalsOptedIn())
 * can be assigned too without risking an id collision between the users and
 * registration_guests tables — the same idiom already used by
 * registration_answers.owner_type/owner_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->string('assignable_type')->nullable()->after('prayer_pals_group_id');
            $table->unsignedBigInteger('assignable_id')->nullable()->after('assignable_type');
        });

        // Every existing row predates this feature, so it is necessarily a real registrant.
        DB::table($this->table())->update([
            'assignable_type' => config('registration.user_model'),
            'assignable_id' => DB::raw('user_id'),
        ]);

        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->dropColumn('user_id');
            $table->string('assignable_type')->nullable(false)->change();
            $table->unsignedBigInteger('assignable_id')->nullable(false)->change();
            $table->unique(['assignable_type', 'assignable_id']);
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('prayer_pals_group_id');
        });

        DB::table($this->table())
            ->where('assignable_type', config('registration.user_model'))
            ->update(['user_id' => DB::raw('assignable_id')]);
        DB::table($this->table())->whereNull('user_id')->delete();

        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn(['assignable_type', 'assignable_id']);
            $table->unsignedBigInteger('user_id')->unique()->nullable(false)->change();
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'prayer_pals_assignments';
    }
};
