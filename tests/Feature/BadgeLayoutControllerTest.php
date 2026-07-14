<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\BadgeElementType;
use ConferenceTools\Registration\Enums\BadgeSheetSize;
use ConferenceTools\Registration\Models\BadgeLayoutElement;
use ConferenceTools\Registration\Services\ConferenceEdition;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Badge Layout Controller. */
#[TestDox('Badge Layout Controller')]
class BadgeLayoutControllerTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedReportQuestions();
    }

    /** The layout routes for the data provider. */
    public static function layoutRoutes(): array
    {
        return [
            'index' => ['get', 'registration.admin.logistics.badges.layout', []],
            'store' => ['post', 'registration.admin.logistics.badges.layout.store', []],
            'seed' => ['post', 'registration.admin.logistics.badges.layout.seed', []],
        ];
    }

    #[DataProvider('layoutRoutes')]
    #[TestDox('layout routes require the gate')]
    public function test_layout_routes_require_the_gate(string $method, string $route, array $params): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->$method(route($route, $params))
            ->assertForbidden();
    }

    #[TestDox('index renders with no custom layout')]
    public function test_index_renders_with_no_custom_layout(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.badges.layout'))
            ->assertOk()
            ->assertSee(__('registration::admin.badge_layout_empty'));
    }

    #[TestDox('seed defaults creates the four built in elements once')]
    public function test_seed_defaults_creates_the_four_built_in_elements_once(): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.logistics.badges.layout.seed', ['size' => 'avery_5392']))
            ->assertRedirect();

        $this->assertSame(4, BadgeLayoutElement::where('sheet_size', BadgeSheetSize::Avery5392)->count());

        // Idempotent: seeding again while rows exist changes nothing.
        BadgeLayoutElement::where('sheet_size', BadgeSheetSize::Avery5392)->first()->update(['x_pct' => 99]);
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.logistics.badges.layout.seed', ['size' => 'avery_5392']));
        $this->assertSame(4, BadgeLayoutElement::where('sheet_size', BadgeSheetSize::Avery5392)->count());
    }

    #[TestDox('store creates an element and enforces singular and repeatable limits')]
    public function test_store_creates_an_element_and_enforces_singular_and_repeatable_limits(): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.logistics.badges.layout.store'), [
                'sheet_size' => 'avery_5392', 'type' => 'organization',
            ])->assertRedirect();
        $this->assertSame(1, BadgeLayoutElement::where('type', BadgeElementType::Organization)->count());

        // A second singular element for the same size is rejected.
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.logistics.badges.layout.store'), [
                'sheet_size' => 'avery_5392', 'type' => 'organization',
            ])->assertSessionHasErrors('type');
        $this->assertSame(1, BadgeLayoutElement::where('type', BadgeElementType::Organization)->count());

        // The same singular type for a *different* size is independent.
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.logistics.badges.layout.store'), [
                'sheet_size' => 'avery_l4728', 'type' => 'organization',
            ])->assertRedirect();
        $this->assertSame(2, BadgeLayoutElement::where('type', BadgeElementType::Organization)->count());

        // Repeatable types allow up to two, then reject a third.
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.logistics.badges.layout.store'), [
                'sheet_size' => 'avery_5392', 'type' => 'static_text',
            ])->assertRedirect();
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.logistics.badges.layout.store'), [
                'sheet_size' => 'avery_5392', 'type' => 'static_text',
            ])->assertRedirect();
        $this->assertSame(2, BadgeLayoutElement::where('sheet_size', BadgeSheetSize::Avery5392)->where('type', BadgeElementType::StaticText)->count());

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.logistics.badges.layout.store'), [
                'sheet_size' => 'avery_5392', 'type' => 'static_text',
            ])->assertSessionHasErrors('type');
        $this->assertSame(2, BadgeLayoutElement::where('sheet_size', BadgeSheetSize::Avery5392)->where('type', BadgeElementType::StaticText)->count());
    }

    #[TestDox('update position moves an element')]
    public function test_update_position_moves_an_element(): void
    {
        $element = BadgeLayoutElement::factory()->create(['x_pct' => 10, 'y_pct' => 10]);

        $this->actingAs($this->makeUser())
            ->patchJson(route('registration.admin.logistics.badges.layout.position', $element), [
                'x_pct' => 55.5, 'y_pct' => 20,
            ])->assertOk()->assertJson(['status' => 'ok']);

        $element->refresh();
        $this->assertSame(55.5, $element->x_pct);
        $this->assertSame(20.0, $element->y_pct);
    }

    #[TestDox('update position rejects a malformed payload')]
    public function test_update_position_rejects_a_malformed_payload(): void
    {
        $element = BadgeLayoutElement::factory()->create();

        $this->actingAs($this->makeUser())
            ->patchJson(route('registration.admin.logistics.badges.layout.position', $element), [
                'x_pct' => 'not-a-number', 'y_pct' => 20,
            ])->assertUnprocessable();
    }

    #[TestDox('update saves text element fields')]
    public function test_update_saves_text_element_fields(): void
    {
        $element = BadgeLayoutElement::factory()->ofType(BadgeElementType::StaticText)->create();

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.logistics.badges.layout.update', $element), [
                'width_pct' => 60, 'align' => 'right', 'font_size_pt' => 14,
                'bold' => '1', 'text' => 'Sponsored by Acme',
            ])->assertRedirect();

        $element->refresh();
        $this->assertSame(60.0, $element->width_pct);
        $this->assertSame('right', $element->align);
        $this->assertSame(14, $element->font_size_pt);
        $this->assertTrue($element->bold);
        $this->assertFalse($element->italic);
        $this->assertSame('Sponsored by Acme', $element->text);
    }

    #[TestDox('update uploads and stores a static image as base64')]
    public function test_update_uploads_and_stores_a_static_image_as_base64(): void
    {
        $element = BadgeLayoutElement::factory()->image()->create();

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.logistics.badges.layout.update', $element), [
                'width_pct' => 30, 'align' => 'center',
                'image' => UploadedFile::fake()->image('sponsor.png'),
            ])->assertRedirect();

        $element->refresh();
        $this->assertNotNull($element->image_data);
        $this->assertNotFalse(base64_decode($element->image_data, true));
        $this->assertSame('image/png', $element->image_mime);
    }

    #[DataProvider('invalidUpdatePayloads')]
    #[TestDox('update validation rejects bad input')]
    public function test_update_validation_rejects_bad_input(array $extra, string $field): void
    {
        $element = BadgeLayoutElement::factory()->ofType(BadgeElementType::StaticText)->create();

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.logistics.badges.layout.update', $element), array_merge([
                'width_pct' => 50, 'align' => 'center', 'font_size_pt' => 12, 'text' => 'Hello',
            ], $extra))
            ->assertSessionHasErrors($field);
    }

    /** The invalid update payloads for the data provider. */
    public static function invalidUpdatePayloads(): array
    {
        return [
            'bad alignment' => [['align' => 'diagonal'], 'align'],
            'font size too small' => [['font_size_pt' => 1], 'font_size_pt'],
            'font size too large' => [['font_size_pt' => 999], 'font_size_pt'],
            'missing text' => [['text' => ''], 'text'],
        ];
    }

    #[TestDox('destroy removes the element')]
    public function test_destroy_removes_the_element(): void
    {
        $element = BadgeLayoutElement::factory()->create();

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.logistics.badges.layout.destroy', $element))
            ->assertRedirect();

        $this->assertSame(0, BadgeLayoutElement::count());
    }

    #[TestDox('badges report falls back to the default layout when no elements exist')]
    public function test_badges_report_falls_back_to_the_default_layout_when_no_elements_exist(): void
    {
        app(ConferenceEdition::class)->update('ICCM Test', '2026');
        $this->makeRegistrant('Ada', 'Lovelace', ['badgename' => 'Ada L.']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.badges'))
            ->assertOk()
            ->assertSee('ICCM Test 2026')
            ->assertSee('Ada L.')
            ->assertSee('Analytical Engines');
    }

    #[TestDox('badges report renders a custom layout when one exists')]
    public function test_badges_report_renders_a_custom_layout_when_one_exists(): void
    {
        $this->makeRegistrant('Ada', 'Lovelace', ['badgename' => 'Ada L.']);
        // The default sheet size for the test suite's plain "en" locale (see ReportPagesTest).
        $size = BadgeSheetSize::Avery5392;
        BadgeLayoutElement::factory()->forSize($size)->ofType(BadgeElementType::BadgeName)->create();
        BadgeLayoutElement::factory()->forSize($size)->ofType(BadgeElementType::StaticText)->create(['text' => 'Sponsored by Acme']);

        $content = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.badges'))
            ->assertOk()
            ->assertSee('Ada L.')
            ->assertSee('Sponsored by Acme')
            ->getContent();

        // Organization was never added to this custom layout, so the rendered
        // cards don't show it. The embedded PDF payload legitimately carries
        // the full badge data (an admin may add an organization element any
        // time), so strip it before asserting on the visible page.
        $visible = preg_replace('/<script type="application\/json"[^>]*>.*?<\/script>/s', '', $content);
        $this->assertStringNotContainsString('Analytical Engines', $visible);
    }
}
