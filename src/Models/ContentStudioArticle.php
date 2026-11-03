<?php

namespace Shazzoo\StrategyEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Shazzoo\StrategyEngine\Support\Provenance\Disclosure;
use Shazzoo\StrategyEngine\Support\Provenance\Provenance;

class ContentStudioArticle extends Model
{
    protected $table = 'content_studio_articles';

    protected $fillable = [
        'title',
        'excerpt',
        'body_html',
        'featured_image_url',
        'featured_image_alt',
        'meta_title',
        'meta_description',
        'seo_title',
        'og_title',
        'og_description',
        'twitter_title',
        'twitter_description',
        'content_studio_article_id',
        'locale',
        'slug',
        'published_url',
        'published_confirmed_at',
        'cta',
        'primary_keyword',
        'cluster',
        'funnel_stage',
        'intent',
        'angle',
        'source_month',
        'generated_at',
        'planned_at',
        'content_type',
        'cluster_key',
        'hub_content_id',
        'author_name',
        'author_role_title',
        'author_experience_label',
        'author_experience_summary',
        'author_article_relevance',
        'author_boundary_note',
        'authors',
    ];

    public const STATE_VALID = 'valid';

    /** De Engine stuurde provenance die niet klopt: gepubliceerd zonder blok. */
    public const STATE_INVALID = 'invalid';

    public const STATE_LEGACY = 'legacy';

    protected $casts = [
        'cta' => 'array',
        'cluster' => 'array',
        'authors' => 'array',
        'generated_at' => 'datetime',
        'planned_at' => 'datetime',
        'published_confirmed_at' => 'datetime',
        'provenance' => 'array',
        'verification' => 'array',
        'verified_at' => 'datetime',
    ];

    /**
     * Schrijft beheervelden (verificatie, vlaggen) zonder updated_at te
     * raken: dat is de dateModified van het artikel, en die hoort niet te
     * verschuiven omdat de site even keek.
     */
    public function persistQuietly(array $attributes): void
    {
        $timestamps = $this->timestamps;
        $this->timestamps = false;

        try {
            $this->forceFill($attributes)->save();
        } finally {
            $this->timestamps = $timestamps;
        }
    }

    public function isPublic(): bool
    {
        return filled($this->slug);
    }

    /**
     * De genormaliseerde provenance, alleen als die geldig is vastgelegd.
     * Een geparkeerd of legacy artikel heeft er voor de pagina geen.
     */
    public function validProvenance(): ?array
    {
        if ($this->provenance_state !== self::STATE_VALID || ! is_array($this->provenance) || empty($this->provenance['authorship_mode'])) {
            return null;
        }

        return $this->provenance;
    }

    /**
     * Wie er zichtbaar bij het artikel staat. Met provenance is dat wat de
     * Engine vaststelde - ook Scout, die nooit als persoon of als gebruiker
     * van de site verschijnt. Zonder provenance de auteur zoals voorheen.
     *
     * @return array{name: string, role: string, url: string, kind: string, prefix: ?string}|null
     */
    public function byline(): ?array
    {
        $provenance = $this->validProvenance();

        if ($provenance === null) {
            return filled($this->author_name)
                ? ['name' => (string) $this->author_name, 'role' => (string) $this->author_role_title, 'url' => '', 'kind' => 'legacy', 'prefix' => null]
                : null;
        }

        $byline = (array) ($provenance['byline'] ?? []);

        if (($byline['name'] ?? '') === '') {
            return null;
        }

        $kind = (string) ($byline['kind'] ?? 'person');

        return [
            'name' => (string) $byline['name'],
            'role' => (string) ($byline['role'] ?? ''),
            'url' => Provenance::isCleanUrl($byline['url'] ?? '') ? (string) $byline['url'] : '',
            'kind' => $kind,
            'prefix' => __('strategy-engine::content_studio.'.($kind === 'scout' ? 'compiled_by' : 'written_by')),
        ];
    }

    /**
     * De auteursnamen voor de bestaande auteursregel (article-info).
     *
     * @return string[]
     */
    public function bylineNames(): array
    {
        if ($this->validProvenance() === null) {
            return collect(preg_split('/\s*[,;]\s*/', (string) $this->author_name, -1, PREG_SPLIT_NO_EMPTY))
                ->map(fn ($author) => trim($author))
                ->filter()
                ->values()
                ->all();
        }

        $byline = $this->byline();

        return $byline ? [$byline['name']] : [];
    }

    /**
     * Het blok "Over de auteur" hoort bij een mens. Met provenance alleen
     * als de Engine een persoon als auteur noemt, en dezelfde persoon als
     * de auteursvelden - anders staan er twee auteurs onder één artikel.
     */
    public function showsAuthorBio(): bool
    {
        if (! filled($this->author_name)) {
            return false;
        }

        $provenance = $this->validProvenance();

        if ($provenance === null) {
            return true;
        }

        return $provenance['authorship_mode'] === 'human_person'
            && strcasecmp(trim((string) $provenance['author']['name']), trim((string) $this->author_name)) === 0;
    }

    /**
     * Of de site mag zeggen dat iemand dit artikel beoordeelde. Met
     * provenance alleen als een mens het las.
     */
    public function mayClaimReview(): bool
    {
        $provenance = $this->validProvenance();

        return $provenance === null || $provenance['review_status'] === 'human_reviewed';
    }

    /** De tekst zonder provenanceblok, voor alles wat geen artikelpagina is. */
    public function bodyWithoutProvenance(): string
    {
        return Disclosure::strip((string) $this->body_html);
    }

    public function related_hub_articles()
    {
        return $this->hasMany(ContentStudioArticle::class, 'hub_content_id', 'content_studio_article_id')
            ->where('locale', $this->locale);
    }

    /*
     * De accessors hieronder bestaan zodat een site zijn eigen views kan
     * schrijven zonder de kolomnamen van de Engine te kennen. Verander ze niet
     * zonder reden: overschreven views in resources/views/vendor leunen erop.
     */

    public function thumbnailUrl(): ?string
    {
        return $this->featuredImagePath();
    }

    /**
     * Het pad van de uitgelichte afbeelding op de public disk, of null als
     * er geen is. Oudere syncs bewaarden bij een artikel zonder afbeelding
     * een pad als content_studio_images/2333/. - dat is er ook geen.
     */
    public function featuredImagePath(): ?string
    {
        $path = trim((string) $this->featured_image_url);

        if ($path === '' || in_array(pathinfo($path, PATHINFO_BASENAME), ['.', '..'], true) || pathinfo($path, PATHINFO_FILENAME) === '') {
            return null;
        }

        return $path;
    }

    /** De publieke URL van de uitgelichte afbeelding, of null. */
    public function featuredImageUrl(): ?string
    {
        $path = $this->featuredImagePath();

        if ($path === null) {
            return null;
        }

        return str_starts_with($path, 'http') ? $path : \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    }

    /**
     * De artikelen die een hub bundelt, in zijn eigen taal.
     *
     * Twee bronnen, want de Engine legt het op twee manieren vast: een
     * cluster-hub via hub_content_id op elk artikel, een pillar-hub alleen
     * in de linklijst die hij zelf in de hub zet (HUB_LINKS). Die lijst is
     * wat de lezer ziet, dus wat erin staat en hier bestaat, hoort erbij.
     */
    public function hubChildren(): \Illuminate\Support\Collection
    {
        if ($this->hubChildrenCache !== null) {
            return $this->hubChildrenCache;
        }

        $slugs = self::hubLinkSlugs((string) $this->body_html);

        return $this->hubChildrenCache = static::query()
            ->where('locale', $this->locale)
            ->whereKeyNot($this->getKey())
            ->where(function ($query) use ($slugs) {
                $query->where('hub_content_id', $this->content_studio_article_id);

                if ($slugs !== []) {
                    $query->orWhereIn('slug', $slugs);
                }
            })
            ->orderByDesc('generated_at')
            ->get();
    }

    private ?\Illuminate\Support\Collection $hubChildrenCache = null;

    /**
     * De slugs in het linkblok dat de Engine in een hub zet.
     *
     * @return string[]
     */
    public static function hubLinkSlugs(string $html): array
    {
        if (! preg_match('/<!--HUB_LINKS_START-->(.*?)<!--HUB_LINKS_END-->/s', $html, $block)) {
            return [];
        }

        preg_match_all('/href="([^"]+)"/i', $block[1], $links);

        $slugs = [];

        foreach ($links[1] as $href) {
            $path = trim((string) parse_url(html_entity_decode($href), PHP_URL_PATH), '/');
            $slug = $path === '' ? '' : basename($path);

            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    /** Excerpt uit de Engine, of anders een ingekorte versie van de body. */
    public function excerpt(int $limit = 160): string
    {
        // Zonder het provenanceblok: een verantwoording is geen samenvatting.
        return (string) ($this->excerpt ?: Str::limit(trim(strip_tags($this->bodyWithoutProvenance())), $limit));
    }

    public function getContentAttribute(): ?string
    {
        return $this->body_html;
    }

    public function getAuthorAttribute(): ?object
    {
        if (! $this->author_name) {
            return null;
        }

        return (object) [
            'name' => $this->author_name,
            'profile_photo_url' => null,
        ];
    }
}
