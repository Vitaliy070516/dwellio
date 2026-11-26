<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dwellio — генерація оголошення</title>
    @vite(['resources/css/app.css', 'resources/js/ad-generation.js'])
</head>
<body class="bg-stone-50 text-stone-900 antialiased">
<main class="mx-auto max-w-3xl px-6 py-10">
    <h1 class="text-2xl font-semibold">Генерація оголошення</h1>
    <p class="mt-1 text-sm text-stone-600">
        Job у черзі <code>generation</code> → стрім LLM → Reverb → цей браузер. Результат зберігається в БД.
    </p>

    <form id="ad-form" class="mt-6 flex flex-wrap items-end gap-3">
        <label class="flex flex-col text-sm">
            Квартира
            <select name="listing" class="mt-1 rounded border border-stone-300 bg-white px-2 py-1.5">
                @foreach ($listings as $listing)
                    <option value="{{ $listing->id }}">#{{ $listing->id }} · {{ $listing->rooms }}к · {{ number_format($listing->price_uah, 0, '', ' ') }} грн · {{ \Illuminate\Support\Str::limit($listing->title, 40) }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col text-sm">
            Майданчик
            <select name="platform" class="mt-1 rounded border border-stone-300 bg-white px-2 py-1.5">
                @foreach ($platforms as $platform)
                    <option value="{{ $platform->value }}">{{ $platform->label() }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="rounded bg-stone-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
            Згенерувати
        </button>
    </form>

    <p id="ad-status" class="mt-6 text-sm text-stone-600" role="status" aria-live="polite"></p>

    <article class="mt-3 rounded border border-stone-200 bg-white p-5">
        <h2 id="ad-title" class="text-lg font-semibold"></h2>
        <div id="ad-body" class="mt-2 whitespace-pre-wrap leading-relaxed"></div>
    </article>

    <p class="mt-4 text-xs text-amber-700">
        Текст створено AI. Перевірте факти (ціну, адресу, умови) перед публікацією.
    </p>
</main>
</body>
</html>
