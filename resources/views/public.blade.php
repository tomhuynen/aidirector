<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />

    {{
    Vite::useHotFile(public_path('hot-public'))
    ->useBuildDirectory('assets/public')
    ->withEntryPoints(['resources/public/ts/app.ts'])
    }}

    <x-inertia::head />
</head>

<body>
    <x-inertia::app />
</body>

</html>
