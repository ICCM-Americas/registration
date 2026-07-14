<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Models\Group;
use Illuminate\Database\Eloquent\Model;

/**
 * Registers an already-authenticated host user as the administrator of a new
 * group. As with {@see UserRegistrationService}, the registrant's and group's
 * answers are validated by the configurable questionnaire and stored in the EAV
 * answer store; this service only creates the group (with its organization name)
 * and links the admin to it.
 */
class GroupRegistrationService extends UserRegistrationService
{
    /**
     * Register an already-authenticated host user as the administrator of a new
     * group. The account (email/password) already exists — it is created by the
     * host application before the user reaches the registration form.
     *
     * @throws \Exception
     */
    public function registerGroup(array $data, Model $user, bool $isGroupAdmin = true): Model
    {
        $group = $this->createGroup($data);

        return parent::registerUser($data, $group, $user, $isGroupAdmin);
    }

    /**
     * Build a Group from registration data — its identity is the organization
     * name. Group-scope questions are not asked while the group flow is parked,
     * so without an organization answer the group is named for the registrant.
     */
    public function createGroup(array $data): Group
    {
        $group = new Group;
        $group->fill([
            'name' => $data['organization'] ?? trim(($data['name'] ?? '').' '.($data['lastname'] ?? '')),
            'is_group' => (bool) ($data['is_group'] ?? false),
        ]);

        return $group;
    }
}
