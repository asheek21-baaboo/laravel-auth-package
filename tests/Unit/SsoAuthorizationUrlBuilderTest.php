<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\Services\SsoAuthorizationUrlBuilder;

test('authorizeUrl builds IdP oauth authorize query', function () {
    $url = app(SsoAuthorizationUrlBuilder::class)->authorizeUrl();

    expect($url)->toStartWith('https://auth.test/oauth/authorize?')
        ->and($url)->toContain('client_id=hr-portal')
        ->and($url)->toContain('project_id=hr-portal');
});
