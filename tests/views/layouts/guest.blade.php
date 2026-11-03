{{-- Een kale site-layout voor de tests: zoals een thema de SEO-tags in de
     head zet en de pagina in de body. --}}
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    {!! \Artesaos\SEOTools\Facades\SEOTools::generate() !!}
    @stack('head')
</head>
<body>
    @yield('content')
</body>
</html>
