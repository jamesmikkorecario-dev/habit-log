<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terminal header verification</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-zinc-950 text-zinc-100">
    <main class="app-container py-8">
        <livewire:terminal-header-fixture :page="$page" />
    </main>
    @livewireScripts
</body>
</html>
