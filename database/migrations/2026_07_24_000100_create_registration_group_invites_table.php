<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per person a group leader has invited to register as a member of
 * their group (see GroupMemberController/DraftGroupMembers for the pre-commit
 * side, and RegistrationController::commit() for where these are created).
 * "token" is the invite link's sole credential — deliberately not tied to a
 * specific email address, since the invitee may sign up with a different one
 * than the leader typed; see GroupInviteController. "user_id" is a
 * convention-only reference to the host users table (no hard FK, matching
 * every other host-user reference in this package, e.g. guests.user_id),
 * populated once the invite is accepted. The row (and its token/consumed_at)
 * is kept even after acceptance, and even if the resulting account is later
 * deleted (see RegistrationServiceProvider) — it is the record of who
 * belongs to which group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table('group_invites'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('group_id')->constrained($this->table('groups'))->cascadeOnDelete();
            $table->string('token', 16)->unique();
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('group_invites'));
    }

    private function table(string $base): string
    {
        return config('registration.tables.prefix').$base;
    }
};
