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
        background-color: #f9f8f6; /* Warm Alabaster — matches the frontend canvas */
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

    /* Dark charcoal chrome (sidebar + top bar) framing a light content area,
       both driven by config('brand.sidebar'). */
    .fi-sidebar {
        background-color: {{ config('brand.sidebar') }};
        border-inline-end: 1px solid color-mix(in oklch, white 12%, transparent);
    }

    /* Top bar — same charcoal chrome as the sidebar; its contents read light. */
    .fi-topbar {
        background-color: {{ config('brand.sidebar') }};
        border-bottom: 1px solid color-mix(in oklch, white 12%, transparent);
    }

    .fi-topbar,
    .fi-topbar a,
    .fi-topbar button,
    .fi-topbar .fi-icon-btn,
    .fi-topbar .fi-icon {
        color: color-mix(in oklch, white 80%, transparent);
    }

    .fi-topbar a:hover,
    .fi-topbar button:hover,
    .fi-topbar .fi-icon-btn:hover,
    .fi-topbar .fi-icon-btn:hover .fi-icon {
        color: white;
    }

    /* Sidebar contents read light on the charcoal surface. */
    .fi-sidebar-item-btn .fi-sidebar-item-label {
        color: color-mix(in oklch, white 75%, transparent);
    }

    .fi-sidebar-item-btn .fi-icon {
        color: color-mix(in oklch, white 55%, transparent);
    }

    .fi-sidebar-item-btn:hover {
        background-color: color-mix(in oklch, white 8%, transparent);
    }

    .fi-sidebar-item-btn:hover .fi-sidebar-item-label,
    .fi-sidebar-item-btn:hover .fi-icon {
        color: white;
    }

    .fi-sidebar-group-label {
        color: color-mix(in oklch, white 45%, transparent);
    }

    /* Active nav item: a light tint + a light left bar + light label/icon. */
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn {
        background-color: color-mix(in oklch, white 12%, transparent);
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
        background-color: white;
    }

    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-sidebar-item-label,
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-icon {
        color: white;
    }
</style>
