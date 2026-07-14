<?php

// Shared labels used across the registration package's views.
return [
    'login' => 'Login',
    'registration' => 'Registration',
    'register_account' => 'Create a new account',
    'register_conference' => 'Register for the conference',

    // YesNo question buttons (see QuestionType::YesNo) — fixed, shared across
    // every such question, translated once here rather than per question.
    'yes' => 'Yes',
    'no' => 'No',

    // The invoice-style cost summary: the final wizard step's card and the
    // plain-text {cost_summary} email token (see CostSummary/CostSummaryBuilder).
    'cost_summary_heading' => 'Cost Summary',
    'cost_subtotal' => 'Subtotal',
    'cost_discount' => 'Discount',
    'cost_total' => 'Total',
    'cost_per_diem_line' => ':label (:days days)',
    'cost_guests_line' => 'Accompanying adult guests (:count × :days days)',
    'cost_guests_minor_line' => 'Accompanying minor guests (:count × :days days)',

    // The Guest List hub and the guest add/edit form (see GuestController).
    'manage_guests_link' => 'Manage Non-Attending Guests',
    'guest_hub_title' => 'Non-Attending Guests',
    'guest_hub_intro' => 'Add anyone coming with you who is not attending the conference themselves.',
    'guest_hub_continue' => 'Continue',
    'guest_list_empty' => 'No guests added yet.',
    'guest_unnamed' => 'Unnamed guest',
    'guest_type_label' => 'Guest type',
    'guest_type_adult' => 'Adult',
    'guest_type_minor' => 'Minor',
    'guest_add' => 'Add a Guest',
    'guest_edit' => 'Edit',
    'guest_remove' => 'Remove',
    'guest_save' => 'Save Guest',
    'confirm_delete_guest' => 'Remove this guest?',

    // The Group Member hub and the group-member add/edit form (see GroupMemberController).
    'manage_group_members_link' => 'Manage Group Members',
    'group_member_hub_title' => 'Group Members',
    'group_member_hub_intro' => 'Add the name and email address of everyone you\'re inviting to join your group — we\'ll email each of them a link to register.',
    'group_member_hub_continue' => 'Continue',
    'group_member_list_empty' => 'No group members added yet.',
    'group_member_unnamed' => 'Unnamed group member',
    'group_member_add' => 'Add a Group Member',
    'group_member_edit' => 'Edit',
    'group_member_remove' => 'Remove',
    'group_member_save' => 'Save Group Member',
    'confirm_delete_group_member' => 'Remove this group member?',

    // Sent to an invitee before they've signed in — see GroupInviteController.
    'invite_login_notice' => 'Log in or create an account to continue your group registration.',

    // The group member's split cost summary (see partials/group-member-cost-summary).
    'group_leader_covers_heading' => 'Covered by Your Group Leader',
    'group_leader_covers_total' => 'Total Covered',
    'your_responsibility_heading' => 'Your Responsibility',

    // Privacy notice shown above the registration forms (contains markup).
    'privacy_notice' => '<b>Privacy notice:</b><br />
At the conference, we will produce printed lists of attendants including your name (replaced by a nickname if you enter one), e-mail, name of organization and IT skills.<br />
<br />
If you do not wish to be included, please contact us by e-mail.<br />
<br />
We do not distribute this data in any digital form.',
];
