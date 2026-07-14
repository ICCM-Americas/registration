<?php

namespace ConferenceTools\Registration\Tests\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\TestResponse;

/**
 * Drives the server-side registration wizard end to end in feature tests: for
 * each step it reads the rendered hidden "_step" (exactly as a browser would
 * submit it) and posts the whole answer set with direction "next". The wizard
 * validates only the current step's questions, so posting everything each step
 * is harmless and keeps the helper simple.
 */
trait WalksRegistrationWizard
{
    /**
     * The route names the walker drives, keyed step/store/committed. The
     * default drives the real registrant flow; the admin test-drive tests
     * override this with the admin.test.* routes.
     */
    protected function wizardRoutes(): array
    {
        return [
            'step' => 'registration.register',
            'store' => 'registration.register.store',
            'committed' => 'registration.info',
        ];
    }

    /** Walk the wizard to completion for the user. */
    protected function completeWizard(Model $user, array $answers, int $maxSteps = 12): TestResponse
    {
        $this->actingAs($user);
        $committedUrl = route($this->wizardRoutes()['committed']);
        $stepUrl = route($this->wizardRoutes()['step']);
        $response = null;

        for ($step = 0; $step < $maxSteps; $step++) {
            $questionId = $this->currentWizardStep();

            $response = $this->post(route($this->wizardRoutes()['store']), array_merge($answers, [
                '_step' => $questionId,
                '_direction' => 'next',
            ]));

            if ($response->headers->get('Location') === $committedUrl) {
                return $response;          // committed
            }

            // Otherwise we should be sent back to the wizard for the next step;
            // a redirect anywhere else (or validation errors) ends the walk.
            if ($response->headers->get('Location') !== $stepUrl) {
                return $response;
            }
        }

        return $response;
    }

    /** The id (first question) of the step currently rendered by the wizard. */
    protected function currentWizardStep(): int
    {
        $html = $this->get(route($this->wizardRoutes()['step']))->assertOk()->getContent();
        preg_match('/name="_step" value="(\d+)"/', $html, $matches);

        return (int) ($matches[1] ?? 0);
    }
}
