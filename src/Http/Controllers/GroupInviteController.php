<?php

namespace ConferenceTools\Registration\Http\Controllers;

use ConferenceTools\Registration\Models\GroupInvite;
use Illuminate\Http\Request;

/**
 * Lands an invited group member from their email link. The invite's token is
 * the link's sole credential — deliberately not tied to any particular
 * account/email, since the invitee may sign up with a different one than the
 * leader typed for them (see GroupInvite's own doc comment). This controller
 * only remembers the invite in the session and hands off to the host's own
 * login/signup; {@see RegistrationController} is what actually excludes the
 * group-registration question and attaches the resulting account to the
 * leader's group once the invitee reaches the wizard.
 */
class GroupInviteController extends Controller
{
    /** The session key {@see RegistrationController} reads to resolve the pending invite. */
    public const SESSION_KEY = 'registration.group_invite_token';

    /** Remember an unconsumed invite for this browser session, then send it to log in or sign up. */
    public function accept(Request $request, string $token)
    {
        GroupInvite::where('token', $token)->whereNull('consumed_at')->firstOrFail();

        $request->session()->put(self::SESSION_KEY, $token);

        return redirect()->route(config('registration.login_route'))
            ->with('status', __('registration::common.invite_login_notice'));
    }
}
