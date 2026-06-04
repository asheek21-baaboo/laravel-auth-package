<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\Services\OAuthStateManager;
use Baaboo\InternalToolComposerAuthPackage\Services\SsoAuthorizationUrlBuilder;

test('authorizeUrl builds IdP oauth authorize query including state', function () {
    $this->startSession();

    $url = app(SsoAuthorizationUrlBuilder::class)->authorizeUrl();
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://auth.test/oauth/authorize?')
        ->and($query['client_id'])->toBe('hr-portal')
        ->and($query['project_id'])->toBe('hr-portal')
        ->and($query['state'])->toHaveLength(40)
        ->and(session(OAuthStateManager::SESSION_KEY))->toBe($query['state']);
});
