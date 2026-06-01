<?php

declare(strict_types=1);

test('GET /oauth/error renders fallback when stub is missing', function () {
    $this->get('/oauth/error')
        ->assertOk()
        ->assertSee('Token Expired', false)
        ->assertSee('The token has expired. Please log in again.', false);
});

test('GET /oauth/error renders mapped copy for a known stub', function () {
    $this->get('/oauth/error?stub=access_denied')
        ->assertOk()
        ->assertSee('Access denied', false)
        ->assertSee('You do not have permission to use this application.', false);
});

test('GET /oauth/error renders fallback for an unknown stub', function () {
    $this->get('/oauth/error?stub=not_a_real_stub')
        ->assertOk()
        ->assertSee('Token Expired', false)
        ->assertSee('The token has expired. Please log in again.', false)
        ->assertDontSee('Access denied', false);
});

test('company-auth.error route accepts stub query parameter', function () {
    $url = route('company-auth.error', ['stub' => 'sign_in_failed']);

    $this->get($url)
        ->assertOk()
        ->assertSee('Sign-in failed', false)
        ->assertSee('We could not complete sign-in. Please try again.', false);
});

test('GET /oauth/error renders logged_out copy', function () {
    $this->get('/oauth/error?stub=logged_out')
        ->assertOk()
        ->assertSee('Logged out', false)
        ->assertSee('You have been logged out. Please log in again.', false);
});
