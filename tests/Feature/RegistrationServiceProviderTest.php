<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Branding\Contracts\BrandingProvider;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Variable;
use ConferenceTools\Registration\RegistrationServiceProvider;
use ConferenceTools\Registration\Services\RegistrationStatus;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Registration Service Provider. */
#[TestDox('Registration Service Provider')]
class RegistrationServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('factory name resolver handles package and host models')]
    public function test_factory_name_resolver_handles_package_and_host_models(): void
    {
        $this->assertSame(
            'ConferenceTools\\Registration\\Database\\Factories\\GroupFactory',
            Factory::resolveFactoryName(Group::class)
        );

        $this->assertSame(
            'Database\\Factories\\WidgetFactory',
            Factory::resolveFactoryName('App\\Models\\Widget')
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publishTags(): iterable
    {
        yield 'config' => ['registration-config'];
        yield 'views' => ['registration-views'];
        yield 'lang' => ['registration-lang'];
        yield 'migrations' => ['registration-migrations'];
        yield 'assets' => ['registration-assets'];
        // The stylesheet also joins Laravel's conventional group, so the stock
        // post-update-cmd composer script republishes it.
        yield 'laravel assets' => ['laravel-assets'];
    }

    #[TestDox('publish groups are registered in console')]
    #[DataProvider('publishTags')]
    public function test_publish_groups_are_registered_in_console(string $tag): void
    {
        $this->assertNotEmpty(
            ServiceProvider::pathsToPublish(RegistrationServiceProvider::class, $tag),
            "Missing publish group: {$tag}"
        );
    }

    #[TestDox('the stylesheet publishes into the web root')]
    public function test_the_stylesheet_publishes_into_the_web_root(): void
    {
        $paths = ServiceProvider::pathsToPublish(RegistrationServiceProvider::class, 'registration-assets');

        $this->assertContains(public_path('vendor/registration/css'), $paths);
        $this->assertContains(realpath(__DIR__.'/../../resources/css'), array_map('realpath', array_keys($paths)));
    }

    #[TestDox('view composer exposes layout branding route name and vars helpers')]
    public function test_view_composer_exposes_layout_branding_route_name_and_vars_helpers(): void
    {
        Variable::factory()->create(['name' => 'conf', 'value' => 'ICCM Africa']);

        // The info view takes its steps from the controller; the composer only
        // adds the helpers, so an empty set stands in for them here.
        $view = view('registration::registration', ['steps' => collect()]);
        $view->render();
        $data = $view->getData();

        $this->assertSame(config('registration.layout'), $data['registrationLayout']);
        $this->assertNotSame('', $data['branding']->siteName());
        $this->assertSame('registration.register', $data['routeName']('register'));
        $this->assertSame('Welcome to ICCM Africa', $data['vars']('Welcome to {conf}'));
        // The label helper interpolates variables and then renders the small
        // Markdown-flavored label convention as HTML (see LabelFormatter).
        $this->assertSame('Welcome to <b>ICCM Africa</b>', $data['label']('Welcome to **{conf}**'));
        $this->assertInstanceOf(RegistrationStatus::class, $data['registrationStatus']);
    }

    #[TestDox('host bound branding flows into the themed layout')]
    public function test_host_bound_branding_flows_into_the_themed_layout(): void
    {
        // Branding is host-owned: the host binds its own BrandingProvider and the
        // package's default layout renders its name, colors and logo. This mirrors
        // how the ICCM-Africa host app brands the package's screens.
        $this->app->instance(BrandingProvider::class, new class implements BrandingProvider
        {
            /** The configured site name fixture. */
            public function siteName(): string
            {
                return 'ICCM-Africa';
            }

            /** The configured color fixture. */
            public function color(string $key): string
            {
                return ['primary' => '#37abc8', 'secondary' => '#5a2ca0', 'background' => '#ffffff', 'text' => '#0f172a'][$key] ?? '#000000';
            }

            /** The configured logo fixture. */
            public function logoUrl(): ?string
            {
                return 'data:image/png;base64,AAAA';
            }

            /** The configured URL fixture. */
            public function url(): string
            {
                return 'https://africa.example.org';
            }
        });

        $html = view('registration::registration', ['steps' => collect()])->render();

        $this->assertStringContainsString('ICCM-Africa', $html);
        $this->assertStringContainsString('#37abc8', $html);
        $this->assertStringContainsString('data:image/png;base64,AAAA', $html);
    }

    #[TestDox('user deletion handling ignores an invalid user model')]
    public function test_user_deletion_handling_ignores_an_invalid_user_model(): void
    {
        // A host that has not wired the trait/model should not break booting: the
        // guard clause must return before touching the bogus class. The contract
        // is purely "does not throw", so there is no state to assert beyond that.
        config(['registration.user_model' => 'Not\\A\\Real\\Class']);
        $this->expectNotToPerformAssertions();

        $provider = new RegistrationServiceProvider($this->app);

        $method = (new \ReflectionClass($provider))->getMethod('registerUserDeletionHandling');
        $method->setAccessible(true);

        $method->invoke($provider);
    }

    #[TestDox('publishing is skipped when not running in console')]
    public function test_publishing_is_skipped_when_not_running_in_console(): void
    {
        $app = \Mockery::mock(Application::class);
        $app->shouldReceive('runningInConsole')->andReturnFalse();

        // Outside the console the gate must short-circuit before registering any
        // publish group: shouldNotReceive throws the moment publishes() is hit.
        $provider = \Mockery::mock(RegistrationServiceProvider::class.'[publishes]', [$app])
            ->shouldAllowMockingProtectedMethods();
        $provider->shouldNotReceive('publishes');

        $method = (new \ReflectionClass(RegistrationServiceProvider::class))->getMethod('registerPublishing');
        $method->setAccessible(true);
        $method->invoke($provider);

        $this->assertFalse($app->runningInConsole());
    }
}
