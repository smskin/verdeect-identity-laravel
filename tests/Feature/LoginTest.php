<?php

declare(strict_types=1);

use Verdeect\IdentityIntegration\Flow\AuthorizationFlow;

beforeEach(function (): void {
    identityFakeHttp();
});

/**
 * Критерий 68 (частично): обмен кода выполняется на сервере с PKCE.
 * Здесь порождается вызов со способом `S256` — установка отвергает любой
 * другой даже у клиента с послаблением (справка 4.1).
 */
it('redirects to authorize with pkce', function (): void {
    $response = $this->get(route('identity.login'));

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');

    expect($location)->toStartWith(identityBaseUrl().'/authorize?');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['response_type'])->toBe('code')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['client_id'])->toBe('test-web-client')
        ->and($query['redirect_uri'])->toBe('http://localhost/auth/callback')
        ->and($query['scope'])->toBe('openid profile')
        ->and($query['ui_locales'])->toBe('ru')
        ->and($query['state'])->not->toBeEmpty()
        ->and($query['nonce'])->not->toBeEmpty()
        ->and($query['code_challenge'])->not->toBeEmpty();
});

it('stores flow state in session', function (): void {
    $this->get(route('identity.login'))->assertRedirect();

    $flow = session(AuthorizationFlow::SESSION_KEY);

    expect($flow)->toBeArray()
        ->and($flow['state'])->toBeString()
        ->and($flow['nonce'])->toBeString()
        ->and($flow['verifier'])->toBeString();
});

/**
 * Проверочный код в браузер не попадает: он живёт только в серверной сессии.
 */
it('never sends the verifier to the browser', function (): void {
    $response = $this->get(route('identity.login'));

    $flow = session(AuthorizationFlow::SESSION_KEY);

    expect((string) $response->headers->get('Location'))
        ->not->toContain($flow['verifier']);
});

it('keeps intended url', function (): void {
    $this->withSession(['identity.intended_url' => '/houses/7'])
        ->get(route('identity.login'))
        ->assertRedirect();

    expect(session(AuthorizationFlow::SESSION_KEY)['intended_url'])->toBe('/houses/7');
});
