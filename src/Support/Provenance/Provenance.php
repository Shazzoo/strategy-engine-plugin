<?php

namespace Shazzoo\StrategyEngine\Support\Provenance;

/**
 * De herkomst van een artikel: wie het schreef, wie erachter staat, of er AI
 * aan te pas kwam en wie het las. De Engine stelt dit vast en stuurt het mee
 * als content_provenance; deze klasse controleert het en zet het in één vaste
 * vorm.
 *
 * De site leidt hier zelf niets af. De zichtbare tekst, de byline en de
 * structured data komen uit wat de Engine stuurde. Een site die zelf gaat
 * raden wie er auteur is, kan iets anders zeggen dan de Engine - en dan staat
 * er onder een artikel een uitspraak die niemand heeft gedaan.
 *
 * Hetzelfde contract als de WordPress-connector, met dezelfde foutcodes, zodat
 * de Engine van beide kanten hetzelfde terughoort.
 */
final class Provenance
{
    public const SUPPORTED_VERSIONS = [1];

    public const AUTHORSHIP_MODES = ['human_person', 'customer_organization', 'content_studio_scout'];

    public const REVIEW_STATUSES = ['not_reviewed', 'automated_checks_only', 'human_reviewed', 'legacy_unknown'];

    public const SCOUT_NAME = 'Scout';

    public const SCOUT_ORGANIZATION = 'Content Studio Redactie';

    public const SCOUT_ROLE = 'AI-redactieassistent van Content Studio';

    public const POSITIONS = ['author', 'top', 'bottom'];

    /**
     * Wat de Engine stuurde.
     *
     * @return array{present: bool, valid: bool, provenance: ?array, errors: string[], warnings: string[]}
     */
    public static function fromArticle(mixed $article): array
    {
        if (! is_array($article) || ! array_key_exists('content_provenance', $article)) {
            /* Een Engine van vóór provenance stuurt de sleutel niet eens.
               Dat is iets anders dan null: dan zegt de Engine dat de herkomst
               onvolledig is. */
            return [
                'present' => false,
                'valid' => false,
                'provenance' => null,
                'errors' => [],
                'warnings' => ['provenance_not_supported_by_engine'],
            ];
        }

        return ['present' => true] + self::validate($article['content_provenance']);
    }

    /**
     * Controleert het object en geeft een genormaliseerde vorm terug. Bij een
     * fout is provenance null: half geldige provenance publiceren is erger dan
     * geen.
     *
     * @return array{valid: bool, provenance: ?array, errors: string[], warnings: string[]}
     */
    public static function validate(mixed $payload): array
    {
        if (! is_array($payload)) {
            return ['valid' => false, 'provenance' => null, 'errors' => ['provenance_missing'], 'warnings' => []];
        }

        $errors = [];
        $warnings = [];

        $version = is_numeric($payload['provenance_version'] ?? null) ? (int) $payload['provenance_version'] : 0;

        if ($version < 1) {
            $errors[] = 'provenance_version_invalid';
        } elseif (! in_array($version, (array) config('content-studio.provenance.supported_versions', self::SUPPORTED_VERSIONS), true)) {
            $errors[] = 'provenance_version_unsupported';
        }

        $revision = self::string($payload, 'article_revision_id');

        if (preg_match('/^[0-9a-f]{40}$/', $revision) !== 1) {
            $errors[] = 'body_revision_invalid';
        }

        $mode = self::string($payload, 'authorship_mode');

        if (! in_array($mode, self::AUTHORSHIP_MODES, true)) {
            $errors[] = 'authorship_mode_invalid';
        }

        $author = self::entity($payload['author'] ?? null);
        $publisher = self::entity($payload['publisher'] ?? null);

        if ($author === null) {
            $errors[] = 'author_missing';
        } else {
            array_push($errors, ...self::authorErrors($mode, $author));
        }

        if ($publisher === null) {
            $errors[] = 'publisher_missing';
        } elseif ($publisher['type'] !== 'organization') {
            $errors[] = 'publisher_type_invalid';
        } elseif (self::isScoutName($publisher['name'])) {
            // De klant is de uitgever, nooit de redactie.
            $errors[] = 'publisher_is_scout';
        }

        if (! is_bool($payload['ai_assisted'] ?? null)) {
            $errors[] = 'ai_assisted_invalid';
        }

        $aiAssisted = ($payload['ai_assisted'] ?? null) === true;

        $reviewStatus = self::string($payload, 'review_status');

        if (! in_array($reviewStatus, self::REVIEW_STATUSES, true)) {
            $errors[] = 'review_status_invalid';
        }

        $reviewer = null;

        if (($payload['reviewer'] ?? null) !== null) {
            $reviewer = self::entity($payload['reviewer'], 'person');

            if ($reviewer === null) {
                $errors[] = 'reviewer_invalid';
            } elseif ($reviewStatus !== 'human_reviewed') {
                /* Een naam bij een artikel dat niemand las, is precies de
                   claim die nooit mag ontstaan. */
                $errors[] = 'reviewer_without_human_review';
            } elseif (self::isScoutName($reviewer['name'])) {
                $errors[] = 'reviewer_is_scout';
            }
        }

        $disclosure = is_string($payload['disclosure_text'] ?? null) ? trim($payload['disclosure_text']) : null;

        if ($disclosure === null || ($disclosure === '' && $aiAssisted)) {
            // Kwam er AI aan te pas, dan gaat die zin mee. Altijd.
            $errors[] = 'disclosure_text_missing';
        } elseif (self::containsMarkdownLink($disclosure)) {
            $errors[] = 'disclosure_text_contains_markdown';
        }

        $hash = sha1((string) $disclosure);
        $fingerprint = self::string($payload, 'disclosure_fingerprint');

        if ($fingerprint !== '' && ! hash_equals($fingerprint, $hash)) {
            $errors[] = 'disclosure_fingerprint_mismatch';
        }

        $contributors = [];

        foreach (is_array($payload['expert_contributors'] ?? null) ? $payload['expert_contributors'] : [] as $contributor) {
            $entity = self::entity($contributor, 'person');

            if ($entity === null || self::isScoutName($entity['name'])) {
                $errors[] = 'contributor_invalid';

                continue;
            }

            $contributors[] = $entity;
        }

        foreach ([$author, $publisher, $reviewer] as $entity) {
            if (is_array($entity) && $entity['url'] !== '' && ! self::isCleanUrl($entity['url'])) {
                $errors[] = 'entity_url_invalid';
            }
        }

        $byline = self::byline($payload, $mode, $author, $publisher, $warnings);

        $structuredData = $errors === []
            ? self::structuredData($payload, $mode, $author, $publisher, $contributors, $errors, $warnings)
            : null;

        if ($errors !== []) {
            return [
                'valid' => false,
                'provenance' => null,
                'errors' => array_values(array_unique($errors)),
                'warnings' => array_values(array_unique($warnings)),
            ];
        }

        $position = self::string($payload, 'provenance_block_position');

        if ($position === '' && is_array($payload['display'] ?? null)) {
            $position = self::string($payload['display'], 'position');
        }

        return [
            'valid' => true,
            'provenance' => [
                'version' => $version,
                'body_revision' => $revision,
                'authorship_mode' => $mode,
                'author' => $author,
                'publisher' => $publisher,
                'ai_assisted' => $aiAssisted,
                'generation_method' => self::string($payload, 'generation_method'),
                'review_status' => $reviewStatus,
                'reviewer' => $reviewStatus === 'human_reviewed' ? $reviewer : null,
                'reviewed_at' => self::string($payload, 'reviewed_at'),
                'expert_contributors' => $contributors,
                'disclosure_text' => (string) $disclosure,
                'disclosure_hash' => $hash,
                'byline' => $byline,
                'white_label' => array_key_exists('white_label', $payload) ? (bool) $payload['white_label'] : null,
                'position' => in_array($position, self::POSITIONS, true) ? $position : '',
                'scout_profile_url' => $mode === 'content_studio_scout' ? $author['url'] : self::scoutUrlFrom($payload),
                'structured_data' => $structuredData,
            ],
            'errors' => [],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Of er onder dit artikel een verantwoording moet staan.
     */
    public static function requiresDisclosure(?array $provenance): bool
    {
        return is_array($provenance)
            && (! empty($provenance['ai_assisted']) || trim((string) ($provenance['disclosure_text'] ?? '')) !== '');
    }

    /**
     * Scout is geen mens. Ook niet onder een andere schrijfwijze.
     */
    public static function isScoutName(mixed $name): bool
    {
        $name = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $name)));

        return in_array($name, [mb_strtolower(self::SCOUT_NAME), mb_strtolower(self::SCOUT_ORGANIZATION)], true);
    }

    /**
     * Markdown-links of geëscapete linknotatie: wat er overblijft als ergens
     * een stap tekst niet als HTML maar als Markdown doorgaf.
     */
    public static function containsMarkdownLink(mixed $value): bool
    {
        return preg_match('/\[[^\]]*\]\([^)]*\)|\\\\\[|\\\\\]|%5B[^%]*%5D%28|&#0*91;[^&]*&#0*93;&#0*40;/i', (string) $value) === 1;
    }

    public static function isCleanUrl(mixed $url): bool
    {
        $url = (string) $url;

        if (! preg_match('#^https?://[^\s<>"\'\[\]]+$#i', $url)) {
            return false;
        }

        return ! self::containsMarkdownLink($url) && stripos($url, '%5D%28') === false;
    }

    /**
     * De schema.org-vorm van Scout. Organization, nooit Person.
     *
     * @return array<string, string>
     */
    public static function scoutSchema(string $url): array
    {
        return array_filter([
            '@type' => 'Organization',
            'name' => self::SCOUT_ORGANIZATION,
            'alternateName' => self::SCOUT_NAME,
            'url' => $url,
        ], fn ($value) => $value !== '');
    }

    /**
     * Ergens in deze structuur een Person die Scout heet.
     */
    public static function containsScoutPerson(mixed $data): bool
    {
        if (! is_array($data)) {
            return false;
        }

        if (in_array('Person', (array) ($data['@type'] ?? []), true)) {
            foreach (['name', 'alternateName'] as $key) {
                foreach ((array) ($data[$key] ?? []) as $value) {
                    if (is_string($value) && self::isScoutName($value)) {
                        return true;
                    }
                }
            }
        }

        foreach ($data as $value) {
            if (is_array($value) && self::containsScoutPerson($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private static function authorErrors(string $mode, array $author): array
    {
        $errors = [];

        switch ($mode) {
            case 'human_person':
                if ($author['type'] !== 'person') {
                    $errors[] = 'author_type_mismatch';
                }

                if (self::isScoutName($author['name']) || self::isScoutName($author['alternate_name'])) {
                    $errors[] = 'scout_as_person';
                }
                break;

            case 'customer_organization':
                if ($author['type'] !== 'organization' || self::isScoutName($author['name'])) {
                    $errors[] = 'author_type_mismatch';
                }
                break;

            case 'content_studio_scout':
                if ($author['type'] !== 'organization') {
                    $errors[] = 'scout_as_person';
                }

                if (strcasecmp($author['name'], self::SCOUT_ORGANIZATION) !== 0) {
                    $errors[] = 'scout_author_name_invalid';
                }

                // De canonieke profielpagina, extern bij Content Studio.
                if ($author['url'] === '') {
                    $errors[] = 'scout_profile_url_missing';
                }
                break;
        }

        return $errors;
    }

    /**
     * De zichtbare regel bij het artikel. Liefst zoals de Engine hem
     * samenstelde; anders alleen uit naam en rol, zonder iets toe te voegen.
     *
     * @param  string[]  $warnings
     * @return array{text: string, name: string, role: string, url: string, kind: string}
     */
    private static function byline(array $payload, string $mode, ?array $author, ?array $publisher, array &$warnings): array
    {
        $engineByline = is_array($payload['byline'] ?? null)
            ? self::string($payload['byline'], 'text')
            : self::string($payload, 'byline');

        $whiteLabel = ! empty($payload['white_label']);
        $author ??= self::emptyEntity();
        $publisher ??= self::emptyEntity();

        if ($mode === 'content_studio_scout' && ! $whiteLabel) {
            $name = $author['alternate_name'] !== '' ? $author['alternate_name'] : self::SCOUT_NAME;
            $role = $author['role_title'] !== '' ? $author['role_title'] : self::SCOUT_ROLE;
            $url = $author['url'];
            $kind = 'scout';
        } elseif ($mode === 'content_studio_scout' || $mode === 'customer_organization') {
            // White label: de klantorganisatie neemt de byline over.
            $name = $mode === 'customer_organization' ? $author['name'] : $publisher['name'];
            $role = '';
            $url = $mode === 'customer_organization' ? $author['url'] : $publisher['url'];
            $kind = 'organization';
        } else {
            $name = $author['name'];
            $role = $author['role_title'];
            $url = $author['url'];
            $kind = 'person';
        }

        if ($engineByline === '') {
            $warnings[] = 'byline_missing_from_payload';
        }

        if ($mode === 'content_studio_scout' && ! array_key_exists('white_label', $payload)) {
            $warnings[] = 'white_label_missing_from_payload';
        }

        return ['text' => $engineByline, 'name' => $name, 'role' => $role, 'url' => $url, 'kind' => $kind];
    }

    /**
     * Auteur, uitgever en eventuele bijdragers voor schema.org. Uit de
     * payload, maar gecontroleerd: de vorm moet kloppen met de modus, en Scout
     * is nergens een Person.
     *
     * @param  string[]  $errors
     * @param  string[]  $warnings
     */
    private static function structuredData(array $payload, string $mode, array $author, array $publisher, array $contributors, array &$errors, array &$warnings): ?array
    {
        $given = is_array($payload['structured_data'] ?? null) ? $payload['structured_data'] : null;
        $expectedAuthor = self::authorSchema($mode, $author);

        if ($given === null) {
            $warnings[] = 'structured_data_missing_from_payload';
            $data = ['author' => $expectedAuthor];
        } else {
            $givenAuthor = is_array($given['author'] ?? null) ? $given['author'] : null;

            if ($givenAuthor === null
                || ($givenAuthor['@type'] ?? null) !== $expectedAuthor['@type']
                || strcasecmp(trim((string) ($givenAuthor['name'] ?? '')), (string) $expectedAuthor['name']) !== 0
            ) {
                $errors[] = 'structured_data_author_mismatch';

                return null;
            }

            $data = ['author' => self::cleanNode($givenAuthor)];
        }

        if (self::containsScoutPerson($data) || ($given !== null && self::containsScoutPerson($given))) {
            $errors[] = 'scout_as_person';

            return null;
        }

        $data['publisher'] = self::organizationSchema($publisher);

        if ($given !== null && isset($given['publisher']['name'])
            && strcasecmp(trim((string) $given['publisher']['name']), $publisher['name']) !== 0
        ) {
            $errors[] = 'structured_data_publisher_mismatch';

            return null;
        }

        if ($given !== null && is_array($given['publisher'] ?? null)) {
            $data['publisher'] = self::cleanNode($given['publisher']);
        }

        // Alleen aantoonbare bijdragen: wat in expert_contributors staat.
        if ($contributors !== []) {
            $data['contributor'] = array_map(fn (array $contributor): array => array_filter([
                '@type' => 'Person',
                'name' => $contributor['name'],
                'jobTitle' => $contributor['role_title'],
                'url' => $contributor['url'],
            ], fn ($value) => $value !== ''), $contributors);
        }

        foreach (self::collectUrls($data) as $url) {
            if (! self::isCleanUrl($url)) {
                $errors[] = 'structured_data_url_invalid';

                return null;
            }
        }

        return $data;
    }

    private static function authorSchema(string $mode, array $author): array
    {
        if ($mode === 'content_studio_scout') {
            return self::scoutSchema($author['url']);
        }

        if ($mode === 'customer_organization') {
            return self::organizationSchema($author);
        }

        return array_filter([
            '@type' => 'Person',
            'name' => $author['name'],
            'jobTitle' => $author['role_title'],
            'url' => $author['url'],
        ], fn ($value) => $value !== '');
    }

    private static function organizationSchema(array $entity): array
    {
        $schema = array_filter([
            '@type' => 'Organization',
            'name' => $entity['name'],
            'url' => $entity['url'],
        ], fn ($value) => $value !== '');

        if ($entity['logo_url'] !== '' && self::isCleanUrl($entity['logo_url'])) {
            $schema['logo'] = ['@type' => 'ImageObject', 'url' => $entity['logo_url']];
        }

        return $schema;
    }

    /**
     * Alleen scalars en geneste objecten; geen @context of @id uit de Engine,
     * die bepaalt de site zelf.
     */
    private static function cleanNode(array $node): array
    {
        $clean = [];

        foreach ($node as $key => $value) {
            if (! is_string($key) || $key === '@context' || $key === '@id') {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::cleanNode($value);
            } elseif (is_scalar($value) && trim((string) $value) !== '') {
                $clean[$key] = is_string($value) ? self::text($value) : $value;
            }
        }

        return $clean;
    }

    /**
     * @return string[]
     */
    private static function collectUrls(array $data): array
    {
        $urls = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                array_push($urls, ...self::collectUrls($value));
            } elseif (in_array($key, ['url', 'sameAs', 'logo'], true) && is_string($value)) {
                $urls[] = $value;
            }
        }

        return $urls;
    }

    /**
     * Personen, organisaties en Scout in één vorm.
     */
    private static function entity(mixed $value, string $defaultType = ''): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $name = self::string($value, 'name');

        if ($name === '') {
            return null;
        }

        $type = strtolower(self::string($value, 'type'));

        if (($value['is_person'] ?? null) === false) {
            $type = 'organization';
        } elseif ($type === '') {
            $type = $defaultType;
        }

        $organization = is_array($value['organization'] ?? null)
            ? self::string($value['organization'], 'name')
            : self::string($value, 'organization');

        return [
            'type' => $type,
            'name' => self::text($name),
            'alternate_name' => self::text(self::string($value, 'alternate_name')),
            'role_title' => self::text(self::string($value, 'role_title')),
            'organization' => self::text($organization),
            'url' => self::string($value, 'url'),
            'logo_url' => self::string($value, 'logo_url'),
        ];
    }

    private static function emptyEntity(): array
    {
        return ['type' => '', 'name' => '', 'alternate_name' => '', 'role_title' => '', 'organization' => '', 'url' => '', 'logo_url' => ''];
    }

    private static function scoutUrlFrom(array $payload): string
    {
        $url = is_array($payload['assembled_by'] ?? null) ? self::string($payload['assembled_by'], 'url') : '';

        return self::isCleanUrl($url) ? $url : '';
    }

    /** Platte tekst: geen tags, geen dubbele witruimte. */
    private static function text(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
    }

    private static function string(array $source, string $key): string
    {
        return isset($source[$key]) && is_scalar($source[$key]) ? trim((string) $source[$key]) : '';
    }
}
