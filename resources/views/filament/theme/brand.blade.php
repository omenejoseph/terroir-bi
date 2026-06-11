{{--
    Brand overrides that align the back office with the frontend's design tokens
    (frontend/src/app/globals.css). Injected via the STYLES_AFTER render hook so
    it wins over Filament's defaults without a custom theme build step. The
    palette itself (primary/gray) is set in AdminPanelProvider::panel().
--}}
<style>
    :root {
        /* The frontend uses the system font stack (Tailwind's default sans),
           not Filament's bundled Inter. */
        --font-family: ui-sans-serif;

        /* Frontend radius scale: --radius: 0.625rem; sm/md/lg/xl derive from it. */
        --radius-sm: 0.375rem;
        --radius-md: 0.5625rem;
        --radius-lg: 0.625rem;
        --radius-xl: 0.875rem;
    }

    /* Off-white canvas + the soft brand glow at the top, exactly as the
       frontend body. Cards/sidebar stay white and lift off the page. */
    .fi-body {
        background-color: oklch(0.984 0.004 264);
        background-image: radial-gradient(
            72% 42% at 50% -8%,
            color-mix(in oklch, var(--primary-600) 9%, transparent),
            transparent 70%
        );
        background-attachment: fixed;
        background-repeat: no-repeat;
        font-feature-settings: 'rlig' 1, 'calt' 1;
    }

    .dark .fi-body {
        /* Frontend dark background: oklch(0.141 0.005 285.823). */
        background-color: oklch(0.141 0.005 285.823);
        background-image: radial-gradient(
            72% 42% at 50% -8%,
            color-mix(in oklch, var(--primary-500) 12%, transparent),
            transparent 70%
        );
    }
</style>
