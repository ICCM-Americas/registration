<?php

namespace ConferenceTools\Registration\Services\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** A search source over what registrants entered, naming each hit by its registrant. */
abstract class RegistrantSource extends SearchSource
{
    public function __construct(protected RegistrantLabels $labels) {}

    /** {@inheritDoc} */
    public function category(): string
    {
        return 'answers';
    }

    /**
     * The host users with the given ids, keyed by id.
     *
     * @return Collection<int, Model>
     */
    protected function users(iterable $ids): Collection
    {
        return config('registration.user_model')::query()
            ->whereIn('id', collect($ids)->unique()->values())
            ->get()
            ->keyBy('id');
    }

    /**
     * The registrant's status badges.
     *
     * @return list<string>
     */
    protected function badges(Model $user, bool $draft): array
    {
        return array_values(array_filter([
            $draft ? __('registration::admin.search_badge_draft') : null,
            $user->is_group_admin && $user->group_id ? __('registration::admin.search_badge_leader') : null,
        ]));
    }

    /**
     * The secondary link to the registrant's read-only Payments page.
     *
     * @return list<array{label: string, url: string, modal: bool}>
     */
    protected function viewLink(Model $user): array
    {
        return [['label' => __('registration::admin.search_view_registrant'), 'url' => $this->route('admin.payments.show', $user->getKey()), 'modal' => false]];
    }
}
