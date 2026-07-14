<?php

namespace ConferenceTools\Registration\Tests\Feature;

use Carbon\CarbonImmutable;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\BaseCharge;
use ConferenceTools\Registration\Models\DiscountCode;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Models\Variable;
use ConferenceTools\Registration\Services\ConferenceEdition;
use ConferenceTools\Registration\Services\RegistrationEmails;
use ConferenceTools\Registration\Services\RegistrationStatus;
use ConferenceTools\Registration\Services\VariableInterpolator;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Variable Interpolator. */
#[TestDox('Variable Interpolator')]
class VariableInterpolatorTest extends TestCase
{
    use RefreshDatabase;

    /** The interpolator under test, resolved from the container. */
    private function interpolator(): VariableInterpolator
    {
        return $this->app->make(VariableInterpolator::class);
    }

    /** A charge, discount codes, and questions for the prefixed-token tests. */
    private function seedPrefixedFixtures(): void
    {
        BaseCharge::factory()->create(['name' => 'Conference Fee', 'amount' => 100]);
        DiscountCode::factory()->create(['code' => 'MONSTER', 'formula' => '-10%', 'description' => 'Monster discount']);
        DiscountCode::factory()->disabled()->create(['code' => 'OLD', 'formula' => '-5', 'description' => 'Expired']);

        $section = Section::factory()->create();
        Question::factory()->for($section)->create(['key' => 'nickname', 'label' => 'Nickname']);

        $accommodation = Question::factory()->for($section)->ofType(QuestionType::Radio)
            ->create(['key' => 'accommodation', 'label' => 'Accommodation']);
        $accommodation->options()->create(['value' => 'hotel', 'label' => 'Hotel', 'cost' => 100, 'position' => 0]);
        $accommodation->options()->create(['value' => 'none', 'label' => 'No accommodation', 'cost' => 0, 'position' => 1]);

        $products = Question::factory()->for($section)->ofType(QuestionType::Checkbox)
            ->create(['key' => 'products', 'label' => 'Extras']);
        $products->options()->create(['value' => 'dinner', 'label' => 'Dinner', 'cost' => 20, 'position' => 0]);
        $products->options()->create(['value' => 'tshirt', 'label' => 'T-shirt', 'cost' => 15, 'position' => 1]);

        Question::factory()->for($section)->ofType(QuestionType::DiscountCode)
            ->create(['key' => 'discount', 'label' => 'Discount code']);
    }

    #[DataProvider('texts')]
    #[TestDox('it replaces known tokens and leaves everything else alone')]
    public function test_it_replaces_known_tokens_and_leaves_everything_else_alone(?string $text, ?string $expected): void
    {
        Variable::factory()->create(['name' => 'conf', 'value' => 'ICCM 2026']);
        Variable::factory()->create(['name' => 'fee', 'value' => '100 USD']);

        $this->assertSame($expected, $this->interpolator()->interpolate($text));
    }

    /** Interpolate the given text. */
    public static function texts(): array
    {
        return [
            'null' => [null, null],
            'empty' => ['', ''],
            'no tokens' => ['plain text', 'plain text'],
            'single token' => ['Welcome to {conf}!', 'Welcome to ICCM 2026!'],
            'several tokens' => ['{conf} costs {fee}.', 'ICCM 2026 costs 100 USD.'],
            'repeated token' => ['{fee} + {fee}', '100 USD + 100 USD'],
            'unknown token kept visible' => ['Pay {price}', 'Pay {price}'],
            'malformed tokens kept' => ['{1abc} {a b} {}', '{1abc} {a b} {}'],
            'stray brace' => ['a { b', 'a { b'],
        ];
    }

    #[DataProvider('prefixedTexts')]
    #[TestDox('prefixed tokens resolve against charges codes questions and answers')]
    public function test_prefixed_tokens_resolve_against_charges_codes_questions_and_answers(string $text, string $expected): void
    {
        $this->seedPrefixedFixtures();
        $interpolator = $this->interpolator();
        $interpolator->setAnswers([
            'nickname' => 'Ada',
            'accommodation' => 'hotel',
            'products' => ['dinner', 'tshirt'],
            'discount' => 'monster',
        ]);

        $this->assertSame($expected, $interpolator->interpolate($text));
    }

    /** Interpolate with the prefixed placeholder style. */
    public static function prefixedTexts(): array
    {
        return [
            // Base charges: bare => name, .amount => amount; name match is
            // case-insensitive but outputs the stored name.
            'charge bare' => ['{c:conference fee}', 'Conference Fee'],
            'charge amount, C: prefix' => ['{C:Conference Fee.amount}', '100.00'],
            'unknown charge kept visible' => ['{c:nope}', '{c:nope}'],

            // Discount codes: bare => description, .formula => formula.
            'discount bare' => ['{d:monster}', 'Monster discount'],
            'discount formula, D: prefix' => ['{D:MONSTER.formula}', '-10%'],
            'unknown discount kept visible' => ['{d:nope}', '{d:nope}'],
            'disabled discount kept visible' => ['{d:old}', '{d:old}'],

            // Questions: bare => label, .value => the registrant's answer,
            // .cost => the cost tied to that answer.
            'question label' => ['{q:accommodation}', 'Accommodation'],
            'text answer value' => ['{q:nickname.value}', 'Ada'],
            'choice answer value uses the option label' => ['{q:accommodation.value}', 'Hotel'],
            'multi answer value joins the labels' => ['{q:products.value}', 'Dinner, T-shirt'],
            'text answer has no cost' => ['{q:nickname.cost}', '0'],
            'choice answer cost' => ['{q:accommodation.cost}', '100.00'],
            'multi answer cost is summed' => ['{q:products.cost}', '35.00'],
            'discount question cost is the entered code\'s formula' => ['{q:discount.cost}', '-10%'],
            'unknown question kept visible' => ['{q:nope.value}', '{q:nope.value}'],
            'unknown suffix folds into the key' => ['{q:nickname.bogus}', '{q:nickname.bogus}'],

            'mixed prose' => [
                '{q:accommodation}: {q:accommodation.value} ({q:accommodation.cost}) less {d:monster.formula}',
                'Accommodation: Hotel (100.00) less -10%',
            ],

            // Several prefixed references inside one pair of braces resolve
            // and join with a space, so one block can combine multiple
            // answers without needing separate braces per token.
            'several references in one block' => ['{q:nickname.value q:accommodation.value}', 'Ada Hotel'],
            'mixed prefixes in one block' => ['{q:nickname.value c:conference fee}', 'Ada Conference Fee'],
            'one unresolved reference keeps the whole block visible' => [
                '{q:nickname.value q:nope.value}', '{q:nickname.value q:nope.value}',
            ],
        ];
    }

    #[DataProvider('unansweredTexts')]
    #[TestDox('answer tokens without a registrant context')]
    public function test_answer_tokens_without_a_registrant_context(string $text, string $expected): void
    {
        $this->seedPrefixedFixtures();

        $this->assertSame($expected, $this->interpolator()->interpolate($text));
    }

    /** Interpolate against an owner with no answers. */
    public static function unansweredTexts(): array
    {
        return [
            'value is empty' => ['{q:nickname.value}', ''],
            'cost is zero' => ['{q:accommodation.cost}', '0'],
            'discount cost is empty' => ['{q:discount.cost}', ''],
            'label still resolves' => ['{q:accommodation}', 'Accommodation'],
        ];
    }

    #[TestDox('a zero cost choice and an unrecognized discount entry')]
    public function test_a_zero_cost_choice_and_an_unrecognized_discount_entry(): void
    {
        $this->seedPrefixedFixtures();
        $interpolator = $this->interpolator();
        $interpolator->setAnswers(['accommodation' => 'none', 'discount' => 'not-a-code']);

        // A chosen option whose cost is 0, and a discount entry matching no
        // configured code, per spec: "0" and "" respectively.
        $this->assertSame('0', $interpolator->interpolate('{q:accommodation.cost}'));
        $this->assertSame('', $interpolator->interpolate('{q:discount.cost}'));
    }

    #[TestDox('question label and option value resolve in the current locale')]
    public function test_question_label_and_option_value_resolve_in_the_current_locale(): void
    {
        $this->seedPrefixedFixtures();
        $question = Question::firstWhere('key', 'accommodation');
        $question->storeTranslation('fr', 'label', 'Hébergement');
        $question->options()->firstWhere('value', 'hotel')->storeTranslation('fr', 'label', 'Hôtel');

        app()->setLocale('fr');
        $interpolator = $this->interpolator();
        $interpolator->setAnswers(['accommodation' => 'hotel']);

        $this->assertSame('Hébergement: Hôtel', $interpolator->interpolate('{q:accommodation}: {q:accommodation.value}'));
    }

    #[TestDox('builtin email variables resolve from the settings')]
    public function test_builtin_email_variables_resolve_from_the_settings(): void
    {
        app(RegistrationEmails::class)->update('admin@conf.test', 'no-reply@conf.test');

        $this->assertSame(
            'Write to admin@conf.test, sent by no-reply@conf.test',
            $this->interpolator()->interpolate('Write to {admin_email}, sent by {from_email}'),
        );
    }

    #[TestDox('from email falls back to the host mail from address')]
    public function test_from_email_falls_back_to_the_host_mail_from_address(): void
    {
        // No from-address setting stored; only the host's mail config.
        config()->set('mail.from.address', 'host@conf.test');

        $this->assertSame('host@conf.test', $this->interpolator()->interpolate('{from_email}'));
    }

    #[TestDox('an unconfigured builtin stays visible unless a variable covers it')]
    public function test_an_unconfigured_builtin_stays_visible_unless_a_variable_covers_it(): void
    {
        // No setting and no variable: the token stays visible…
        $this->assertSame('{admin_email}', $this->interpolator()->interpolate('{admin_email}'));

        // …an admin-defined variable can stand in for an unset address…
        Variable::factory()->create(['name' => 'admin_email', 'value' => 'var@conf.test']);
        $this->assertSame('var@conf.test', $this->interpolator()->interpolate('{admin_email}'));

        // …but the configured address wins over the variable once set (it
        // flushes nothing, so flush by saving the variable to drop the
        // memoized map).
        Setting::put(RegistrationEmails::ADMIN_EMAIL, 'cfg@conf.test');
        Variable::firstWhere('name', 'admin_email')->touch();
        $this->assertSame('cfg@conf.test', $this->interpolator()->interpolate('{admin_email}'));
    }

    #[TestDox('conference and site builtins resolve')]
    public function test_conference_and_site_builtins_resolve(): void
    {
        app(ConferenceEdition::class)->update('ICCM Americas', '2026');

        // No rollover yet: the closed-page tokens mirror the current edition.
        // The site URL comes from the bound BrandingProvider (the package's
        // DefaultBranding here, which links to the application root).
        $this->assertSame(
            'ICCM Americas 2026 / ICCM Americas 2026 / '.url('/'),
            $this->interpolator()->interpolate(
                '{conference_name} {conference_year} / {closed_conference_name} {closed_conference_year} / {conference_site_url}',
            ),
        );
    }

    #[TestDox('closed conference tokens name the archived edition after a rollover')]
    public function test_closed_conference_tokens_name_the_archived_edition_after_a_rollover(): void
    {
        app(ConferenceEdition::class)->update('ICCM Americas', '2026');
        app(RegistrationStatus::class)->schedule(now()->subDays(30), now()->subDays(2));
        app(ConferenceEdition::class)->startNext();

        $this->assertSame(
            'ICCM Americas 2027 / ICCM Americas 2026',
            $this->interpolator()->interpolate(
                '{conference_name} {conference_year} / {closed_conference_name} {closed_conference_year}',
            ),
        );
    }

    #[TestDox('opens tokens resolve from the scheduled open date')]
    public function test_opens_tokens_resolve_from_the_scheduled_open_date(): void
    {
        app(RegistrationStatus::class)->schedule(now()->parse('2027-03-05 09:30:00'), null);

        // App timezone is UTC in the test environment, so the timezone
        // abbreviation renders as "UTC".
        $this->assertSame(
            'March 5, 2027 at 9:30 AM (UTC)',
            $this->interpolator()->interpolate('{opens_date} at {opens_time} ({opens_timezone})'),
        );
    }

    #[TestDox('the countdown token resolves a human-readable relative time from the scheduled open date')]
    public function test_the_countdown_token_resolves_a_human_readable_relative_time_from_the_scheduled_open_date(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-03-01 00:00:00'));

        try {
            app(RegistrationStatus::class)->schedule(now()->parse('2027-03-05 00:00:00'), null);

            $this->assertSame(
                '4 days from now',
                $this->interpolator()->interpolate('{opens_countdown}'),
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    #[TestDox('the cost summary token renders the invoice and needs a registrant context')]
    public function test_the_cost_summary_token_renders_the_invoice_and_needs_a_registrant_context(): void
    {
        $this->seedPrefixedFixtures();
        $interpolator = $this->interpolator();

        // Without a registrant context there is nothing to summarize — the
        // token stays visible, like any other unresolvable token.
        $this->assertSame('{cost_summary}', $interpolator->interpolate('{cost_summary}'));

        // "90%" = the subtotal becomes 90% (see DiscountFormula).
        DiscountCode::factory()->create(['code' => 'TEN', 'formula' => '90%']);
        $interpolator->setAnswers(['accommodation' => 'hotel', 'products' => ['dinner'], 'discount' => 'ten']);

        // Charges, then the chosen priced options, then subtotal/discount and
        // the total. No default currency is configured in this fixture, so
        // amounts render as plain numbers.
        $this->assertSame(
            "Conference Fee: 100.00\nHotel: 100.00\nDinner: 20.00\nSubtotal: 220.00\nDiscount (TEN): -22.00\nTotal: 198.00",
            $interpolator->interpolate('{cost_summary}'),
        );
    }

    #[TestDox('the variable map is memoized and flushed when a variable changes')]
    public function test_the_variable_map_is_memoized_and_flushed_when_a_variable_changes(): void
    {
        $variable = Variable::factory()->create(['name' => 'conf', 'value' => 'Old']);
        $interpolator = $this->interpolator();

        // Two calls in a row reuse the memoized map (one query, same result).
        $this->assertSame('Old', $interpolator->interpolate('{conf}'));
        $this->assertSame('Old', $interpolator->interpolate('{conf}'));

        // Saving through the model flushes the singleton's memoized map…
        $variable->update(['value' => 'New']);
        $this->assertSame('New', $interpolator->interpolate('{conf}'));

        // …and so does deleting: the token reappears as-is.
        $variable->delete();
        $this->assertSame('{conf}', $interpolator->interpolate('{conf}'));
    }

    #[TestDox('setExtra tokens win over a like-named admin variable and clear correctly')]
    public function test_set_extra_tokens_win_over_a_like_named_admin_variable_and_clear_correctly(): void
    {
        Variable::factory()->create(['name' => 'leader_name', 'value' => 'From Variable']);
        $interpolator = $this->interpolator();

        // Unset: falls through to the admin variable.
        $this->assertSame('From Variable', $interpolator->interpolate('{leader_name}'));

        $interpolator->setExtra(['leader_name' => 'From Extra', 'invite_link' => 'https://example.test/invite']);
        $this->assertSame('From Extra', $interpolator->interpolate('{leader_name}'));
        $this->assertSame('https://example.test/invite', $interpolator->interpolate('{invite_link}'));

        // Cleared: falls back to the admin variable again.
        $interpolator->setExtra(null);
        $this->assertSame('From Variable', $interpolator->interpolate('{leader_name}'));
    }

    #[TestDox('setAnswers with the group member flag switches cost_summary to the split shape')]
    public function test_set_answers_with_the_group_member_flag_switches_cost_summary_to_the_split_shape(): void
    {
        $this->seedPrefixedFixtures();
        $interpolator = $this->interpolator();

        $interpolator->setAnswers(['accommodation' => 'hotel'], true);

        $this->assertSame(
            "Covered by Your Group Leader:\nConference Fee: 100.00\nTotal Covered: 100.00\n\n"
                ."Your Responsibility:\nHotel: 100.00\nTotal: 100.00",
            $interpolator->interpolate('{cost_summary}'),
        );

        // The default (false) is unaffected — still the plain summary.
        $interpolator->setAnswers(['accommodation' => 'hotel']);
        $this->assertSame(
            "Conference Fee: 100.00\nHotel: 100.00\nTotal: 200.00",
            $interpolator->interpolate('{cost_summary}'),
        );
    }
}
