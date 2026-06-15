<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Brand palette — single source of truth for the back office theme
|--------------------------------------------------------------------------
|
| The back office (Filament) reads its primary colour ramp from here, so
| retheming the admin panel is a one-file change. Filament fills buttons with
| shade 600, so 600 mirrors the frontend's accent; 500 is the hover shade.
|
| The frontend SPA has its own single source — the `--brand` token in
| frontend/src/app/globals.css (also runtime-overridable for per-tenant
| theming). Keep 600 here aligned with that `--brand` value when rebranding.
|
| Current: Deep Charcoal-Slate (neutral grey ramp around #3A3A3A).
|
*/

return [
    'primary' => [
        50 => '#f7f7f7',
        100 => '#ededed',
        200 => '#dcdcdc',
        300 => '#bdbdbd',
        400 => '#8f8f8f',
        500 => '#5c5c5c',   // hover
        600 => '#3a3a3a',   // Deep Charcoal-Slate = frontend --brand (filled buttons)
        700 => '#303030',
        800 => '#262626',
        900 => '#1f1f1f',
        950 => '#141414',
    ],
];
