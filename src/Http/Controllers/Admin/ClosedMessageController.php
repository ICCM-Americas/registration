<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\ClosedMessage;
use Illuminate\Http\Request;

/**
 * The admin "Closed Page" console: the messages shown instead of the
 * registration pages while registration is closed, one per window state (see
 * RegistrationStatus::closedMessageKey()). The set is fixed — nothing is
 * added, removed, hidden or reordered — so the console is a labeled list
 * with a form page per message (mirroring the landing-page steps console)
 * plus the shared translations editor.
 */
class ClosedMessageController extends Controller
{
    /** The closed-page messages console. */
    public function index()
    {
        return view('registration::admin.closed-messages.index', [
            // forKey() creates a missing row with its default text, so the
            // list (and the translations editor, which needs a row id) works
            // even before the seeder has run.
            'messages' => collect(ClosedMessage::KEYS)
                ->map(fn (string $key) => ClosedMessage::forKey($key)->load('translations')),
        ]);
    }

    /** Show the edit form for one message. */
    public function edit(string $key)
    {
        return view('registration::admin.closed-messages.form', [
            'closedMessage' => $this->message($key),
        ]);
    }

    /** Save one message's text. */
    public function update(Request $request, string $key)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $this->message($key)->update($data);

        return redirect()->route($this->routeName('admin.closed'));
    }

    /** The message record for a known key, or 404. */
    private function message(string $key): ClosedMessage
    {
        abort_unless(in_array($key, ClosedMessage::KEYS, true), 404);

        return ClosedMessage::forKey($key);
    }
}
