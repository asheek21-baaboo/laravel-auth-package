<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\CompanyAuth;

test('idpUrl returns production constant when app env is not local', function () {
    app()['env'] = 'production';
    config(['company-auth.idp_url' => 'http://sso.test']);

    expect(CompanyAuth::idpUrl())->toBe(CompanyAuth::IDP_URL);
});

test('idpUrl returns configured url when app env is local', function () {
    app()['env'] = 'local';
    config(['company-auth.idp_url' => 'http://sso.test/']);

    expect(CompanyAuth::idpUrl())->toBe('http://sso.test');
});

test('idpUrl falls back to production constant when local and config is empty', function () {
    app()['env'] = 'local';
    config(['company-auth.idp_url' => '']);

    expect(CompanyAuth::idpUrl())->toBe(CompanyAuth::IDP_URL);
});
