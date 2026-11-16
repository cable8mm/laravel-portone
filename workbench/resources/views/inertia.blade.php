<!doctype html>
{{-- Root view of the Workbench Inertia app that renders the published React pages (live verification of SCR-014). --}}
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @vite('resources/js/inertia.tsx')
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
