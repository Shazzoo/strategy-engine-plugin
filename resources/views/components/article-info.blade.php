@props([
    'authors' => [],
    'byline' => null,
    'date' => null,
    'time' => null,
])

<div>
    {{-- Met provenance: wie de Engine als auteur noemt, met wat er bij hoort
         ("Samengesteld door Scout", met de rol eronder). Scout krijgt geen
         initialen-avatar: dat is geen persoon. --}}
    @if (is_array($byline) && filled($byline['prefix'] ?? null))
        <p class="mb-1 text-sm text-ink-800" data-byline-kind="{{ $byline['kind'] }}">
            {{ $byline['prefix'] }}
            @if (filled($byline['url']))
                <a href="{{ $byline['url'] }}" class="font-medium underline underline-offset-2">{{ $byline['name'] }}</a>
            @else
                <span class="font-medium">{{ $byline['name'] }}</span>
            @endif
            @if (filled($byline['role']))
                <span class="block text-ink-400">{{ $byline['role'] }}</span>
            @endif
        </p>
    @elseif (count($authors = array_filter($authors)))
        <div class="mb-1 flex items-center gap-2 text-sm text-ink-800">
            <div class="flex -space-x-2">
                @forelse ($authors as $author)
                    <div
                        class="flex h-8 w-8 items-center justify-center rounded-full border border-line bg-bg-alt text-xs font-medium text-ink-800">
                        {{ strtoupper(substr($author, 0, 1)) }}
                    </div>
                @endforeach
            </div>

            <p class="m-0 font-medium">
                {{ implode(', ', $authors) }}
            </p>
        </div>
    @endif

    @php
        $meta = collect([
            $date,
            $time ? __('strategy-engine::content_studio.reading_time', ['minutes' => $time]) : null,
        ])->filter();
    @endphp

    @if ($meta->isNotEmpty())
        <div class="ml-2 flex items-center gap-2 text-sm text-ink-400">
            @foreach ($meta as $item)
                <span class="leading-none">{{ $item }}</span>

                @if (!$loop->last)
                    <span>•</span>
                @endif
            @endforeach
        </div>
    @endif
</div>
