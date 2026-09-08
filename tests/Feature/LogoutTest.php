<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Verdeect\IdentityIntegration\Tokens\TokenStore;

/**
 * Критерий 76: выход вызывает `/end-session`; локальная сессия и токены
 * уничтожаются.
 */
it('destroys local session and tokens before redirect', function (): void {
    identityFakeHttp();
    identityAuthenticate();

    $this->post('/auth/logout')->assertRedirect();

    expect(app(TokenStore::class)->get('sid-1'))->toBeNull()
        ->and(session()->has('identity.sid'))->toBeFalse()
        ->and(session()->has('identity.sub'))->toBeFalse();
});

it('redirects to end-session with id_token_hint', function (): void {
    identityFakeHttp();
    identityAuthenticate();

    $response = $this->post('/auth/logout');

    $location = (string) $response->headers->get('Location');

    expect($location)->toStartWith(identityBaseUrl().'/end-session?');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['id_token_hint'])->not->toBeEmpty()
        ->and($query['post_logout_redirect_uri'])->toBe('http://localhost/')
        ->and($query['state'])->not->toBeEmpty();
});

/**
 * `/revoke` освобождает токены одного продукта, не выводя человека
 * из системы: возврат в сервис происходил бы одним кликом без пароля
 * (PRD 12.5).
 */
it('never calls revoke', function (): void {
    identityFakeHttp();
    identityAuthenticate();

    $this->post('/auth/logout');

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/revoke'));
});

/**
 * Перенаправление на `/end-session` возможно только при пригодной подсказке
 * (справка 4.5). Её нет — выход выполняется локально, человек возвращается
 * на главную.
 */
it('logs out locally when hint is missing', function (): void {
    identityFakeHttp();
    identityAuthenticate(withIdToken: false);

    $this->post('/auth/logout')->assertRedirect('/');

    expect(session()->has('identity.sid'))->toBeFalse()
        ->and(app(TokenStore::class)->get('sid-1'))->toBeNull();
});

it('logs out locally when discovery is unreachable', function (): void {
    Http::fake([
        identityBaseUrl().'/.well-known/openid-configuration' => Http::response('', 503),
    ]);

    identityAuthenticate();

    $this->post('/auth/logout')->assertRedirect('/');

    expect(app(TokenStore::class)->get('sid-1'))->toBeNull();
});

/**
 * Запрос Inertia — это XHR, и обычное перенаправление он проходит сам.
 * Цепочка, уходящая на установку, обрывалась бы правилом общего
 * происхождения: локальная сессия уничтожена, а сессия установки жива —
 * и ближайший переход возвращал бы человека вошедшим (PRD 12.3, пункт 6).
 */
it('answers inertia requests with a full page redirect', function (): void {
    identityFakeHttp();
    identityAuthenticate();

    $response = $this->withHeaders(['X-Inertia' => 'true'])->post('/auth/logout');

    $response->assertStatus(409);

    expect($response->headers->get('X-Inertia-Location'))
        ->toStartWith(identityBaseUrl().'/end-session?');
});
