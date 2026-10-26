<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Onboarding\UpdateOnboardingStateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\UpdateOnboardingStepRequest;
use App\Models\Tenant;
use App\Services\Onboarding\OnboardingStatus;
use App\Services\Onboarding\OnboardingStep;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The new-account setup hub (nav's "System · Setup", capability
 * `settings.manage`): the go-live checklist from OnboardingStatus, plus the
 * few writes the checklist owns — skipping a step, marking one done by hand,
 * and dismissing the dashboard reminder. Every other step links out to the
 * existing screen that does the work (see resources/js/lib/onboarding.ts).
 */
class SetupController extends Controller
{
    public function index(TenantContext $tenant, OnboardingStatus $status): Response
    {
        $current = $this->tenant($tenant);

        return Inertia::render('Setup/Index', [
            'steps' => array_map(fn (OnboardingStep $step): array => $step->toArray(), $status->steps($current)),
            'summary' => $status->summary($current),
        ]);
    }

    public function updateStep(
        UpdateOnboardingStepRequest $request,
        string $step,
        TenantContext $tenant,
        UpdateOnboardingStateAction $action,
    ): RedirectResponse {
        abort_unless(array_key_exists($step, OnboardingStatus::STEPS), 404);

        $current = $this->tenant($tenant);

        if ($request->has('skipped')) {
            if (! in_array($step, OnboardingStatus::SKIPPABLE, true)) {
                throw ValidationException::withMessages(['skipped' => __('This step can\'t be skipped.')]);
            }

            $action->skip($current, $step, $request->boolean('skipped'));
        } else {
            if (! in_array($step, OnboardingStatus::MARKABLE, true)) {
                throw ValidationException::withMessages(['done' => __('This step completes itself once its data is in.')]);
            }

            $action->markDone($current, $step, $request->boolean('done'));
        }

        return back();
    }

    public function dismiss(Request $request, TenantContext $tenant, UpdateOnboardingStateAction $action): RedirectResponse
    {
        $action->dismiss($this->tenant($tenant), $request->boolean('dismissed', true));

        return back();
    }

    private function tenant(TenantContext $tenant): Tenant
    {
        $current = $tenant->current();
        abort_unless($current instanceof Tenant, 404);

        return $current;
    }
}
