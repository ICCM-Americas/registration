<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Models\Setting;

/**
 * The registration email addresses, edited on the admin "Emails" console: the
 * administrator notification recipient and the sender of both registration
 * emails. They change over time (a new treasurer, a new list), so they are
 * runtime settings — not deploy-time .env configuration. The sender falls
 * back to the host's mail.from address while unset.
 */
class RegistrationEmails
{
    public const ADMIN_EMAIL = 'admin_email';

    public const FROM_EMAIL = 'from_email';

    /** The administrator notification recipient, or null while unconfigured. */
    public function adminEmail(): ?string
    {
        return Setting::get(self::ADMIN_EMAIL);
    }

    /** The configured sender address (without the host fallback applied). */
    public function fromEmail(): ?string
    {
        return Setting::get(self::FROM_EMAIL);
    }

    /** The sender actually used: the configured one, or the host's mail.from. */
    public function effectiveFromEmail(): ?string
    {
        return $this->fromEmail() ?: (config('mail.from.address') ?: null);
    }

    /**
     * Both registration addresses are available: the administrator recipient,
     * and a sender (configured here or the host's mail.from address). This is
     * the email guard that holds registration closed (see RegistrationStatus).
     */
    public function configured(): bool
    {
        return filled($this->adminEmail()) && filled($this->effectiveFromEmail());
    }

    /** Storing an empty address clears it. */
    public function update(?string $adminEmail, ?string $fromEmail): void
    {
        Setting::put(self::ADMIN_EMAIL, filled($adminEmail) ? $adminEmail : null);
        Setting::put(self::FROM_EMAIL, filled($fromEmail) ? $fromEmail : null);
    }
}
