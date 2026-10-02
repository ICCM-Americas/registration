<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Services\AdminSearch;
use ConferenceTools\Registration\Services\Search\RegistrationDeletion;
use ConferenceTools\Registration\Support\Search\InvalidSearchPattern;
use ConferenceTools\Registration\Support\Search\SearchOptions;
use ConferenceTools\Registration\Support\Search\SearchTarget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Deleting the registrations behind the admin search's answer hits: the
 * confirmation dialog (fetched into the editor modal) and the deletion it
 * submits.
 */
class SearchRegistrationController extends Controller
{
    public function __construct(private RegistrationDeletion $deletion) {}

    /** The confirmation dialog for the selected hits, or for every answer hit of a search when "all" is set. */
    public function preview(Request $request, AdminSearch $search): View
    {
        return view('registration::admin.search.delete-confirm', $this->deletion->preview($this->targets($request, $search)));
    }

    /** Delete what the confirmed dialog lists, then show the outcome in the modal. */
    public function destroy(Request $request): View
    {
        $data = $request->validate([
            'registrants' => ['nullable', 'array'],
            'registrants.*' => ['integer'],
            'guest_targets' => ['nullable', 'array'],
            'guest_targets.*' => ['string'],
            'guests' => ['nullable', 'array'],
            'guests.*' => ['in:guest,registrant'],
            'leaders' => ['nullable', 'array'],
            'leaders.*' => ['nullable', 'integer'],
        ]);

        $this->ensureGuestsChosen($data['guest_targets'] ?? [], $data['guests'] ?? []);
        $plan = $this->deletion->resolve($data['registrants'] ?? [], $data['guests'] ?? []);
        $leaders = array_map('intval', array_filter($data['leaders'] ?? []));
        $this->ensureSuccessors($plan['users'], $leaders);

        return view('registration::admin.search.deleted', $this->deletion->delete($plan, $leaders));
    }

    /**
     * The selected targets: the encoded "targets" checkboxes, or every answer hit when "all" is set.
     *
     * @return Collection<int, SearchTarget>
     */
    private function targets(Request $request, AdminSearch $search): Collection
    {
        if (! $request->boolean('all')) {
            return collect((array) $request->query('targets', []))
                ->map(fn ($encoded): ?SearchTarget => is_string($encoded) ? SearchTarget::decode($encoded) : null)
                ->filter()
                ->values();
        }

        $validator = SearchController::validator($request->query());
        abort_if($validator->fails(), 422);

        try {
            return $search->answerTargets(SearchOptions::fromInput($validator->validated()));
        } catch (InvalidSearchPattern) {
            abort(422);
        }
    }

    /**
     * Refuse until every listed guest has a guest-or-registration choice.
     *
     * @param  list<string>  $guestTargets
     * @param  array<string, string>  $choices
     */
    private function ensureGuestsChosen(array $guestTargets, array $choices): void
    {
        if (array_diff($guestTargets, array_keys($choices)) !== []) {
            throw ValidationException::withMessages(['guests' => __('registration::admin.search_delete_choose_guest')]);
        }
    }

    /**
     * Refuse until every departing leader whose group keeps members has a successor among them.
     *
     * @param  Collection<int, Model>  $users
     * @param  array<int, int>  $leaders
     */
    private function ensureSuccessors(Collection $users, array $leaders): void
    {
        $departing = $users->map(fn (Model $user): int => $user->getKey());

        foreach ($users as $user) {
            $successors = $this->deletion->successors($user, $departing);
            if ($successors && $successors->isNotEmpty() && ! $successors->contains('id', $leaders[$user->getKey()] ?? null)) {
                throw ValidationException::withMessages(['leaders' => __('registration::admin.search_delete_choose_leader')]);
            }
        }
    }
}
