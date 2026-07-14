<?php

namespace ConferenceTools\Registration\Tests\Fixtures;

use ConferenceTools\Registration\Concerns\InteractsWithRegistration;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Stand-in host user model for the package test suite. Mirrors how a real host
 * application wires the registration trait into its own User model.
 */
class User extends Authenticatable
{
    use InteractsWithRegistration;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];
}
