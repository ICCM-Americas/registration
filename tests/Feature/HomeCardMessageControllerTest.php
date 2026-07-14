<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Database\Seeders\HomeCardMessageSeeder;
use ConferenceTools\Registration\Models\HomeCardMessage;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The admin "Home Card Messages" console: the fixed set of home-dashboard
 * registration card messages is listed with descriptive labels, each edited
 * on its own form page and translated through the shared translations
 * editor — mirrors the Closed Page console, but for a distinct setting.
 */
#[TestDox('Home Card Message Controller')]
class HomeCardMessageControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
    }

    #[TestDox('the console requires the gate')]
    public function test_the_console_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.home_card_messages'))
            ->assertForbidden();
    }

    #[TestDox('the console lists every message with its label and creates missing rows')]
    public function test_the_console_lists_every_message_with_its_label_and_creates_missing_rows(): void
    {
        $this->assertSame(0, HomeCardMessage::count());

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.home_card_messages'))
            ->assertOk()
            ->assertSee(__('registration::admin.home_card_label_before_open'))
            ->assertSee(__('registration::admin.home_card_label_after_close'));

        $this->assertSame(count(HomeCardMessage::KEYS), HomeCardMessage::count());
    }

    #[TestDox('the seeder is idempotent and preserves edited texts')]
    public function test_the_seeder_is_idempotent_and_preserves_edited_texts(): void
    {
        $this->seed(HomeCardMessageSeeder::class);
        $this->assertSame(count(HomeCardMessage::KEYS), HomeCardMessage::count());

        HomeCardMessage::forKey(HomeCardMessage::AFTER_CLOSE)->update(['body' => 'Edited by an admin.']);

        $this->seed(HomeCardMessageSeeder::class);
        $this->assertSame(count(HomeCardMessage::KEYS), HomeCardMessage::count());
        $this->assertSame('Edited by an admin.', HomeCardMessage::forKey(HomeCardMessage::AFTER_CLOSE)->body);
    }

    #[TestDox('the edit page shows the message body and token hint')]
    public function test_the_edit_page_shows_the_message_body_and_token_hint(): void
    {
        $message = HomeCardMessage::forKey(HomeCardMessage::BEFORE_OPEN);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.home_card_messages.edit', HomeCardMessage::BEFORE_OPEN))
            ->assertOk()
            ->assertSee(__('registration::admin.home_card_label_before_open'))
            ->assertSee(__('registration::admin.closed_tokens_hint'))
            ->assertSee(HomeCardMessage::DEFAULTS[HomeCardMessage::BEFORE_OPEN])
            // The shared translations modal is reachable straight from the form.
            ->assertSee(route('registration.admin.translations', ['home-card-message', $message->id]))
            ->assertSee('js-editor-link');
    }

    #[TestDox('saving a message persists it')]
    public function test_saving_a_message_persists_it(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.home_card_messages.update', HomeCardMessage::AFTER_CLOSE), [
                'body' => 'See you at {conference_site_url} next year!',
            ])
            ->assertRedirect(route('registration.admin.home_card_messages'));

        $this->assertSame(
            'See you at {conference_site_url} next year!',
            HomeCardMessage::forKey(HomeCardMessage::AFTER_CLOSE)->body,
        );
    }

    #[TestDox('the body is required')]
    public function test_the_body_is_required(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.home_card_messages.update', HomeCardMessage::AFTER_CLOSE), ['body' => ''])
            ->assertSessionHasErrors('body');
    }

    /** Both the edit page and the update endpoint reject unknown keys. */
    #[DataProvider('unknownKeyRequests')]
    #[TestDox('an unknown message key is a 404')]
    public function test_an_unknown_message_key_is_a_404(string $method, string $routeName, array $payload): void
    {
        $this->actingAs($this->makeUser())
            ->{$method}(route($routeName, 'weekly_newsletter'), $payload)
            ->assertNotFound();
    }

    /** The unknown key requests for the data provider. */
    public static function unknownKeyRequests(): array
    {
        return [
            'edit page' => ['get', 'registration.admin.home_card_messages.edit', []],
            'update endpoint' => ['put', 'registration.admin.home_card_messages.update', ['body' => 'B']],
        ];
    }

    #[TestDox('the messages are translatable through the shared editor')]
    public function test_the_messages_are_translatable_through_the_shared_editor(): void
    {
        $message = HomeCardMessage::forKey(HomeCardMessage::AFTER_CLOSE);

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.translations.save', ['home-card-message', $message->id]), [
                'locale' => 'fr',
                'texts' => ['self' => ['body' => 'Les inscriptions sont fermées.']],
            ])
            ->assertOk();

        $this->assertSame(
            'Les inscriptions sont fermées.',
            $message->fresh()->translate('body', 'fr'),
        );
    }
}
