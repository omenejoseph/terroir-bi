<?php

declare(strict_types=1);

/**
 * The status strings Illuminate\Auth\Passwords\PasswordBroker returns
 * (Password::RESET_LINK_SENT etc.) — resolved via trans($status) wherever the
 * broker's return value is surfaced to a user. This file doesn't ship with the
 * framework skeleton here, so without it trans() would just echo the raw key.
 */
return [
    'reset' => 'Your password has been reset.',
    'sent' => 'We have emailed your password reset link.',
    'throttled' => 'Please wait before retrying.',
    'token' => 'This password reset token is invalid.',
    'user' => 'We cannot find a user with that email address.',
];
