<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Sendtrap Community') }}</title>

        <!-- Fonts (degrade gracefully offline — Figtree falls back to the system sans stack) -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts: Ziggy route list, then the Vite entries (Plan 06 Phase 4b design §7.4) -->
        @routes
        @vite(['resources/js/app.js', 'resources/css/app.css'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
