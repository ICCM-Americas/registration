<?php

namespace ConferenceTools\Registration\Http\Middleware;

use Closure;
use ConferenceTools\Registration\Models\ClosedMessage;
use ConferenceTools\Registration\Services\RegistrationStatus;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the registrant-facing routes (landing page and wizard) on the
 * registration window: while registration is closed — outside the window, or
 * held closed by the email guard ({@see RegistrationStatus}) — every gated
 * request answers with the "registration is closed" page instead, carrying
 * the admin-configured message for the current window state.
 */
class EnsureRegistrationIsOpen
{
    public function __construct(private RegistrationStatus $status) {}

    /** Reject registrant-facing requests while the registration window is closed. */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->status->isOpen()) {
            return response()->view('registration::closed', [
                'closedMessage' => ClosedMessage::forKey($this->status->closedMessageKey()),
            ]);
        }

        return $next($request);
    }
}
