@extends('web.layout', ['scripts' => ['resources/js/listing.js']])

@section('title', $listing->title)

@section('content')
    <a id="back-link" href="{{ route('web.search') }}" class="text-sm text-stone-600 hover:underline">← До пошуку</a>

    <div class="mt-4 grid gap-8 lg:grid-cols-[1fr_1.1fr]">
        <article>
            <h1 class="text-2xl font-semibold leading-snug">{{ $listing->title }}</h1>
            <p class="mt-2 text-2xl font-semibold">
                {{ number_format($listing->price_uah, 0, '', ' ') }} <span class="text-base font-normal text-stone-500">грн/міс</span>
            </p>

            <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-6 gap-y-1.5 text-sm">
                <dt class="text-stone-500">Кімнат</dt><dd>{{ $listing->rooms }}</dd>
                <dt class="text-stone-500">Площа</dt><dd>{{ rtrim(rtrim($listing->area_m2, '0'), '.') }} м²</dd>
                <dt class="text-stone-500">Поверх</dt><dd>{{ $listing->floor }} з {{ $listing->floors_total }}</dd>
                <dt class="text-stone-500">Район</dt><dd>{{ $listing->district->label() }}</dd>
                <dt class="text-stone-500">Адреса</dt><dd>{{ $listing->address }}</dd>
                @if ($listing->metro_station !== null)
                    <dt class="text-stone-500">Метро</dt>
                    <dd>{{ $listing->metro_station }}@if ($listing->metro_distance_m !== null) · {{ $listing->metro_distance_m }} м @endif</dd>
                @endif
                <dt class="text-stone-500">Тварини</dt><dd>{{ $listing->pets_allowed ? 'можна' : 'не можна' }}</dd>
                <dt class="text-stone-500">Меблі</dt><dd>{{ $listing->furnished ? 'є' : 'немає' }}</dd>
            </dl>

            <p class="mt-5 whitespace-pre-line leading-relaxed text-stone-800">{{ $listing->description }}</p>
        </article>

        <section id="ad-generator" data-listing-id="{{ $listing->id }}"
                 class="h-fit rounded border border-stone-200 bg-white p-5">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">Оголошення для майданчика</h2>
                {{-- Стан WebSocket видно завжди: обрив не має бути «тихим». --}}
                <span id="ws-state" data-state="idle" class="rounded-full bg-stone-100 px-2.5 py-0.5 text-xs text-stone-600">
                    WebSocket: не потрібен
                </span>
            </div>
            <p class="mt-1 text-sm text-stone-600">Текст генерується у фоновому воркері й приходить у браузер частинами.</p>

            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($platforms as $platform)
                    <button type="button" data-platform="{{ $platform->value }}"
                            class="rounded bg-stone-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                        Згенерувати для {{ $platform->label() }}
                    </button>
                @endforeach
            </div>

            <div id="ad-notice" class="mt-4 hidden rounded border px-3 py-2 text-sm" role="alert"></div>
            <p id="ad-status" class="mt-4 text-sm text-stone-600" role="status" aria-live="polite"></p>

            <div id="ad-result" class="mt-3 hidden">
                <div class="rounded border border-stone-200 bg-stone-50 p-4">
                    <h3 id="ad-title" class="font-semibold"></h3>
                    <div id="ad-body" class="mt-2 whitespace-pre-wrap text-sm leading-relaxed"></div>
                </div>
                <div class="mt-2 flex items-center justify-between gap-3">
                    <p class="text-xs text-amber-700">Текст створено AI. Перевірте факти (ціну, адресу, умови) перед публікацією.</p>
                    <button id="ad-copy" type="button" class="hidden shrink-0 rounded border border-stone-300 px-3 py-1 text-xs hover:bg-stone-100">
                        Скопіювати
                    </button>
                </div>
            </div>
        </section>
    </div>
@endsection
