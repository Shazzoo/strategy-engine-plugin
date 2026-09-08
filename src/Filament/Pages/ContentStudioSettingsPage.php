<?php

namespace Shazzoo\StrategyEngine\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Artisan;
use Shazzoo\StrategyEngine\Models\ContentStudioSetting;
use Shazzoo\StrategyEngine\Support\Engine\ProjectInfo;
use Shazzoo\ContentStudioCore\Support\Blocks\Adapters\FilamentFieldAdapter;
use Shazzoo\ContentStudioCore\Support\Templates\TemplateSettingsDefinition;
use Shazzoo\ContentStudioCore\Support\Theming\TemplateDefinitionRegistry;
use Shazzoo\ContentStudioCore\Support\Theming\TemplateRegistry;

class ContentStudioSettingsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'Strategy Engine';

    protected static string|\UnitEnum|null $navigationGroup = 'Plugins';

    protected static ?int $navigationSort = 250;

    protected string $view = 'strategy-engine::filament.pages.content-studio-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $model = ContentStudioSetting::singleton();
        $this->form->fill($model->toArray());
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('Strategy Engine')
                    ->columns(2)
                    ->schema([
                        TextInput::make('cs_api_key')
                            ->label('API sleutel')
                            ->password()
                            ->revealable()
                            ->helperText('Je API sleutel van Content Studio Engine.'),
                        TextInput::make('cs_project_code')
                            ->label('Project code')
                            ->helperText(fn () => self::projectHelperText()),
                        TextInput::make('route_prefix')
                            ->label('Blog route prefix')
                            ->default('blog')
                            ->required()
                            ->helperText('Voorbeeld: blog resulteert in /blog en /blog/{slug}.')
                            ->dehydrateStateUsing(fn ($state) => trim((string) $state, '/')),
                        TextInput::make('articles_per_block')
                            ->label('Articles in block')
                            ->numeric()
                            ->default(6)
                            ->minValue(1)
                            ->maxValue(24)
                            ->required()
                            ->helperText('Aantal artikelen dat het Strategy Engine block op pagina\'s toont.'),
                        TextInput::make('articles_per_page')
                            ->label('Articles per page')
                            ->numeric()
                            ->default(12)
                            ->minValue(1)
                            ->maxValue(48)
                            ->required()
                            ->helperText('Aantal artikelen op de artikelen-overzichtspagina.'),
                    ]),
                Section::make('Templates')
                    ->description('Optioneel. Zonder gekozen template rendert de plugin zijn eigen views.')
                    ->columns(2)
                    ->schema([
                        Select::make('index_template_key')
                            ->label('Overview template')
                            ->options(fn (): array => $this->templateOptions())
                            ->searchable()
                            ->live()
                            ->placeholder('Use plugin default')
                            ->helperText('Theme template for the articles overview page.'),
                        Select::make('article_template_key')
                            ->label('Article template')
                            ->options(fn (): array => $this->templateOptions())
                            ->searchable()
                            ->live()
                            ->placeholder('Use plugin default')
                            ->helperText('Theme template for article detail pages.'),
                        Group::make()
                            ->schema(fn (Get $get): array => $this->templateSettingsSchema($get, 'index_template_key'))
                            ->statePath('index_template_settings')
                            ->columnSpan(1),
                        Group::make()
                            ->schema(fn (Get $get): array => $this->templateSettingsSchema($get, 'article_template_key'))
                            ->statePath('article_template_settings')
                            ->columnSpan(1),
                    ]),
            ])
            ->statePath('data');
    }

    private function templateOptions(): array
    {
        return collect(app(TemplateRegistry::class)->all())
            ->mapWithKeys(fn (array $template, string $key): array => [
                $key => $template['label'] ?? $template['meta']['label'] ?? $key,
            ])
            ->toArray();
    }

    /**
     * Velden van het gekozen template. Leeg zolang er geen template gekozen is,
     * zodat de sectie niets toont op een site die de plugin-views gebruikt.
     */
    private function templateSettingsSchema(Get $get, string $templateKeyField): array
    {
        $key = (string) ($get($templateKeyField) ?: $get('../'.$templateKeyField) ?: $get('../../'.$templateKeyField));

        if ($key === '') {
            return [];
        }

        $resolver = app(TemplateDefinitionRegistry::class)->get($key);

        if (! $resolver) {
            return [];
        }

        $definition = $resolver();

        if (! $definition instanceof TemplateSettingsDefinition) {
            return [];
        }

        return app(FilamentFieldAdapter::class)->convertSchema($definition->schema ?? []);
    }

    public function save(): void
    {
        $model = ContentStudioSetting::singleton();
        $model->fill($this->form->getState());
        $model->save();

        Notification::make()
            ->title('Strategy Engine instellingen opgeslagen')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Opslaan')
                ->icon('heroicon-o-check')
                ->keyBindings(['mod+s'])
                ->action('save')
                ->color('primary'),
            Action::make('syncArticles')
                ->label('Sync articles now')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    $exitCode = Artisan::call('content-studio:articles');

                    $notification = Notification::make()
                        ->title($exitCode === 0 ? 'Article sync completed' : 'Article sync failed')
                        ->body(trim(Artisan::output()) !== '' ? trim(Artisan::output()) : 'Controleer de logs voor details.');

                    if ($exitCode === 0) {
                        $notification->success();
                    } else {
                        $notification->danger();
                    }

                    $notification->send();
                }),
        ];
    }

    private static function projectHelperText(): string
    {
        $info = ProjectInfo::stored();

        if (empty($info)) {
            return 'De project code van Content Studio Engine. (Nog niet opgehaald - draai een sync.)';
        }

        $parts = array_filter([
            $info['name'] ?? null,
            $info['website'] ?? null,
        ]);

        $summary = 'Verbonden met: '.implode(' - ', $parts);

        if (! empty($info['primary_locale'])) {
            $summary .= ' | standaardtaal: '.strtoupper((string) $info['primary_locale']);
        }

        if (! empty($info['content_counts']['total'])) {
            $summary .= ' | '.$info['content_counts']['total'].' artikelen beschikbaar';
        }

        return $summary;
    }
}
