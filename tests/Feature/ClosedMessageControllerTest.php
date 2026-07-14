<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Database\Seeders\ClosedMessageSeeder;
use ConferenceTools\Registration\Models\ClosedMessage;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The admin "Closed Page" console: the fixed set of closed-page messages is
 * listed with descriptive labels, each edited on its own form page and
 * translated through the shared translations editor.
 */
#[TestDox('Closed Message Controller')]
class ClosedMessageControllerTest extends TestCase
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
            ->get(route('registration.admin.closed'))
            ->assertForbidden();
    }

    #[TestDox('the console lists every message with its label and creates missing rows')]
    public function test_the_console_lists_every_message_with_its_label_and_creates_missing_rows(): void
    {
        // The table starts empty: listing the console materializes the fixed
        // set with the default texts (so the translations editor has rows).
        $this->assertSame(0, ClosedMessage::count());

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.closed'))
            ->assertOk()
            ->assertSee(__('registration::admin.closed_label_before_open'))
            ->assertSee(__('registration::admin.closed_label_opening_soon'))
            ->assertSee(__('registration::admin.closed_label_after_close'))
            ->assertSee(__('registration::admin.closed_label_closed'));

        $this->assertSame(count(ClosedMessage::KEYS), ClosedMessage::count());
    }

    #[TestDox('the seeder is idempotent and preserves edited texts')]
    public function test_the_seeder_is_idempotent_and_preserves_edited_texts(): void
    {
        $this->seed(ClosedMessageSeeder::class);
        $this->assertSame(count(ClosedMessage::KEYS), ClosedMessage::count());

        ClosedMessage::forKey(ClosedMessage::CLOSED)->update(['body' => 'Edited by an admin.']);

        $this->seed(ClosedMessageSeeder::class);
        $this->assertSame(count(ClosedMessage::KEYS), ClosedMessage::count());
        $this->assertSame('Edited by an admin.', ClosedMessage::forKey(ClosedMessage::CLOSED)->body);
    }

    #[TestDox('the edit page shows the message body and token hint')]
    public function test_the_edit_page_shows_the_message_body_and_token_hint(): void
    {
        $message = ClosedMessage::forKey(ClosedMessage::BEFORE_OPEN);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.closed.edit', ClosedMessage::BEFORE_OPEN))
            ->assertOk()
            ->assertSee(__('registration::admin.closed_label_before_open'))
            ->assertSee(__('registration::admin.closed_tokens_hint'))
            ->assertSee(ClosedMessage::DEFAULTS[ClosedMessage::BEFORE_OPEN])
            // The shared translations modal is reachable straight from the form.
            ->assertSee(route('registration.admin.translations', ['closed-message', $message->id]))
            ->assertSee('js-editor-link');
    }

    #[TestDox('saving a message persists it')]
    public function test_saving_a_message_persists_it(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.closed.update', ClosedMessage::AFTER_CLOSE), [
                'body' => 'See you at {conference_site_url} next year!',
            ])
            ->assertRedirect(route('registration.admin.closed'));

        $this->assertSame(
            'See you at {conference_site_url} next year!',
            ClosedMessage::forKey(ClosedMessage::AFTER_CLOSE)->body,
        );
    }

    #[TestDox('the body is required')]
    public function test_the_body_is_required(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.closed.update', ClosedMessage::CLOSED), ['body' => ''])
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
            'edit page' => ['get', 'registration.admin.closed.edit', []],
            'update endpoint' => ['put', 'registration.admin.closed.update', ['body' => 'B']],
        ];
    }

    #[TestDox('the messages are translatable through the shared editor')]
    public function test_the_messages_are_translatable_through_the_shared_editor(): void
    {
        $message = ClosedMessage::forKey(ClosedMessage::CLOSED);

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.translations.save', ['closed-message', $message->id]), [
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
