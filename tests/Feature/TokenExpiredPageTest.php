<?php

declare(strict_types=1);

test('GET /auth/token-expired renders SSO message and IdP link', function () {
    $this->get('/auth/token-expired')
        ->assertOk()
        ->assertSee('Token expired, please log in via SSO.', false)
        ->assertSee('https://auth.test', false);
});
