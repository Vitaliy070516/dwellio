<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Dwellio</title>
    @vite(['resources/css/app.css', ...$scripts])
</head>
<body class="min-h-screen bg-stone-50 text-stone-900 antialiased">
<header class="border-b border-stone-200 bg-white">
    <div class="mx-auto flex max-w-5xl items-baseline gap-3 px-6 py-4">
        <a href="{{ route('web.search') }}" class="text-lg font-semibold tracking-tight">Dwellio</a>
        <span class="text-sm text-stone-500">AI-пошук оренди в Києві · демо</span>
    </div>
</header>

<main class="mx-auto max-w-5xl px-6 py-8">
    @yield('content')
</main>

<footer class="mx-auto max-w-5xl px-6 pb-10 text-xs text-stone-500">
    Дані квартир синтетичні. Пошук: pgvector + FTS + trigram (RRF), генерація: черга → LLM → Reverb.
</footer>
</body>
</html>
