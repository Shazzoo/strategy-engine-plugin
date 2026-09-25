{{-- The "Strategy Engine Articles" block (type
     shazzoo/content-studio.content-studio-articles) for themes that look up a
     block's view by its type. Themes that resolve class components use the
     component registered as blocks.content-studio-articles instead. --}}
{{ (new \Shazzoo\StrategyEngine\Views\Components\Blocks\ContentStudioArticles($data ?? []))->render() }}
