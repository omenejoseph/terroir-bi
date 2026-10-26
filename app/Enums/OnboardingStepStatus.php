<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one setup-checklist step stands — see App\Services\Onboarding\OnboardingStatus.
 */
enum OnboardingStepStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Skipped = 'skipped';

    /** Done and skipped both stop a step holding the account back from "set up". */
    public function isSettled(): bool
    {
        return $this === self::Done || $this === self::Skipped;
    }
}
