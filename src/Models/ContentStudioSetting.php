<?php

namespace Shazzoo\StrategyEngine\Models;

use Illuminate\Database\Eloquent\Model;

class ContentStudioSetting extends Model
{
    protected $table = 'content_studio_settings';

    protected $fillable = [
        'ai_enabled',
        'model',
        'default_language',
        'default_tone',
        'default_audience',
        'site_context',
        'meta',
        'cs_api_key',
        'cs_project_code',
        'cs_webhook_secret',
        'cs_sync_cursor',
        'route_prefix',
        'articles_per_block',
        'articles_per_page',
        'index_template_key',
        'index_template_settings',
        'article_template_key',
        'article_template_settings',
    ];

    /**
     * Het geheim waarmee binnenkomende webhooks gecontroleerd worden hoort
     * nooit in een toArray() terecht te komen: die array gaat als
     * Livewire-state naar de browser zodra een instellingenformulier ermee
     * gevuld wordt.
     */
    protected $hidden = [
        'cs_webhook_secret',
    ];

    protected $casts = [
        'index_template_settings' => 'array',
        'article_template_settings' => 'array',
        'ai_enabled' => 'bool',
        'meta' => 'array',
        'articles_per_block' => 'integer',
        'articles_per_page' => 'integer',
    ];

    public static function singleton(): self
    {
        return static::query()->firstOrCreate([], [
            'ai_enabled' => true,
            'model' => 'gpt-4o-mini',
            'default_language' => 'nl',
            'default_tone' => 'friendly, practical',
            'route_prefix' => 'blog',
            'articles_per_block' => 6,
            'articles_per_page' => 12,
        ]);
    }
}
