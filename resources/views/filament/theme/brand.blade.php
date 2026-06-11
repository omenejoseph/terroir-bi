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

    /* The frontend sidebar carries a large centred logo (up to 136px wide);
       let the header grow to fit it instead of Filament's fixed 4rem. */
    .fi-sidebar-header {
        height: auto;
        padding-block: 1rem;
    }

    /*
        Active nav item, as in the frontend sidebar: a light primary tint
        (bg-primary/10) plus a small primary bar on the left. The item's text
        and icon keep their normal colors — the highlight does the work, the
        label does not recolor.
    */
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn {
        background-color: color-mix(in oklch, var(--primary-600) 10%, transparent);
    }

    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn::before {
        content: '';
        position: absolute;
        inset-inline-start: 0;
        top: 50%;
        height: 1.25rem;
        width: 2px;
        translate: 0 -50%;
        border-radius: 9999px;
        background-color: var(--primary-600);
    }

    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-sidebar-item-label {
        color: var(--gray-700);
    }

    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-icon {
        color: var(--gray-400);
    }
</style>
