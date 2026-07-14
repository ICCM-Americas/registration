<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Services\DraftGroupMembers;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * CRUD over a Draft's "group_members" JSON column: each invitee is
 * addressed by a stable UUID (not array position), so removing one member
 * never shifts the ids of the others — mirrors DraftGuestsTest.
 */
#[TestDox('Draft Group Members')]
class DraftGroupMembersTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('all is empty for a fresh draft')]
    public function test_all_is_empty_for_a_fresh_draft(): void
    {
        $draft = Draft::factory()->create();

        $this->assertSame([], app(DraftGroupMembers::class)->all($draft));
    }

    #[TestDox('add appends a group member with a stable id')]
    public function test_add_appends_a_group_member_with_a_stable_id(): void
    {
        $draft = Draft::factory()->create();
        $draftMembers = app(DraftGroupMembers::class);

        $id = $draftMembers->add($draft, ['group_member_name' => 'Ada', 'group_member_email' => 'ada@example.com']);

        $this->assertNotSame('', $id);
        $entry = $draftMembers->find($draft->fresh(), $id);
        $this->assertSame(['id' => $id, 'answers' => ['group_member_name' => 'Ada', 'group_member_email' => 'ada@example.com']], $entry);
    }

    #[TestDox('add twice keeps both members with distinct ids')]
    public function test_add_twice_keeps_both_members_with_distinct_ids(): void
    {
        $draft = Draft::factory()->create();
        $draftMembers = app(DraftGroupMembers::class);

        $firstId = $draftMembers->add($draft, ['group_member_name' => 'Ada']);
        $secondId = $draftMembers->add($draft, ['group_member_name' => 'Bea']);

        $this->assertNotSame($firstId, $secondId);
        $all = $draftMembers->all($draft->fresh());
        $this->assertCount(2, $all);
        $this->assertSame(['Ada', 'Bea'], array_column(array_column($all, 'answers'), 'group_member_name'));
    }

    #[TestDox('update replaces only the targeted members answers')]
    public function test_update_replaces_only_the_targeted_members_answers(): void
    {
        $draft = Draft::factory()->create();
        $draftMembers = app(DraftGroupMembers::class);
        $id = $draftMembers->add($draft, ['group_member_name' => 'Ada']);

        $draftMembers->update($draft, $id, ['group_member_name' => 'Ada Updated']);

        $entry = $draftMembers->find($draft->fresh(), $id);
        $this->assertSame(['group_member_name' => 'Ada Updated'], $entry['answers']);
    }

    #[TestDox('remove deletes only the targeted member and never renumbers the others')]
    public function test_remove_deletes_only_the_targeted_member_and_never_renumbers_the_others(): void
    {
        $draft = Draft::factory()->create();
        $draftMembers = app(DraftGroupMembers::class);
        $firstId = $draftMembers->add($draft, ['group_member_name' => 'Ada']);
        $secondId = $draftMembers->add($draft, ['group_member_name' => 'Bea']);

        $draftMembers->remove($draft, $firstId);

        $all = $draftMembers->all($draft->fresh());
        $this->assertCount(1, $all);
        $this->assertSame($secondId, $all[0]['id']);
        $this->assertNull($draftMembers->find($draft->fresh(), $firstId));
    }

    #[TestDox('find returns null for an unknown id')]
    public function test_find_returns_null_for_an_unknown_id(): void
    {
        $draft = Draft::factory()->create();

        $this->assertNull(app(DraftGroupMembers::class)->find($draft, 'not-a-real-id'));
    }
}
