<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Verdeect\IdentityIntegration\Api\NavigationClient;
use Verdeect\IdentityIntegration\Events\SessionMarks;
use Verdeect\IdentityIntegration\Support\IdentityCache;

/**
 * Обращения к навигации, отобранные по глаголу.
 *
 * Адрес у обеих операций общий, и отличить их можно только методом: счётчик
 * по адресу смешал бы гостевые обращения с пользовательскими.
 *
 * @return list<mixed>
 */
function identityNavigationSent(string $method): array
{
    return Http::recorded()
        ->map(static fn (array $pair) => $pair[0])
        ->filter(static fn ($request): bool => str_ends_with($request->url(), '/api/navigation')
            && $request->method() === $method)
        ->values()
        ->all();
}

/**
 * Критерий 89: кэш живёт на бэкенде продукта, а не в браузере.
 */
it('caches by sub on the backend', function (): void {
    identityFakeNavigation();

    $client = app(NavigationClient::class);

    $client->for('sub-1');
    $client->for('sub-1');

    expect(identityNavigationRequests())->toBe(1);
});

/**
 * Критерий 90: пересортировка запрещена — пункты приходят уже в порядке
 * отображения, и второе правило видимости разошлось бы с первым.
 */
it('preserves item order', function (): void {
    identityFakeNavigation();

    $data = app(NavigationClient::class)->for('sub-1');

    expect(array_map(static fn ($item): string => $item->id, $data->items))
        ->toBe(['item-b', 'item-a']);
});

/**
 * Критерий 91: пустой `items` — допустимый ответ; рейл не рисуется вовсе,
 * включая логотип.
 */
it('accepts empty items', function (): void {
    identityFakeNavigation(Http::response([
        'profile_url' => identityBaseUrl().'/profile',
        'logo_url' => identityBaseUrl().'/installation/logo',
        'items' => [],
    ]));

    expect(app(NavigationClient::class)->for('sub-1')->items)->toBe([]);
});

it('rereads when navigation mark is newer', function (): void {
    identityFakeNavigation();

    $client = app(NavigationClient::class);

    $client->for('sub-1');

    // Состав пунктов установки изменился: отметка новее записи кэша.
    app(SessionMarks::class)->markProduct(CarbonImmutable::now()->addMinute());

    $client->for('sub-1');

    expect(identityNavigationRequests())->toBe(2);
});

/**
 * `404 user_not_found` здесь — отказ, в отличие от разрешения имён,
 * но страницу он не валит: рейл не рисуется (справка 7.3).
 */
it('degrades to an empty rail when identity rejects the request', function (): void {
    identityFakeNavigation(Http::response(['error' => 'user_not_found'], 404));

    $data = app(NavigationClient::class)->for('sub-unknown');

    expect($data->items)->toBe([])
        ->and($data->logoUrl)->toBe('');
});

/**
 * Недоступность установки отдаёт **устаревшую запись**, а не пустоту.
 *
 * Пустой рейл на живой странице выглядит как решение установки скрыть пункты,
 * а не как её недоступность: показать вчерашний состав честнее, чем показать
 * пустоту (требование деградации).
 */
it('serves the stale record when the installation is unavailable', function (): void {
    identityFakeNavigation(Http::response(['error' => 'server_error'], 503));

    app(IdentityCache::class)->put('navigation:v3:user:sub-1', [
        'profileUrl' => identityBaseUrl().'/profile',
        'logoUrl' => identityBaseUrl().'/installation/logo',
        'items' => [[
            'id' => 'item-stale',
            'name' => ['ru' => 'Вчерашний', 'en' => 'Stale'],
            'url' => identityBaseUrl().'/stale',
            'iconUrl' => 'https://storage.identity.test/navigation/icons/stale.svg?signature=stub',
            'order' => 1,
        ]],
        'cached_at' => CarbonImmutable::now()->subHour()->toIso8601String(),
    ], 3600);

    // Отметка новее записи: клиент обязан пойти за свежей и получить отказ.
    app(SessionMarks::class)->markProduct(CarbonImmutable::now());

    $data = app(NavigationClient::class)->for('sub-1');

    expect($data->items)->toHaveCount(1)
        ->and($data->items[0]->id)->toBe('item-stale');
});

/**
 * Подписанная ссылка на иконку доходит до пункта без потерь.
 *
 * Проверяется **значение**, а не только отсутствие отказа: разборщик пакета
 * терпим к составу ответа и молча подставляет пустую строку, поэтому
 * переименование поля на стороне установки обнаружилось бы не здесь,
 * а заглушками вместо иконок в чужом продукте.
 */
it('reads the signed icon url', function (): void {
    identityFakeNavigation();

    $data = app(NavigationClient::class)->for('sub-1');

    expect($data->items[0]->iconUrl)
        ->toBe('https://storage.identity.test/navigation/icons/item-b.svg?signature=stub');
});

/**
 * Установка, ещё не перешедшая на загружаемые иконки, рейл продукта не роняет.
 *
 * Она отдаёт прежнее поле `icon`; разборщик его не знает и подставляет пустую
 * ссылку. Компонент рейла показывает такой пункт первой буквой имени — это
 * штатное поведение при рассинхроне версий, а не отказ.
 */
it('tolerates a response without the icon url', function (): void {
    $payload = identityNavigationResponse();

    foreach ($payload['items'] as $index => $item) {
        unset($payload['items'][$index]['icon_url']);

        $payload['items'][$index]['icon'] = 'pi-users';
    }

    identityFakeNavigation(Http::response($payload));

    $data = app(NavigationClient::class)->for('sub-1');

    expect($data->items)->toHaveCount(2)
        ->and($data->items[0]->iconUrl)->toBe('');
});

/**
 * Ссылка переживает круг через кэш.
 *
 * Запись кэша и ответ установки называют иконку **по-разному** — `iconUrl`
 * против `icon_url`, — и разборщики у них разные. Общий разборщик подставил бы
 * пустую ссылку на втором обращении, то есть у всех, кроме первого посетителя.
 */
it('keeps the icon url across the cache round trip', function (): void {
    identityFakeNavigation();

    $client = app(NavigationClient::class);

    $client->for('sub-1');

    $cached = $client->for('sub-1');

    expect(identityNavigationRequests())->toBe(1)
        ->and($cached->items[0]->iconUrl)
        ->toBe('https://storage.identity.test/navigation/icons/item-b.svg?signature=stub');
});

/**
 * Запись кэша, положенная прежней версией пакета, не читается.
 *
 * Ради этого в ключе и стоит отметка состава. Обнаружено на продукте установки
 * после выкатки 0.2: состав записи изменился вместе с переименованием
 * `icon` → `icon_url`, а ключ состава не учитывал. Разборщик не находил
 * `iconUrl` и подставлял пустую ссылку — законное значение, означающее
 * «ссылки не пришло», — и рейл показывал заглушки вместо иконок у всех
 * пользователей продукта до истечения срока кэша, без единого отказа
 * в журнале и на экране.
 *
 * В 0.4 состав изменился снова: из записи ушёл `sub`, и отметка поднята
 * с `v2` до `v3`. Проверяется поведением, а не именем ключа: имя —
 * подробность, а обязанность состоит в том, чтобы запись прежнего состава
 * не подменяла собой ответ.
 */
it('ignores a cache record written by an earlier package version', function (): void {
    identityFakeNavigation();

    // Запись в составе 0.3: `sub` ещё на месте, ключ ещё с отметкой `v2`.
    app(IdentityCache::class)->put('services:v2:sub-1', [
        'sub' => 'sub-1',
        'profileUrl' => identityBaseUrl().'/profile',
        'logoUrl' => identityBaseUrl().'/installation/logo',
        'items' => [[
            'id' => 'item-old',
            'name' => ['ru' => 'Прежний', 'en' => 'Old'],
            'url' => 'https://old.identity.test/',
            'iconUrl' => 'https://storage.identity.test/navigation/icons/old.svg?signature=stub',
            'order' => 1,
        ]],
        'cached_at' => CarbonImmutable::now()->toIso8601String(),
    ], 3600);

    $data = app(NavigationClient::class)->for('sub-1');

    // Ответ спрошен у установки, а не взят из прежней записи.
    expect(identityNavigationRequests())->toBe(1)
        ->and(array_map(static fn ($item): string => $item->id, $data->items))
        ->not->toContain('item-old');
});

/**
 * Гостевая операция идёт `GET` и тела не несёт: адресата у неё нет,
 * и подать в теле нечего.
 */
it('asks for the guest rail with get and no body', function (): void {
    identityFakeNavigation();

    app(NavigationClient::class)->forGuest();

    $sent = identityNavigationSent('GET');

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->body())->toBe('');
});

/**
 * Пользовательская операция идёт `POST` и несёт `sub` телом.
 */
it('asks for the user rail with post and the sub in the body', function (): void {
    identityFakeNavigation();

    app(NavigationClient::class)->for('sub-1');

    $sent = identityNavigationSent('POST');

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['sub'])->toBe('sub-1');
});

/**
 * У гостя `profile_url` не приходит, и это значащее отсутствие: страницы
 * профиля у смотрящего нет. Разборщик читает его пустой строкой, чтобы
 * потребителю не пришлось различать «не пришло» и «пусто».
 */
it('reads a guest response without a profile url', function (): void {
    identityFakeNavigation();

    $data = app(NavigationClient::class)->forGuest();

    expect($data->profileUrl)->toBe('')
        ->and($data->logoUrl)->toBe(identityBaseUrl().'/installation/logo')
        ->and($data->items)->toHaveCount(2);
});

/**
 * Ключи различны: гостевая запись не подменяет пользовательскую и наоборот.
 *
 * Общий ключ отдал бы вошедшему гостевой набор — без его личных пунктов,
 * — и заметить это можно было бы только по недостающему пункту в рейле.
 */
it('keeps the guest and the user records apart', function (): void {
    identityFakeNavigation();

    $client = app(NavigationClient::class);

    $client->forGuest();
    $client->for('sub-1');

    expect(identityGuestNavigationRequests())->toBe(1)
        ->and(identityNavigationRequests())->toBe(1);
});

/**
 * Гостевая запись **единственная на установку**: гостю отдаётся один и тот же
 * набор, и различать некого. Ключ по `sub` завёл бы запись на каждого
 * безымянного посетителя и обесценил бы кэш ровно там, где посетителей больше
 * всего.
 */
it('serves every guest from one record', function (): void {
    identityFakeNavigation();

    $client = app(NavigationClient::class);

    $client->forGuest();
    $client->forGuest();
    $client->forGuest();

    expect(identityGuestNavigationRequests())->toBe(1);
});

/**
 * Гостевой ключ устаревает по общей отметке `navigation.changed`.
 *
 * Отдельного способа сброса заводить не понадобилось: состав меняется для всех
 * сразу, отметка одна на установку, и запись старше её считается устаревшей.
 */
it('rereads the guest rail when navigation mark is newer', function (): void {
    identityFakeNavigation();

    $client = app(NavigationClient::class);

    $client->forGuest();

    app(SessionMarks::class)->markProduct(CarbonImmutable::now()->addMinute());

    $client->forGuest();

    expect(identityGuestNavigationRequests())->toBe(2);
});

/**
 * Недоступность установки гостевую страницу не роняет: пустой рейл —
 * законное состояние (критерий 91).
 */
it('degrades to an empty guest rail when identity rejects the request', function (): void {
    identityFakeNavigation(null, Http::response(['error' => 'server_error'], 503));

    $data = app(NavigationClient::class)->forGuest();

    expect($data->items)->toBe([])
        ->and($data->logoUrl)->toBe('')
        ->and($data->profileUrl)->toBe('');
});
