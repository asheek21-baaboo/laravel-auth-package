<?php

declare(strict_types=1);

test('GET /oauth/token-expired renders SSO message and login link', function () {
    $this->get('/oauth/token-expired')
        ->assertOk()
        ->assertSee('Token expired, please log in via SSO.', false)
        ->assertSee('Log in via SSO', false)
        ->assertSee(route('company-auth.login'), false);
});
