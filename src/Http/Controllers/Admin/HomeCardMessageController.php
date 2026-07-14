<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\HomeCardMessage;
use Illuminate\Http\Request;

/**
 * The admin "Home Card Messages" console: the messages shown on the host
 * application's home dashboard registration card while registration is not
 * open, one per key (see HomeCardMessage::KEYS). The set is fixed — nothing
 * is added, removed, hidden or reordered — so the console is a labeled list
 * with a form page per message (mirroring the Closed Page console) plus the
 * shared translations editor.
 */
class HomeCardMessageController extends Controller
{
    /** The home card messages console. */
    public function index()
    {
        return view('registration::admin.home-card-messages.index', [
            // forKey() creates a missing row with its default text, so the
            // list (and the translations editor, which needs a row id) works
            // even before the seeder has run.
            'messages' => collect(HomeCardMessage::KEYS)
                ->map(fn (string $key) => HomeCardMessage::forKey($key)->load('translations')),
        ]);
    }

    /** Show the edit form for one message. */
    public function edit(string $key)
    {
        return view('registration::admin.home-card-messages.form', [
            'homeCardMessage' => $this->message($key),
        ]);
    }

    /** Save one message's text. */
    public function update(Request $request, string $key)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $this->message($key)->update($data);

        return redirect()->route($this->routeName('admin.home_card_messages'));
    }

    /** The message record for a known key, or 404. */
    private function message(string $key): HomeCardMessage
    {
        abort_unless(in_array($key, HomeCardMessage::KEYS, true), 404);

        return HomeCardMessage::forKey($key);
    }
}
