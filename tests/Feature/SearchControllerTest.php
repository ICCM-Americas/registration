<?php

namespace ConferenceTools\Registration\Tests\Feature;

use Closure;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Services\AdminSearch;
use ConferenceTools\Registration\Support\Search\SearchHit;
use ConferenceTools\Registration\Support\Search\SearchOptions;
use ConferenceTools\Registration\Tests\Concerns\BuildsSearchData;
use ConferenceTools\Registration\Tests\Fixtures\User;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The admin Search page: the form, what each place finds, how each hit
 * links to where it is edited, and paging. Deleting registrations from
 * answer hits is covered by {@see SearchRegistrationControllerTest}.
 */
#[TestDox('Search Controller')]
class SearchControllerTest extends TestCase
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

    #[TestDox('requires the gate')]
    public function test_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())->get(route('registration.admin.search'))->assertForbidden();
    }

    #[TestDox('the toolbar links to the page, which first shows an empty form with nothing checked')]
    public function test_the_toolbar_links_to_the_page_which_first_shows_an_empty_form(): void
    {
        $this->actingAs($this->makeUser())->get(route('registration.admin.dashboard'))
            ->assertSee('href="'.route('registration.admin.search').'"', false);

        $this->actingAs($this->makeUser())->get(route('registration.admin.search'))
            ->assertOk()
            ->assertSee('name="in[]"', false)
            ->assertDontSee('" checked>', false)
            ->assertDontSee('js-search-results" data-category=', false);
    }

    /** Searches the form refuses, with the lang key of the message it shows. */
    public static function rejectedSearches(): array
    {
        return [
            'nothing to search checked' => [['q' => 'alpha'], 'search_choose_place'],
            'invalid regular expression' => [['q' => '(alpha', 'regex' => 1, 'in' => ['question_text']], 'search_invalid_regex'],
        ];
    }

    #[DataProvider('rejectedSearches')]
    #[TestDox('refuses a search with $_dataName, keeping the form')]
    public function test_refuses_a_search(array $query, string $messageKey): void
    {
        $message = explode(':', __('registration::admin.'.$messageKey))[0];

        $this->actingAs($this->makeUser())->get(route('registration.admin.search', $query))
            ->assertOk()
            ->assertSee($message)
            ->assertSee('value="'.$query['q'].'"', false)
            ->assertDontSee('js-search-results" data-category=', false);
    }

    /** Each kind of hit: place, unique term, field label key, expected link, and whether it opens the modal. */
    public static function hits(): array
    {
        $questions = fn (array $d, string $anchor): string => route('registration.admin.questions').$anchor;
        $editQuestion = fn (array $d): string => route('registration.admin.questions.edit', $d['question']);
        $editOption = fn (array $d): string => route('registration.admin.questions.edit', $d['question']).'#option-'.$d['option']->id;
        $editReport = fn (array $d): string => route('registration.admin.reports.edit', $d['report']);
        $showDrafter = fn (array $d): string => route('registration.admin.payments.show', $d['drafter']->id);

        return [
            'section title' => ['question_text', 'alphastitle', 'section_title', fn (array $d) => $questions($d, '#section-'.$d['section']->id), false],
            'section key' => ['question_text', 'alphaskey', 'section_key', fn (array $d) => $questions($d, '#section-'.$d['section']->id), false],
            'section description' => ['question_text', 'alphasdesc', 'section_description', fn (array $d) => $questions($d, '#section-'.$d['section']->id), false],
            'section translation' => ['question_text', 'alphastrans', 'section_title', fn (array $d) => route('registration.admin.translations', ['section', $d['section']->id]), true],
            'question label' => ['question_text', 'alphaqlabel', 'label', $editQuestion, false],
            'question key' => ['question_text', 'alphaqkey', 'key', $editQuestion, false],
            'question help text' => ['question_text', 'alphahelp', 'help_text', $editQuestion, false],
            'question placeholder' => ['question_text', 'alphaplace', 'placeholder', $editQuestion, false],
            'question translation' => ['question_text', 'alphaqtrans', 'label', fn (array $d) => route('registration.admin.translations', ['question', $d['question']->id]), true],
            'option value' => ['options', 'alphaoval', 'option_value', $editOption, false],
            'option label' => ['options', 'alphaolabel', 'option_label', $editOption, false],
            'option description' => ['options', 'alphaodesc', 'option_description', $editOption, false],
            'option translation' => ['options', 'alphaotrans', 'option_label', $editOption, false],
            'section rule' => ['question_rules', 'alphasrule', 'section_rule', fn (array $d) => route('registration.admin.sections.visibility', $d['section']), true],
            'question rule' => ['question_rules', 'alphaqrule', 'question_rule', fn (array $d) => route('registration.admin.questions.visibility', $d['question']), true],
            'nested option rule' => ['question_rules', 'alphaorule', 'option_rule', fn (array $d) => route('registration.admin.options.visibility', $d['option']), true],
            'report name' => ['report_text', 'alpharname', 'report_name', $editReport, false],
            'report description' => ['report_text', 'alpharddesc', 'report_description', $editReport, false],
            'report header' => ['report_text', 'alpharhead', 'report_header', $editReport, false],
            'report footer' => ['report_text', 'alpharfoot', 'report_footer', $editReport, false],
            'column header' => ['report_columns', 'alphacolhead', 'column_header', fn (array $d) => $editReport($d).'#column-'.$d['column']->id, false],
            'mapping value' => ['report_columns', 'alphamapval', 'mapping_value', fn (array $d) => route('registration.admin.report_columns.mapping', $d['column']), true],
            'mapping text' => ['report_columns', 'alphamaptext', 'mapping_text', fn (array $d) => route('registration.admin.report_columns.mapping', $d['column']), true],
            'report row rule' => ['report_rules', 'alpharrule', 'report_rule', fn (array $d) => route('registration.admin.reports.visibility', $d['report']), true],
            'column cell rule' => ['report_rules', 'alphacrule', 'column_rule', fn (array $d) => route('registration.admin.report_columns.visibility', $d['column']), true],
            'registrant answer' => ['answers', 'alphaanswer', 'answer', fn (array $d) => route('registration.admin.payments.answers.edit', [$d['registrant']->id, 'highlight' => 'nickname']), false],
            'guest answer' => ['answers', 'alphaguest', 'answer', fn (array $d) => route('registration.admin.payments.guests.answers.edit', [$d['registrant']->id, $d['guest']->id, 'highlight' => 'guestname']), false],
            'draft answer' => ['drafts', 'alphadraft', 'answer', $showDrafter, false],
            'draft multi-value answer' => ['drafts', 'alphalist', 'answer', $showDrafter, false],
            'draft guest answer' => ['drafts', 'alphadguest', 'answer', $showDrafter, false],
            'draft answer to a question no longer asked' => ['drafts', 'alpharetired', 'answer', $showDrafter, false],
        ];
    }

    #[DataProvider('hits')]
    #[TestDox('finds a $_dataName, bolded and linked to where it is edited')]
    public function test_finds_each_kind_of_hit(string $place, string $term, string $field, Closure $url, bool $modal): void
    {
        $search = $this->searchUrl($term, [$place], ['translations' => 1]);

        $this->actingAs($this->makeUser())
            ->get($search)
            ->assertOk()
            ->assertSee('<strong>'.$term.'</strong>', false)
            ->assertSee('<span class="badge badge-info">'.__('registration::admin.search_field_'.$field).'</span>', false)
            ->assertSee('<a href="'.e(SearchHit::returning($url($this->data), $modal, $search)).'" class="'.($modal ? 'js-editor-link' : '').'">', false);
    }

    #[TestDox('has a label for every field a hit can name')]
    public function test_has_a_label_for_every_field_a_hit_can_name(): void
    {
        foreach (array_unique(array_column(self::hits(), 2)) as $field) {
            $this->assertTrue(Lang::has('registration::admin.search_field_'.$field, 'en'), $field);
        }
    }

    /** Term, places, flags, and how many question hits result. */
    public static function narrowedSearches(): array
    {
        return [
            'translations only when asked' => ['alphaqtrans', ['question_text'], [], 0],
            'translations when asked' => ['alphaqtrans', ['question_text'], ['translations' => 1], 1],
            'any case by default' => ['ALPHAQLABEL', ['question_text'], [], 1],
            'case sensitive when asked' => ['ALPHAQLABEL', ['question_text'], ['case' => 1], 0],
            'regex when asked' => ['^alpha.label$', ['options'], ['regex' => 1], 1],
            'regex metacharacters literal otherwise' => ['^alpha.label$', ['options'], [], 0],
            'only the checked places' => ['alphaqrule', ['question_text', 'options'], [], 0],
        ];
    }

    #[DataProvider('narrowedSearches')]
    #[TestDox('searches $_dataName')]
    public function test_narrows_searches(string $term, array $places, array $flags, int $count): void
    {
        $this->actingAs($this->makeUser())
            ->get($this->searchUrl($term, $places, $flags))
            ->assertOk()
            ->assertSee(__('registration::admin.search_results_heading', ['category' => __('registration::admin.search_category_questions'), 'count' => $count]));
    }

    #[TestDox('keeps the submitted choices checked and shows a card only for categories searched')]
    public function test_keeps_the_submitted_choices_checked(): void
    {
        $response = $this->actingAs($this->makeUser())->get($this->searchUrl('alpha', ['options'], ['regex' => 1]));
        $checked = fn (string $id): bool => (bool) preg_match('/id="'.$id.'"[^>]*\schecked>/', $response->getContent());

        $this->assertTrue($checked('search-regex') && $checked('search-in-options'));
        $this->assertFalse($checked('search-case') || $checked('search-in-question_text'));
        $response->assertSee('data-category="questions"', false)
            ->assertDontSee('js-search-results" data-category="reports"', false)
            ->assertDontSee('js-search-results" data-category="answers"', false);
    }

    #[TestDox('pages each category separately, keeping the search in the page links')]
    public function test_pages_each_category_separately(): void
    {
        Question::factory()->count(AdminSearch::PER_PAGE + 5)->create(['label' => 'pagezz', 'help_text' => null, 'section_id' => $this->data['section']->id]);
        $url = $this->searchUrl('pagezz', ['question_text']);

        $first = $this->actingAs($this->makeUser())->get($url)->assertOk();
        $this->assertSame(AdminSearch::PER_PAGE, substr_count($first->getContent(), '<strong>pagezz</strong>'));
        $first->assertSee('questions_page=2', false)->assertSee('q=pagezz', false);

        $second = $this->actingAs($this->makeUser())->get($url.'&questions_page=2')->assertOk();
        $this->assertSame(5, substr_count($second->getContent(), '<strong>pagezz</strong>'));
    }

    #[TestDox('names answer hits by registrant (email when unnamed), with status badges and selection')]
    public function test_names_answer_hits_by_registrant(): void
    {
        $this->data['registrant']->forceFill(['is_group_admin' => true])->save();

        $this->actingAs($this->makeUser())
            ->get($this->searchUrl('alpha(answer|dguest)', ['answers', 'drafts'], ['regex' => 1]))
            ->assertSee('Ada Lovelace')
            ->assertSee(__('registration::admin.search_badge_leader'))
            ->assertSee('drafter@example.com')
            ->assertSee(__('registration::admin.search_badge_draft'))
            ->assertSee(__('registration::admin.search_guest', ['type' => __('registration::common.guest_type_minor')]))
            ->assertSee('value="user:'.$this->data['registrant']->id.'"', false)
            ->assertSee('value="draft-guest:'.$this->data['drafter']->id.':dg-1"', false)
            ->assertSee(e(route('registration.admin.search.registrations.preview', ['targets' => ['user:'.$this->data['registrant']->id]])), false)
            ->assertSee(__('registration::admin.search_select_page'));
    }

    #[TestDox('lists a registrant\'s own answers before their guests\', each guest named')]
    public function test_lists_a_registrants_own_answers_before_their_guests(): void
    {
        $content = $this->actingAs($this->makeUser())
            ->get($this->searchUrl('alpha(answer|guest)', ['answers'], ['regex' => 1]))
            ->assertSee(__('registration::admin.search_guest_named', ['type' => __('registration::common.guest_type_adult'), 'name' => 'alphaguest']), false)
            ->getContent();

        $this->assertLessThan(strpos($content, '<strong>alphaguest</strong>'), strpos($content, '<strong>alphaanswer</strong>'));
    }

    #[TestDox('skips answers and drafts whose owner is gone')]
    public function test_skips_answers_and_drafts_whose_owner_is_gone(): void
    {
        Answer::create(['question_id' => $this->data['gender']->id, 'owner_type' => (new User)->getMorphClass(), 'owner_id' => 9999, 'value' => 'alphaorphan']);
        Answer::create(['question_id' => $this->data['gender']->id, 'owner_type' => (new Guest)->getMorphClass(), 'owner_id' => 9999, 'value' => 'alphaorphan']);
        Draft::factory()->create(['user_id' => 9999, 'answers' => ['nickname' => 'alphaorphan']]);

        $this->actingAs($this->makeUser())
            ->get($this->searchUrl('alphaorphan', SearchOptions::CATEGORIES['answers']))
            ->assertSee(__('registration::admin.search_results_heading', ['category' => __('registration::admin.search_category_answers'), 'count' => 0]))
            ->assertSee(__('registration::admin.search_no_matches'));
    }
}
