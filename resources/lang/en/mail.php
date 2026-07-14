<?php

// Default texts for the registration email templates. They apply until an
// admin saves a template on the Emails console (see EmailTemplate::forKey),
// after which the saved text is used instead.
return [
    'registrant_confirmation_subject' => 'Your registration has been received',
    'registrant_confirmation_body' => "Thank you for registering.\n\nWe have recorded your registration and will be in touch with the next steps.",

    'admin_notification_subject' => 'New registration received',
    'admin_notification_body' => 'A new registration has been submitted.',

    'group_invite_subject' => '{leader_name} has invited you to register',
    'group_invite_body' => "{leader_name} of {leader_organization} has invited you to register for the conference as part of their group.\n\nFollow this link to get started: {invite_link}",
];
