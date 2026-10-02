<?php

namespace ConferenceTools\Registration\Tests\Feature;

use Closure;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Tests\Concerns\BuildsSearchData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Editors opened from the admin search results come back to them: the
 * search page URL rides along as "_return", and Save and Cancel/Back use it —
 * but only a URL of the search page itself, never another redirect target.
 */
#[TestDox('Search Return')]
class SearchReturnTest extends TestCase
{
    use BuildsSearchData, RefreshDatabase;

    /** @var array<string, mixed> */
    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->data = $this->seedSearchData();
    }

    /** Each editor: its edit URL, its save URL and method, a valid payload, and where Save goes without a return. */
    public static function editors(): array
    {
        return [
            'question' => [
                fn (self $t): string => route('registration.admin.questions.edit', $t->question()),
                fn (self $t): string => route('registration.admin.questions.update', $t->question()),
                fn (self $t): array => ['section_id' => $t->question()->section_id, 'type' => 'text', 'key' => 'nickname', 'label' => 'Nickname'],
                fn (self $t): string => route('registration.admin.questions').'#question-'.$t->question()->id,
            ],
            'report' => [
                fn (self $t): string => route('registration.admin.reports.edit', $t->data['report']),
                fn (self $t): string => route('registration.admin.reports.update', $t->data['report']),
                fn (self $t): array => ['name' => 'Renamed report'],
                fn (self $t): string => route('registration.admin.reports.edit', $t->data['report']),
            ],
            'registrant answers' => [
                fn (self $t): string => route('registration.admin.payments.answers.edit', $t->data['registrant']->id),
                fn (self $t): string => route('registration.admin.payments.answers.update', $t->data['registrant']->id),
                fn (self $t): array => $t->fixtureAnswers(),
                fn (self $t): string => route('registration.admin.payments.show', $t->data['registrant']->id),
            ],
            'guest answers' => [
                fn (self $t): string => route('registration.admin.payments.guests.answers.edit', [$t->data['registrant']->id, $t->data['guest']->id]),
                fn (self $t): string => route('registration.admin.payments.guests.answers.update', [$t->data['registrant']->id, $t->data['guest']->id]),
                fn (self $t): array => ['guestname' => 'Gus'],
                fn (self $t): string => route('registration.admin.payments.show', $t->data['registrant']->id),
            ],
        ];
    }

    #[DataProvider('editors')]
    #[TestDox('the $_dataName editor carries the search results and leads back to them')]
    public function test_the_editor_leads_back_to_the_search_results(Closure $edit, Closure $update, Closure $payload): void
    {
        $results = $this->searchUrl('alpha', ['question_text']);

        $this->actingAs($this->makeUser())
            ->get($edit($this).'?_return='.urlencode($results))
            ->assertOk()
            ->assertSee('<input type="hidden" name="_return" value="'.e($results).'">', false)
            ->assertSee('href="'.e($results).'"', false);

        $this->actingAs($this->makeUser())
            ->put($update($this), $payload($this) + ['_return' => $results])
            ->assertRedirect($results);
    }

    /** Return targets that are not the search page. */
    public static function foreignReturns(): array
    {
        return [
            'another site' => ['https://evil.example/registration/admin/search?q=x'],
            'another admin page' => ['/registration/admin/questions'],
            'a lookalike path' => ['http://localhost/registration/admin/searchx'],
        ];
    }

    #[DataProvider('foreignReturns')]
    #[TestDox('ignores a return to $_dataName')]
    public function test_ignores_a_foreign_return(string $foreign): void
    {
        foreach (self::editors() as [$edit, $update, $payload, $default]) {
            $this->actingAs($this->makeUser())
                ->get($edit($this).'?_return='.urlencode($foreign))
                ->assertOk()
                ->assertDontSee('name="_return"', false);

            $this->actingAs($this->makeUser())
                ->put($update($this), $payload($this) + ['_return' => $foreign])
                ->assertRedirect($default($this));
        }
    }

    /** The fixture's "nickname" question. */
    public function question(): Question
    {
        return Question::where('key', 'nickname')->firstOrFail();
    }
}
