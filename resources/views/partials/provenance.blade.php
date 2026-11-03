{{--
    Over dit artikel: wie het schreef en hoe het tot stand kwam.

    Mag per site overschreven worden, maar houd de data-attributen op het
    buitenste element en data-provenance-author op de byline: daaraan
    herkent de plugin het blok bij vervangen, ontdubbelen en verifiëren.
    Gebruik binnen het blok geen <aside> of <section>, en geen scripts.

    De tekst komt letterlijk uit de Engine; zet er niets bij.
--}}
<aside class="cs-provenance cs-provenance--{{ $bylineKind }}"
    data-provenance="{{ $marker }}"
    data-provenance-version="{{ $version }}"
    data-body-revision="{{ $bodyRevision }}"
    data-disclosure-fingerprint="{{ $fingerprint }}"
    data-authorship-mode="{{ $mode }}"
    data-review-status="{{ $reviewStatus }}"
    aria-labelledby="{{ $headingId }}">
    <h2 class="cs-provenance__title" id="{{ $headingId }}">{{ $title }}</h2>
@if ($bylineName !== '')
    <p class="cs-provenance__byline" data-provenance-author="{{ $bylineName }}">
    @if ($bylineText !== '')
        {{ $bylineText }}
    @else
        {{ $bylinePrefix }}
        @if ($bylineUrl !== '')
            <a class="cs-provenance__name" href="{{ $bylineUrl }}">{{ $bylineName }}</a>
        @else
            <span class="cs-provenance__name">{{ $bylineName }}</span>
        @endif
        @if ($bylineRole !== '')
            <span class="cs-provenance__role">{{ $bylineRole }}</span>
        @endif
    @endif
    </p>
@endif
@if ($disclosureText !== '')
    <p class="cs-provenance__text">{{ $disclosureText }}</p>
@endif
</aside>
