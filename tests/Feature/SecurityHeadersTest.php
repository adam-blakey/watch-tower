<?php

use Illuminate\Support\Facades\Vite;

beforeEach(function () {
    $this->withoutVite();
});

it('sends a nonce-based content security policy matching the inline script', function () {
    $response = $this->get(route('login'))->assertOk();

    $nonce = Vite::cspNonce();
    expect($nonce)->not->toBeEmpty();

    $response
        ->assertHeader('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'")
        ->assertSee('<script nonce="'.$nonce.'">', false);
});

it('sends the isolation and permissions headers', function () {
    $this->get(route('login'))
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeader('Cross-Origin-Embedder-Policy', 'require-corp')
        ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('sends the headers on pages outside the web routes', function () {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertHeader('Content-Security-Policy')
        ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
});

it('sends redirects without a body', function () {
    $this->get(route('home'))
        ->assertRedirect(route('login'))
        ->assertContent('');
});
