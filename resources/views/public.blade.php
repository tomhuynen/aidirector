<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@400;500;600;700&display=swap" rel="stylesheet">

    {{
    Vite::useHotFile(public_path('hot-public'))
    ->useBuildDirectory('assets/public')
    ->withEntryPoints(['resources/public/ts/app.ts'])
    }}

    <x-inertia::head />
</head>

<body class="min-h-svh font-sans text-foreground antialiased">
    <x-inertia::app />
</body>

</html>
