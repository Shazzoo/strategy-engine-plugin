<?php

use Shazzoo\StrategyEngine\Support\Provenance\Provenance;

/*
 * Het contract met de Engine (ProvenancePayload): wat er binnenkomt, wordt
 * gecontroleerd voordat er iets live gaat. Dezelfde foutcodes als de
 * WordPress-connector.
 */

test('a complete payload is valid in every authorship mode', function (string $mode, string $kind, string $name) {
    $result = Provenance::validate($this->provenance($mode));

    expect($result['valid'])->toBeTrue()
        ->and($result['errors'])->toBe([])
        ->and($result['provenance']['body_revision'])->toBe(self::REVISION)
        ->and($result['provenance']['disclosure_hash'])->toBe(sha1($this->provenance($mode)['disclosure_text']))
        ->and($result['provenance']['byline']['kind'])->toBe($kind)
        ->and($result['provenance']['byline']['name'])->toBe($name);
})->with([
    'scout' => ['scout', 'scout', 'Scout'],
    'organization' => ['organization', 'organization', 'Klant BV'],
    'person' => ['person', 'person', 'Anna de Vries'],
]);

test('scout gets the fixed byline and role', function () {
    $byline = Provenance::validate($this->provenance('scout'))['provenance']['byline'];

    expect($byline)->toMatchArray([
        'name' => 'Scout',
        'role' => 'AI-redactieassistent van Content Studio',
        'url' => self::SCOUT_URL,
        'kind' => 'scout',
    ]);
});

test('white label puts the customer in the byline instead of scout', function () {
    $byline = Provenance::validate($this->provenance('scout', ['white_label' => true]))['provenance']['byline'];

    expect($byline['kind'])->toBe('organization')
        ->and($byline['name'])->toBe('Klant BV');
});

test('missing fields the engine does not send yet are warnings, not errors', function () {
    $result = Provenance::validate($this->provenance('scout'));

    expect($result['valid'])->toBeTrue()
        ->and($result['warnings'])->toContain('byline_missing_from_payload', 'white_label_missing_from_payload');

    $complete = Provenance::validate($this->provenance('scout', ['byline' => ['text' => 'Samengesteld door Scout'], 'white_label' => false]));

    expect($complete['warnings'])->toBe([]);
});

test('invalid payloads are refused with a reason', function (array $changes, string $error) {
    $result = Provenance::validate($this->provenance('scout', $changes));

    expect($result['valid'])->toBeFalse()
        ->and($result['provenance'])->toBeNull()
        ->and($result['errors'])->toContain($error);
})->with([
    'version missing' => [['provenance_version' => null], 'provenance_version_invalid'],
    'version unknown' => [['provenance_version' => 2], 'provenance_version_unsupported'],
    'revision malformed' => [['article_revision_id' => 'abc'], 'body_revision_invalid'],
    'authorship mode unknown' => [['authorship_mode' => 'ghostwriter'], 'authorship_mode_invalid'],
    'author missing' => [['author' => null], 'author_missing'],
    'publisher missing' => [['publisher' => null], 'publisher_missing'],
    'publisher a person' => [['publisher' => ['type' => 'person', 'name' => 'Jan']], 'publisher_type_invalid'],
    'publisher is scout' => [['publisher' => ['type' => 'organization', 'name' => 'Content Studio Redactie']], 'publisher_is_scout'],
    'ai_assisted not a boolean' => [['ai_assisted' => 'yes'], 'ai_assisted_invalid'],
    'review status unknown' => [['review_status' => 'checked'], 'review_status_invalid'],
    'reviewer without human review' => [['reviewer' => ['name' => 'Jan Jansen']], 'reviewer_without_human_review'],
    'disclosure missing' => [['disclosure_text' => null], 'disclosure_text_missing'],
    'disclosure empty while ai assisted' => [['disclosure_text' => '  '], 'disclosure_text_missing'],
    'markdown in disclosure' => [['disclosure_text' => 'Zie [Scout](https://engine.content-studio.com/scout).'], 'disclosure_text_contains_markdown'],
    'scout without profile url' => [['author' => ['type' => 'organization', 'name' => 'Content Studio Redactie', 'url' => '']], 'scout_profile_url_missing'],
    'scout as a person' => [['author' => ['type' => 'person', 'name' => 'Content Studio Redactie', 'url' => 'https://engine.content-studio.com/scout']], 'scout_as_person'],
    'scout person in structured data' => [['structured_data' => ['author' => ['@type' => 'Person', 'name' => 'Content Studio Redactie']]], 'structured_data_author_mismatch'],
    'markdown url' => [['author' => ['type' => 'organization', 'name' => 'Content Studio Redactie', 'url' => '[profiel](https://x.example)']], 'entity_url_invalid'],
    'scout as contributor' => [['expert_contributors' => [['name' => 'Scout']]], 'contributor_invalid'],
]);

test('the fingerprint has to match the disclosure text', function () {
    $payload = $this->provenance('scout');
    $payload['disclosure_fingerprint'] = sha1('iets anders');

    expect(Provenance::validate($payload)['errors'])->toContain('disclosure_fingerprint_mismatch');
});

test('a human author can not be scout under another spelling', function () {
    $payload = $this->provenance('person', ['author' => ['type' => 'person', 'name' => '  scout ', 'url' => '']]);

    expect(Provenance::validate($payload)['errors'])->toContain('scout_as_person');
});

test('a reviewer is kept only after human review', function () {
    $reviewed = Provenance::validate($this->provenance('person', [
        'review_status' => 'human_reviewed',
        'reviewer' => ['name' => 'Jan Jansen', 'role_title' => 'Partner'],
    ]));

    expect($reviewed['provenance']['reviewer']['name'])->toBe('Jan Jansen');
});

test('null means the engine says provenance is incomplete; a missing key means an older engine', function () {
    expect(Provenance::fromArticle($this->article(null)))
        ->toMatchArray(['present' => true, 'valid' => false, 'errors' => ['provenance_missing']]);

    expect(Provenance::fromArticle($this->article(false)))
        ->toMatchArray(['present' => false, 'valid' => false, 'warnings' => ['provenance_not_supported_by_engine']]);
});

test('urls with escaped markdown are not clean', function () {
    expect(Provenance::isCleanUrl('https://klant.example/a_(b)'))->toBeTrue()
        ->and(Provenance::isCleanUrl('https://klant.example/%5Bx%5D%28y%29'))->toBeFalse()
        ->and(Provenance::isCleanUrl('javascript:alert(1)'))->toBeFalse();
});
