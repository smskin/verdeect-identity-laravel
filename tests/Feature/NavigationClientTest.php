<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Verdeect\IdentityIntegration\Api\NavigationClient;
use Verdeect\IdentityIntegration\Events\SessionMarks;

/**
 * Критерий 89: кэш живёт на бэкенде продукта, а не в браузере.
 */
it('caches by sub on the backend', function (): void {
    identityFakeServices();

    $client = app(NavigationClient::class);

    $client->for('sub-1');
    $client->for('sub-1');

    expect(identityServicesRequests())->toBe(1);
});

/**
 * Критерий 90: пересортировка запрещена — пункты приходят уже в порядке
 * отображения, и второе правило видимости разошлось бы с первым.
 */
it('preserves item order', function (): void {
    identityFakeServices();

    $data = app(NavigationClient::class)->for('sub-1');

    expect(array_map(static fn ($item): string => $item->id, $data->items))
        ->toBe(['item-b', 'item-a']);
});

/**
 * Критерий 91: пустой `items` — допустимый ответ; рейл не рисуется вовсе,
 * включая логотип.
 */
it('accepts empty items', function (): void {
    identityFakeServices(Http::response([
        'sub' => 'sub-1',
        'profile_url' => identityBaseUrl().'/profile',
        'logo_url' => identityBaseUrl().'/installation/logo',
        'items' => [],
    ]));

    expect(app(NavigationClient::class)->for('sub-1')->items)->toBe([]);
});

it('rereads when navigation mark is newer', function (): void {
    identityFakeServices();

    $client = app(NavigationClient::class);

    $client->for('sub-1');

    // Состав пунктов установки изменился: отметка новее записи кэша.
    app(SessionMarks::class)->markProduct(CarbonImmutable::now()->addMinute());

    $client->for('sub-1');

    expect(identityServicesRequests())->toBe(2);
});

/**
 * `404 user_not_found` здесь — отказ, в отличие от разрешения имён,
 * но страницу он не валит: рейл не рисуется (справка 7.3).
 */
it('degrades to an empty rail when identity rejects the request', function (): void {
    identityFakeServices(Http::response(['error' => 'user_not_found'], 404));

    $data = app(NavigationClient::class)->for('sub-unknown');

    expect($data->items)->toBe([])
        ->and($data->logoUrl)->toBe('');
});
