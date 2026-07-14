<?php

// Public registration landing/info page.
return [
    'title' => 'Registration',

    'welcome' => 'Welcome to the registration form for :name.',

    'thanks_strong' => 'Thanks for registering!',
    'thanks_body' => 'Your registration has been recorded.',

    // How each admin-configured step is numbered on the page. The step texts
    // themselves are admin content: they live in the database (seeded in
    // English by InfoStepSeeder, managed on the admin "Steps" page) and are
    // translated per locale on the admin translations screen — not here.
    'step_heading' => 'Step :number: :title',

    // Shown instead of the landing page and wizard while registration is
    // outside its admin-set window (see the dashboard "Registration window").
    // Only the page title lives here: the messages themselves are admin
    // content, edited and translated on the admin "Closed Page" console
    // (see ClosedMessage).
    'closed_title' => 'Registration Closed',

    'login_or_register_heading' => 'Click Here to Login or Register:',
    'guest_prompt' => 'Registering for the conference requires an account. If you already have one, please log in; otherwise create a new account first.',
    'register_prompt' => 'You are logged in but have not registered for the conference yet. Click here to register:',
    'logged_in_prompt' => 'You are already registered for the conference.',
];
