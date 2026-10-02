<?php

namespace ConferenceTools\Registration\Services\Search;

use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\ReportQuestions;
use Illuminate\Database\Eloquent\Model;

/** How the admin search names registrants and their guests. */
class RegistrantLabels
{
    /** @var array<int, string> */
    private array $names = [];

    public function __construct(
        private ReportQuestions $questions,
        private GuestQuestions $guestQuestions,
    ) {}

    /** A registrant's display name: the nominated first and last name, else their email. */
    public function name(Model $user): string
    {
        return $this->names[$user->getKey()] ??= $this->questions->fullName($user) ?? (string) $user->email;
    }

    /** A committed guest's label, e.g. "Guest (Adult): Ann". */
    public function guest(Guest $guest): string
    {
        return $this->guestOfType($guest->type->value, $this->guestQuestions->displayName($guest));
    }

    /** A guest's label by type and, when known, name. */
    public function guestOfType(string $type, ?string $name = null): string
    {
        $type = __('registration::common.guest_type_'.$type);

        return $name
            ? __('registration::admin.search_guest_named', ['type' => $type, 'name' => $name])
            : __('registration::admin.search_guest', ['type' => $type]);
    }
}
