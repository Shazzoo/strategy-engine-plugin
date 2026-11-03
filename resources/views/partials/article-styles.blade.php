{{--
    Styling voor markup die de Content Studio Engine in de artikeltekst zet en
    waar het thema dus geen klasse voor heeft. Staat hier inline omdat de plugin
    geen eigen assetbuild heeft; de site hoeft niets te bundelen.

    Overschrijf de kleuren met de custom properties hieronder, of publiceer dit
    bestand als je de opmaak helemaal zelf wilt doen:

        php artisan vendor:publish --tag=content-studio-views
--}}
@once
    <style>
        /* Uitgelichte afbeelding: vast 16:9 kader dat de afbeelding vult.
           Staat hier zodat het werkt zonder dat de site de plugin in zijn
           Tailwind-config hoeft op te nemen. */
        .cs-featured-image {
            display: block;
            width: 100%;
            aspect-ratio: 16 / 9;
            object-fit: cover;
        }

        /* Afbeeldingen die de Engine als placeholder in de tekst zet
           (diagrammen, illustraties en latere typen). */
        .article-body .article-image {
            margin: 2rem 0;
        }

        .article-body .article-image img {
            display: block;
            width: 100%;
            height: auto;
            border-radius: 0.5rem;
        }

        .article-body .article-image figcaption {
            margin-top: 0.5rem;
            font-size: 0.875rem;
            line-height: 1.5;
            color: var(--cs-caption-text);
        }

        .article-body .article-image figcaption:empty {
            display: none;
        }

        .article-body {
            --cs-caption-text: #475569;
            --cs-pull-quote-accent: #f59e0b;
            --cs-pull-quote-bg: rgb(245 158 11 / 0.06);
            --cs-pull-quote-text: #0f172a;
        }

        .article-body .pull-quote {
            position: relative;
            margin: 2rem 0;
            padding: 1rem 1.25rem 1rem 1.5rem;
            border-left: 4px solid var(--cs-pull-quote-accent);
            border-radius: 0 0.5rem 0.5rem 0;
            background: var(--cs-pull-quote-bg);
        }

        .article-body .pull-quote p {
            margin: 0;
            font-size: 1.25rem;
            font-style: italic;
            font-weight: 500;
            line-height: 1.375;
            color: var(--cs-pull-quote-text);
        }

        @media (min-width: 768px) {
            .article-body .pull-quote p {
                font-size: 1.5rem;
            }
        }

        .article-body .pull-quote p::before {
            content: "\201C";
            margin-right: 0.25rem;
            font-style: normal;
            color: var(--cs-pull-quote-accent);
        }

        .article-body .pull-quote p::after {
            content: "\201D";
            margin-left: 0.25rem;
            font-style: normal;
            color: var(--cs-pull-quote-accent);
        }

        /*
         * Alleen meebewegen met een site die zelf een donkere modus heeft, dus
         * op de .dark-klasse en niet op prefers-color-scheme. Dat laatste volgt
         * de instelling van de bezoeker en maakte de tekst wit op een site die
         * altijd licht is.
         */
        .dark .article-body,
        [data-theme='dark'] .article-body {
            --cs-pull-quote-bg: rgb(245 158 11 / 0.12);
            --cs-pull-quote-text: #ffffff;
            --cs-caption-text: #cbd5e1;
        }

        /* Over dit artikel. Neemt de tekstkleur van de pagina over, dus
           leesbaar op een lichte en een donkere achtergrond zonder dat het
           thema iets hoeft te doen. */
        .cs-provenance {
            margin-block: 2.5rem;
            padding: 1.25rem 1.5rem;
            border: 1px solid rgb(127 127 127 / 0.35);
            border-radius: 0.75rem;
            font-size: 0.9375rem;
            line-height: 1.6;
            color: inherit;
            background: transparent;
        }

        .cs-provenance__title {
            margin: 0 0 0.75rem;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: inherit;
        }

        .cs-provenance__byline {
            margin: 0;
            font-weight: 600;
        }

        .cs-provenance__name {
            color: inherit;
            text-decoration: underline;
            text-underline-offset: 0.15em;
        }

        .cs-provenance__name:focus-visible {
            outline: 2px solid currentColor;
            outline-offset: 2px;
        }

        .cs-provenance__role {
            display: block;
            font-weight: 400;
            font-size: 0.875rem;
        }

        .cs-provenance__text {
            margin: 0.75rem 0 0;
        }

        /* Hubkaart in het overzicht: over de volle breedte van het raster,
           met de afbeelding links als die er is. */
        .cs-hub {
            grid-column: 1 / -1;
            display: flex;
            flex-direction: column;
            margin: 2rem 0;
        }

        .cs-hub__media {
            overflow: hidden;
        }

        .cs-hub__content {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            justify-content: space-between;
            padding: 2rem;
        }

        .cs-hub__title {
            margin: 0 0 1rem;
            font-size: 1.875rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .cs-hub__excerpt {
            margin: 0;
            font-size: 1.125rem;
            font-style: italic;
            line-height: 1.625;
            max-width: 48rem;
        }

        .cs-hub__footer {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top-width: 1px;
            border-top-style: solid;
        }

        .cs-hub__label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--amber-600, #d97706);
        }

        @media (min-width: 640px) {
            .cs-hub__footer {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        @media (min-width: 768px) {
            .cs-hub__content {
                padding: 2.5rem;
            }
        }

        @media (min-width: 1024px) {
            .cs-hub--image {
                flex-direction: row;
            }

            .cs-hub--image .cs-hub__media {
                flex: 0 0 45%;
            }

            .cs-hub--image .cs-hub__media .cs-featured-image {
                height: 100%;
            }
        }
    </style>
@endonce
