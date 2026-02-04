<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />

    {{
    Vite::useHotFile(public_path('hot-admin'))
    ->useBuildDirectory('assets/admin')
    ->withEntryPoints(['resources/admin/ts/app.ts'])
    }}

    @inertiaHead
</head>

<body class="font-sans antialiased">
    @inertia
</body>

</html>
