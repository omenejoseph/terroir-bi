<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Paint the token background before Vue mounts so there is no flash. --}}
    <style>body { background-color: #ffffff; }</style>

    {{-- Figtree is the typeface used throughout the TERROIR design, and every
         measurement taken off the Figma renders assumes it. It is self-hosted
         (public/fonts/figtree, @font-face in resources/css/app.css) rather than
         pulled from Google Fonts: a third-party font that fails to load falls
         back to a wider system face and silently breaks the layout the design
         specifies. Preloading the latin subset avoids a flash of fallback text. --}}
    <link rel="preload" href="/fonts/figtree/figtree-latin.woff2" as="font" type="font/woff2" crossorigin>

    @routes
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="min-h-screen antialiased">
    @inertia
</body>
</html>
