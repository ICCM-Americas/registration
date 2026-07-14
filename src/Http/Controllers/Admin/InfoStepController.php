<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\InfoStep;
use Illuminate\Http\Request;

/**
 * The admin "Landing page" console: the numbered how-to steps shown to
 * visitors on the public registration landing page — display-only content
 * shown before login (seeded with defaults by InfoStepSeeder). It mirrors the
 * question builder: steps are reordered by drag & drop, created/edited on a
 * form page, and hidden/shown with a toggle (steps carry no visibility rules).
 * The public page numbers what it shows from 1, so both the texts and the
 * number of steps are configurable.
 */
class InfoStepController extends Controller
{
    /** The info-steps console. */
    public function index()
    {
        return view('registration::admin.steps.index', [
            // Translations ride along so the "translated" tags render without
            // an extra query per row.
            'steps' => InfoStep::with('translations')->orderBy('position')->get(),
        ]);
    }

    /**
     * Persist a drag-and-drop rearrangement. Sent as JSON by the list after
     * every drop, mirroring the question builder's reorder endpoint.
     */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'steps' => ['array'],
            'steps.*.id' => ['required', 'integer'],
            'steps.*.position' => ['required', 'integer'],
        ]);

        foreach ($data['steps'] ?? [] as $row) {
            InfoStep::whereKey($row['id'])->update(['position' => $row['position']]);
        }

        return response()->json(['status' => 'ok']);
    }

    /** Show the add-step form. */
    public function create()
    {
        return view('registration::admin.steps.form', ['step' => new InfoStep]);
    }

    /** Add an info step. */
    public function store(Request $request)
    {
        InfoStep::create($this->validateStep($request) + [
            'position' => (int) InfoStep::max('position') + 1,
            'enabled' => true,
        ]);

        return $this->back();
    }

    /** Show the edit form for a step. */
    public function edit(InfoStep $step)
    {
        return view('registration::admin.steps.form', ['step' => $step]);
    }

    /** Save a step's content. */
    public function update(Request $request, InfoStep $step)
    {
        $step->update($this->validateStep($request));

        return $this->back();
    }

    /** Remove a step. */
    public function destroy(InfoStep $step)
    {
        $step->delete();

        return $this->back();
    }

    /** Disable a step so the wizard skips it. */
    public function hide(InfoStep $step)
    {
        $step->update(['enabled' => false]);

        return $this->back();
    }

    /** Re-enable a hidden step. */
    public function show(InfoStep $step)
    {
        $step->update(['enabled' => true]);

        return $this->back();
    }

    /** Validate an info-step form submission. */
    private function validateStep(Request $request): array
    {
        return $request->validate([
            'heading' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
    }

    /** Redirect back to the console with a status message. */
    private function back()
    {
        return redirect()->route($this->routeName('admin.steps'));
    }
}
