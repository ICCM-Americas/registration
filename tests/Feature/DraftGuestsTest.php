<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Services\DraftGuests;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * CRUD over a Draft's "guests" JSON column: each guest is addressed by a
 * stable UUID (not array position), so removing one guest never shifts the
 * ids of the others.
 */
#[TestDox('Draft Guests')]
class DraftGuestsTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('all is empty for a fresh draft')]
    public function test_all_is_empty_for_a_fresh_draft(): void
    {
        $draft = Draft::factory()->create();

        $this->assertSame([], app(DraftGuests::class)->all($draft));
    }

    #[TestDox('add appends a guest with a stable id')]
    public function test_add_appends_a_guest_with_a_stable_id(): void
    {
        $draft = Draft::factory()->create();
        $draftGuests = app(DraftGuests::class);

        $id = $draftGuests->add($draft, GuestType::Adult, ['name' => 'Ada']);

        $this->assertNotSame('', $id);
        $entry = $draftGuests->find($draft->fresh(), $id);
        $this->assertSame(['id' => $id, 'type' => 'adult', 'answers' => ['name' => 'Ada']], $entry);
    }

    #[TestDox('add twice keeps both guests with distinct ids')]
    public function test_add_twice_keeps_both_guests_with_distinct_ids(): void
    {
        $draft = Draft::factory()->create();
        $draftGuests = app(DraftGuests::class);

        $firstId = $draftGuests->add($draft, GuestType::Adult, ['name' => 'Ada']);
        $secondId = $draftGuests->add($draft, GuestType::Minor, ['name' => 'Bea']);

        $this->assertNotSame($firstId, $secondId);
        $all = $draftGuests->all($draft->fresh());
        $this->assertCount(2, $all);
        $this->assertSame(['adult', 'minor'], array_column($all, 'type'));
    }

    #[TestDox('update replaces only the targeted guests answers')]
    public function test_update_replaces_only_the_targeted_guests_answers(): void
    {
        $draft = Draft::factory()->create();
        $draftGuests = app(DraftGuests::class);
        $id = $draftGuests->add($draft, GuestType::Adult, ['name' => 'Ada']);

        $draftGuests->update($draft, $id, ['name' => 'Ada Updated']);

        $entry = $draftGuests->find($draft->fresh(), $id);
        $this->assertSame(['name' => 'Ada Updated'], $entry['answers']);
        $this->assertSame('adult', $entry['type']); // type is unaffected by update()
    }

    #[TestDox('remove deletes only the targeted guest and never renumbers the others')]
    public function test_remove_deletes_only_the_targeted_guest_and_never_renumbers_the_others(): void
    {
        $draft = Draft::factory()->create();
        $draftGuests = app(DraftGuests::class);
        $firstId = $draftGuests->add($draft, GuestType::Adult, ['name' => 'Ada']);
        $secondId = $draftGuests->add($draft, GuestType::Minor, ['name' => 'Bea']);

        $draftGuests->remove($draft, $firstId);

        $all = $draftGuests->all($draft->fresh());
        $this->assertCount(1, $all);
        $this->assertSame($secondId, $all[0]['id']);
        $this->assertNull($draftGuests->find($draft->fresh(), $firstId));
    }

    #[TestDox('find returns null for an unknown id')]
    public function test_find_returns_null_for_an_unknown_id(): void
    {
        $draft = Draft::factory()->create();

        $this->assertNull(app(DraftGuests::class)->find($draft, 'not-a-real-id'));
    }
}
