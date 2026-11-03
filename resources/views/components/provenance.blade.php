@props(['article', 'at' => ['bottom']])

{{--
    De plek voor het blok "Over dit artikel". Zet hem in een eigen view op de
    plaats(en) waar het blok mag staan; hij rendert alleen op de positie die
    voor dit artikel geldt, en maar één keer per pagina, ook als een thema
    hem vaker plaatst.

        <x-strategy-engine::provenance :article="$article" :at="['top']" />
--}}
{!! \Shazzoo\StrategyEngine\Support\Provenance\Disclosure::renderAt($article, (array) $at) !!}
