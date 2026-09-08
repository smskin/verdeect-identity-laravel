<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Verdeect\IdentityIntegration\Http\ExternalRedirect;

/**
 * Переход на чужой источник.
 *
 * Правило проверяется на самом классе, а не через маршрут: запрос Inertia
 * с несовпавшей версией сборки установка ассетов возвращает `409` сама,
 * и через маршрут два разных повода для одного кода ответа не различить.
 */
it('answers a plain request with a redirect', function (): void {
    $response = ExternalRedirect::to(Request::create('/auth/logout', 'POST'), 'https://id.example.test/end-session');

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe('https://id.example.test/end-session');
});

/**
 * Запрос Inertia — это XHR: обычное перенаправление он проходит сам,
 * и цепочка обрывается правилом общего происхождения. Полный переход
 * выполняет клиент по заголовку (PRD 12.3, пункт 6).
 */
it('answers an inertia request with a full page location', function (): void {
    $request = Request::create('/auth/logout', 'POST');
    $request->headers->set('X-Inertia', 'true');

    $response = ExternalRedirect::to($request, 'https://id.example.test/end-session');

    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->get('X-Inertia-Location'))
        ->toBe('https://id.example.test/end-session');
});
