<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Models\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Attaches a registrant to a group. The registrant's answers (last name,
 * passport, accommodation, products…) are validated by the configurable
 * questionnaire and stored in the EAV answer store; this service only writes the
 * few structural columns the package owns on the host users table (the account's
 * display name, the mail-link token, the group-admin flag) and links the user to
 * their group.
 */
class UserRegistrationService
{
    /**
     * Register a host user as a participant in a group.
     *
     * Creating the account itself — its email, password, and display name —
     * belongs to the host application's authentication; this package never
     * touches those. forceFill is used so the package does not depend on the
     * host model's $fillable configuration for the columns it does own. The
     * caller is responsible for validating and storing the registrant's
     * answers (see {@see AnswerStore}).
     *
     * @throws \Exception
     */
    public function registerUser(array $data, Group $group, Model $user, bool $isGroupAdmin = false): Model
    {
        $user->forceFill([
            // Preserve an existing mail_id (the per-user mail link token); only
            // generate one the first time the user is registered.
            'mail_id' => $user->mail_id ?: bin2hex(random_bytes(8)),
            'is_group_admin' => $isGroupAdmin,
        ]);

        DB::transaction(function () use ($group, $user) {
            $group->save();
            $group->users()->save($user);
        });

        return $user;
    }
}
