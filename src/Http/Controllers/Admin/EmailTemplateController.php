<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\EmailTemplate;
use ConferenceTools\Registration\Services\RegistrationEmails;
use Illuminate\Http\Request;

/**
 * The admin "Emails" console: the registration email addresses (administrator
 * recipient and sender — runtime settings, see RegistrationEmails), and the
 * subject and body of the fixed registration emails (registrant confirmation,
 * administrator notification, group member invite). The template set never changes — only the
 * texts are edited, with the shared "{...}" variables (see
 * VariableInterpolator) resolved when a registration commits.
 */
class EmailTemplateController extends Controller
{
    public function __construct(private RegistrationEmails $emails) {}

    /** The email templates console. */
    public function index()
    {
        return view('registration::admin.emails.index', [
            'emails' => $this->emails,
            'templates' => collect(EmailTemplate::KEYS)->map(fn (string $key) => EmailTemplate::forKey($key)),
        ]);
    }

    /** Save the admin and from addresses. */
    public function updateAddresses(Request $request)
    {
        $data = $request->validate([
            'admin_email' => ['nullable', 'email', 'max:255'],
            'from_email' => ['nullable', 'email', 'max:255'],
        ]);

        $this->emails->update($data['admin_email'] ?? null, $data['from_email'] ?? null);

        return redirect()
            ->route($this->routeName('admin.emails'))
            ->with('addresses_status', __('registration::admin.email_addresses_saved'));
    }

    /** Save one template's subject and body. */
    public function update(Request $request, string $key)
    {
        abort_unless(in_array($key, EmailTemplate::KEYS, true), 404);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
        ]);

        EmailTemplate::forKey($key)->fill($data)->save();

        return redirect()->route($this->routeName('admin.emails'));
    }
}
