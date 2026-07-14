<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Database\Seeders\InfoStepSeeder;
use ConferenceTools\Registration\Models\InfoStep;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Info Step Seeder. */
#[TestDox('Info Step Seeder')]
class InfoStepSeederTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('it seeds the three default english steps')]
    public function test_it_seeds_the_three_default_english_steps(): void
    {
        $this->seed(InfoStepSeeder::class);

        // English literals from the seeder itself — step texts are admin
        // content translated via the DB, not lang-file entries.
        $this->assertSame(
            array_map(fn (array $texts) => $texts['heading'], InfoStepSeeder::DEFAULT_STEPS),
            InfoStep::orderBy('position')->pluck('heading', 'position')->all()
        );
        $this->assertSame(InfoStepSeeder::DEFAULT_STEPS[1]['body'], InfoStep::firstWhere('position', 1)->body);
        $this->assertSame(3, InfoStep::where('enabled', true)->count());
    }

    #[TestDox('reseeding adds nothing and keeps admin edits')]
    public function test_reseeding_adds_nothing_and_keeps_admin_edits(): void
    {
        $this->seed(InfoStepSeeder::class);
        InfoStep::firstWhere('position', 1)->update(['heading' => 'Custom heading', 'enabled' => false]);

        $this->seed(InfoStepSeeder::class);

        $this->assertSame(3, InfoStep::count());
        $this->assertSame('Custom heading', InfoStep::firstWhere('position', 1)->heading);
        $this->assertFalse(InfoStep::firstWhere('position', 1)->enabled);
    }
}
