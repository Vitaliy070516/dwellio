@extends('web.layout', ['scripts' => ['resources/js/search.js']])

@section('title', 'Пошук квартир')

@section('content')
    <h1 class="text-2xl font-semibold">Опишіть квартиру своїми словами</h1>
    <p class="mt-1 text-sm text-stone-600">
        AI розбере запит на фільтри (ціна, кімнати, район), а решту шукатиме за змістом.
    </p>

    <form id="search-form" class="mt-6 space-y-3" novalidate>
        <div class="flex gap-2">
            <input name="q" type="search" maxlength="300" autocomplete="off"
                   placeholder="тихо, біля метро, можна з котом, до 20 тисяч"
                   class="min-w-0 flex-1 rounded border border-stone-300 bg-white px-3 py-2">
            <button type="submit" class="rounded bg-stone-900 px-5 py-2 text-sm font-medium text-white disabled:opacity-50">
                Шукати
            </button>
        </div>

        <div class="flex flex-wrap gap-2 text-sm" aria-label="Приклади запитів">
            @foreach (['тихо біля метро, можна з котом', 'двокімнатна на Подолі до 20 тисяч', 'студія з меблями на лівому березі', 'вид на Дніпро, свіжий ремонт'] as $example)
                <button type="button" data-example="{{ $example }}"
                        class="rounded-full border border-stone-300 bg-white px-3 py-1 text-stone-700 hover:bg-stone-100">
                    {{ $example }}
                </button>
            @endforeach
        </div>

        <details class="text-sm">
            <summary class="cursor-pointer text-stone-600">Точні фільтри (сильніші за здогадку AI)</summary>
            <div class="mt-3 flex flex-wrap items-end gap-4">
                <label class="flex flex-col">
                    Ціна до, грн
                    <input name="price_max" type="number" min="0" step="500"
                           class="mt-1 w-36 rounded border border-stone-300 bg-white px-2 py-1.5">
                </label>
                <label class="flex flex-col">
                    Кімнат
                    <select name="rooms" class="mt-1 rounded border border-stone-300 bg-white px-2 py-1.5">
                        <option value="">будь-скільки</option>
                        @foreach ([1, 2, 3, 4] as $rooms)
                            <option value="{{ $rooms }}">{{ $rooms }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex items-center gap-2">
                    <input name="pets_allowed" type="checkbox" value="1"> можна з тваринами
                </label>
                <label class="flex items-center gap-2">
                    <input name="furnished" type="checkbox" value="1"> з меблями
                </label>
            </div>
        </details>
    </form>

    {{-- Стани деградації: JS додає сюди банери з data-kind (budget / no-ai / no-semantic / filters-only / error). --}}
    <div id="search-notices" class="mt-6 space-y-2" role="status" aria-live="polite"></div>
    <div id="search-understood" class="mt-3 flex flex-wrap items-center gap-2 text-sm"></div>
    <p id="search-summary" class="mt-3 text-sm text-stone-600"></p>
    <ol id="search-results" class="mt-4 grid gap-4 sm:grid-cols-2"></ol>

    <template id="listing-card">
        <li class="flex flex-col rounded border border-stone-200 bg-white p-4">
            <a data-field="link" class="font-semibold leading-snug hover:underline"><span data-field="title"></span></a>
            <p class="mt-1 text-lg font-semibold"><span data-field="price"></span> <span class="text-sm font-normal text-stone-500">грн/міс</span></p>
            <p data-field="facts" class="mt-1 text-sm text-stone-600"></p>
            <p data-field="place" class="text-sm text-stone-600"></p>
            <p data-field="description" class="mt-2 text-sm text-stone-700"></p>
            <div data-field="badges" class="mt-3 flex flex-wrap gap-1.5 text-xs"></div>
        </li>
    </template>

    <script type="application/json" id="districts-data">@json($districts)</script>
@endsection
