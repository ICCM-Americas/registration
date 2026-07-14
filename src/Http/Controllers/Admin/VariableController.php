<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\Variable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The admin "Variables" console: the values interpolated into registrant-facing
 * texts wherever "{name}" is written (landing-page steps, question labels/help/
 * placeholders, option labels/descriptions). The name is fixed once created —
 * renaming would silently break every text referencing the token — so editing
 * is limited to the value.
 */
class VariableController extends Controller
{
    /** The text-variables console. */
    public function index()
    {
        return view('registration::admin.variables.index', [
            'variables' => Variable::orderBy('name')->get(),
        ]);
    }

    /** Add a variable. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:64',
                'regex:/^[A-Za-z][A-Za-z0-9_]*$/',
                Rule::unique(Variable::make()->getTable(), 'name'),
            ],
            'value' => $this->valueRules(),
        ]);

        Variable::create($data);

        return $this->back();
    }

    /** Save a variable's value. */
    public function update(Request $request, Variable $variable)
    {
        $variable->update($request->validate(['value' => $this->valueRules()]));

        return $this->back();
    }

    /** Remove a variable. */
    public function destroy(Variable $variable)
    {
        $variable->delete();

        return $this->back();
    }

    /** The validation rules for a variable value. */
    private function valueRules(): array
    {
        return ['required', 'string', 'max:1000'];
    }

    /** Redirect back to the console with a status message. */
    private function back()
    {
        return redirect()->route($this->routeName('admin.variables'));
    }
}
