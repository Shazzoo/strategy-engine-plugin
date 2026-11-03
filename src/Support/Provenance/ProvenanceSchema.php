<?php

namespace Shazzoo\StrategyEngine\Support\Provenance;

/**
 * Auteur, uitgever, bijdragers en beoordelaar voor het Article-schema, zoals
 * de provenance ze noemt.
 *
 * Op deze site schrijft de plugin zelf de BlogPosting (ArticleSeoBuilder),
 * dus er is één eigenaar en niets om af te stemmen met een SEO-plugin. Wat
 * er toch naast kan komen - een thema dat een eigen Article in zijn layout
 * zet - ziet de verificatie, en het conflictbeleid bepaalt wat daarmee
 * gebeurt.
 *
 * Scout is nergens een Person.
 */
final class ProvenanceSchema
{
    public const ARTICLE_TYPES = ['Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report'];

    /**
     * @return array{author: array, publisher: array, contributor: array, reviewer: ?array}
     */
    public static function pieces(array $provenance): array
    {
        $data = is_array($provenance['structured_data'] ?? null) ? $provenance['structured_data'] : [];
        $author = is_array($data['author'] ?? null) ? $data['author'] : [];

        // De laatste grendel: wat er ook in de payload stond.
        if ($provenance['authorship_mode'] === 'content_studio_scout' || Provenance::containsScoutPerson($author)) {
            $author = Provenance::scoutSchema((string) $provenance['scout_profile_url']);
        }

        $reviewer = null;
        $given = $provenance['reviewer'] ?? null;

        if ($provenance['review_status'] === 'human_reviewed' && is_array($given)
            && ($given['name'] ?? '') !== '' && ($given['type'] ?? '') !== 'organization'
        ) {
            $reviewer = array_filter([
                '@type' => 'Person',
                'name' => $given['name'],
                'jobTitle' => $given['role_title'] ?? '',
            ], fn ($value) => $value !== '');
        }

        $contributors = is_array($data['contributor'] ?? null) ? $data['contributor'] : [];

        return [
            'author' => $author,
            'publisher' => is_array($data['publisher'] ?? null) ? $data['publisher'] : [],
            'contributor' => array_values(array_filter(
                $contributors,
                fn ($node) => is_array($node) && ! Provenance::containsScoutPerson($node),
            )),
            'reviewer' => $reviewer,
        ];
    }

    /**
     * warn: een tweede Article of een afwijkende auteur op de pagina is een
     * waarschuwing, zolang de zichtbare verantwoording klopt. block: dan gaat
     * het artikel niet live.
     */
    public static function conflictPolicy(): string
    {
        return config('content-studio.provenance.schema_conflict_policy') === 'block' ? 'block' : 'warn';
    }

    public static function isType(array $node, array $types): bool
    {
        foreach ((array) ($node['@type'] ?? []) as $type) {
            if (in_array($type, $types, true)) {
                return true;
            }
        }

        return false;
    }
}
