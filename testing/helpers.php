<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Verdeect\IdentityIntegration\Support\IdentityCache;
use Verdeect\IdentityIntegration\Tokens\TokenSet;
use Verdeect\IdentityIntegration\Tokens\TokenStore;

/**
 * Общая оснастка прогонов: поддельная установка identity.
 *
 * Файл лежит в `testing/`, а не в `tests/`, и попадает в поставку через
 * `autoload.files`: наборы **продукта** тоже проверяют экраны за входом,
 * а `tests/` в архив composer не входит — оставленная там оснастка была бы
 * доступна только самому пакету.
 *
 * Ключ подписи порождается один раз на прогон: генерация RSA-2048 занимает
 * заметное время, а набору нужен один и тот же ключ во всех сценариях.
 *
 * Приставка `identity` у всех функций обязательна: файлы Pest объявляют
 * функции глобально, и одноимённая в другом наборе роняет весь прогон.
 */
function identityBaseUrl(): string
{
    return 'https://id.example.test';
}

function identitySigningKey(): JWK
{
    static $key = null;

    if ($key === null) {
        $key = JWKFactory::createRSAKey(2048, [
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => 'test-key-1',
        ]);
    }

    return $key;
}

/**
 * Открытая часть ключа в форме набора JWKS.
 *
 * @param  string|null  $kid  переопределяет идентификатор ключа — так
 *                            проверяется реакция на ротацию
 * @return array{keys: list<array<string, mixed>>}
 */
function identityJwks(null|string $kid = null): array
{
    $public = identitySigningKey()->toPublic()->all();

    if ($kid !== null) {
        $public['kid'] = $kid;
    }

    return ['keys' => [$public]];
}

/**
 * Документ обнаружения тестовой установки.
 *
 * @return array<string, mixed>
 */
function identityDiscovery(): array
{
    $base = identityBaseUrl();

    return [
        'issuer' => $base,
        'authorization_endpoint' => $base.'/authorize',
        'token_endpoint' => $base.'/token',
        'userinfo_endpoint' => $base.'/userinfo',
        'jwks_uri' => $base.'/.well-known/jwks.json',
        'end_session_endpoint' => $base.'/end-session',
        'code_challenge_methods_supported' => ['S256'],
        'id_token_signing_alg_values_supported' => ['RS256'],
    ];
}

/**
 * Подписанный ID-токен.
 *
 * @param  array<string, mixed>  $claims  переопределения утверждений
 */
function identityIdToken(array $claims = [], null|JWK $key = null): string
{
    $now = time();

    $payload = [
        'iss' => identityBaseUrl(),
        'aud' => config('identity.web.client_id'),
        'sub' => '01K5XQTESTSUBJECT0000000001',
        'sid' => '01K6YTTESTSESSION000000001',
        'auth_time' => $now,
        'iat' => $now,
        'exp' => $now + 300,
        'nonce' => 'test-nonce',
        'name' => 'Из ID-токена Устаревшее',
        ...$claims,
    ];

    $builder = new JWSBuilder(new AlgorithmManager([new RS256]));
    $signingKey = $key ?? identitySigningKey();

    $jws = $builder->create()
        ->withPayload((string) json_encode($payload))
        ->addSignature($signingKey, [
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => $signingKey->has('kid') ? $signingKey->get('kid') : 'test-key-1',
        ])
        ->build();

    return (new CompactSerializer)->serialize($jws);
}

/**
 * Токен доступа установки.
 *
 * Подпись здесь не важна: продукт разбирает его **без проверки** — токен
 * адресован identity как ресурсу, а не нам (PRD 12.2).
 *
 * @param  array<string, mixed>  $claims
 */
function identityAccessToken(array $claims = []): string
{
    $now = time();

    $payload = [
        'iss' => identityBaseUrl(),
        'aud' => [identityBaseUrl()],
        'sub' => '01K5XQTESTSUBJECT0000000001',
        'sid' => '01K6YTTESTSESSION000000001',
        'client_id' => 'web-client',
        'scope' => 'openid profile email',
        'roles' => ['user'],
        'entitlements' => [],
        'iat' => $now,
        'exp' => $now + 300,
        ...$claims,
    ];

    $segment = static fn (array $data): string => rtrim(
        strtr(base64_encode((string) json_encode($data)), '+/', '-_'),
        '=',
    );

    return $segment(['typ' => 'at+jwt', 'alg' => 'RS256', 'kid' => 'test-key-1'])
        .'.'.$segment($payload)
        .'.signature';
}

/**
 * Ответы установки по умолчанию: документ обнаружения и набор ключей.
 *
 * @param  array<string, mixed>  $extra  дополнительные образцы адресов
 */
function identityFakeHttp(array $extra = []): void
{
    Http::fake([
        identityBaseUrl().'/.well-known/openid-configuration' => Http::response(identityDiscovery()),
        identityBaseUrl().'/.well-known/jwks.json' => Http::response(identityJwks()),
        ...$extra,
    ]);
}

/**
 * Устанавливает сессию входа с токенами в хранилище.
 *
 * `$idToken` можно снять: тогда проверяется путь выхода без пригодной
 * подсказки — перенаправление на `/end-session` в этом случае невозможно
 * (справка 4.5).
 *
 * `$roles` и `$entitlements` кладутся в токен доступа — оттуда их читает
 * `CurrentIdentity`. Умолчания повторяют обычного сотрудника без ограничений;
 * набору, проверяющему маршрут под ролью, довольно передать `['admin']`.
 * Доводы объявлены здесь, а не оставлены наборам продукта на самостоятельную
 * сборку токена: иначе каждый продукт воспроизводил бы устройство утверждений,
 * а с ним и ошибки в нём.
 *
 * @param  list<string>  $roles
 * @param  list<string>  $entitlements
 */
function identityAuthenticate(
    string $sid = 'sid-1',
    string $sub = 'sub-1',
    bool $withIdToken = true,
    array $roles = ['user'],
    array $entitlements = [],
): void {
    app(TokenStore::class)->put($sid, new TokenSet(
        accessToken: identityAccessToken([
            'sid' => $sid,
            'sub' => $sub,
            'roles' => $roles,
            'entitlements' => $entitlements,
        ]),
        refreshToken: 'refresh-current',
        idToken: $withIdToken ? identityIdToken() : null,
        issuedAt: CarbonImmutable::now(),
        expiresAt: CarbonImmutable::now()->addSeconds(300),
        scope: 'openid profile email',
        sid: $sid,
        sub: $sub,
    ));

    session([
        'identity.sid' => $sid,
        'identity.sub' => $sub,
        'identity.authenticated_at' => CarbonImmutable::now()->toIso8601String(),
    ]);
}

/**
 * Ответ установки на рейл вошедшего.
 *
 * Поля `sub` в нём нет: оно было эхом присланного и из контракта 0.4 ушло.
 *
 * @return array<string, mixed>
 */
function identityNavigationResponse(): array
{
    return [
        'profile_url' => identityBaseUrl().'/profile',
        'logo_url' => identityBaseUrl().'/installation/logo',
        'items' => [
            [
                'id' => 'item-b',
                'name' => ['ru' => 'Пользователи', 'en' => 'Users'],
                'url' => identityBaseUrl().'/users',
                // Подписанная ссылка ограниченного срока, а не идентификатор
                // набора: набора иконок у установки больше нет.
                'icon_url' => 'https://storage.identity.test/navigation/icons/item-b.svg?signature=stub',
                'order' => 5,
            ],
            [
                'id' => 'item-a',
                'name' => ['ru' => 'Уборка', 'en' => 'Cleaning'],
                'url' => 'http://localhost/',
                'icon_url' => 'https://storage.identity.test/navigation/icons/item-a.svg?signature=stub',
                // Равный порядок: пункты обязаны сохранить порядок ответа.
                'order' => 5,
            ],
        ],
    ];
}

/**
 * Ответ установки на гостевой рейл.
 *
 * `profile_url` здесь **нет намеренно**, и это значащее отсутствие: страницы
 * профиля у гостя не существует. Разборщик читает такое поле пустой строкой,
 * и наборы обязаны проверять именно это, а не выдуманный адрес.
 *
 * @return array<string, mixed>
 */
function identityGuestNavigationResponse(): array
{
    $response = identityNavigationResponse();

    unset($response['profile_url']);

    return $response;
}

/**
 * Ответ, у пунктов которого одна и та же названная ссылка на иконку.
 *
 * Нужен там, где проверяется не состав рейла, а разбор самой ссылки: адрес
 * хранилища, порт, пустое значение.
 *
 * @return array<string, mixed>
 */
function identityNavigationResponseWithIcon(string $iconUrl): array
{
    $response = identityNavigationResponse();

    /** @var list<array<string, mixed>> $items */
    $items = $response['items'];

    $response['items'] = array_map(
        static function (array $item) use ($iconUrl): array {
            $item['icon_url'] = $iconUrl;

            return $item;
        },
        $items,
    );

    return $response;
}

/**
 * Подделка установки для обеих операций навигации.
 *
 * **Адрес у них один, различается метод**, и развести образцы по адресу
 * нельзя: `Http::fake()` сопоставляет ответ адресу, а не глаголу. Ветвление
 * поэтому живёт внутри замыкания — `GET` отдаёт гостевой набор, `POST`
 * пользовательский.
 */
function identityFakeNavigation(mixed $userStub = null, mixed $guestStub = null): void
{
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::response([
            'access_token' => 'service-token',
            'expires_in' => 300,
        ]),
        identityBaseUrl().'/api/navigation' => function ($request) use ($userStub, $guestStub) {
            if ($request->method() === 'GET') {
                return $guestStub ?? Http::response(identityGuestNavigationResponse());
            }

            return $userStub ?? Http::response(identityNavigationResponse());
        },
    ]);
}

/**
 * Сколько раз спрошен рейл вошедшего.
 *
 * Счётчики разведены по глаголу, а не по адресу: адрес у операций общий,
 * и один счётчик не отличил бы промах гостевого кэша от промаха
 * пользовательского.
 */
function identityNavigationRequests(): int
{
    return identityCountNavigationRequests('POST');
}

function identityGuestNavigationRequests(): int
{
    return identityCountNavigationRequests('GET');
}

function identityCountNavigationRequests(string $method): int
{
    $count = 0;

    Http::assertSent(function ($request) use (&$count, $method): bool {
        if (str_ends_with($request->url(), '/api/navigation') && $request->method() === $method) {
            $count++;
        }

        return true;
    });

    return $count;
}

/**
 * @param  list<string>  $subs
 * @return array{users: list<array<string, string>>}
 */
function identityResolveResponse(array $subs): array
{
    return [
        'users' => array_map(static fn (string $sub): array => [
            'sub' => $sub,
            'last_name' => 'Михайлов',
            'first_name' => 'Сергей',
            'middle_name' => 'Петрович',
            'name' => 'Михайлов Сергей Петрович',
        ], $subs),
    ];
}

/**
 * Подделка установки для разрешения имён.
 *
 * `Http::fake()` при повторном вызове не заменяет прежние образцы,
 * а дополняет их, и первый совпавший выигрывает. Поэтому набор объявляет
 * образец **один раз за тест**, а не в `beforeEach` с переопределением.
 */
function identityFakeResolve(mixed $resolveStub = null): void
{
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::response([
            'access_token' => 'service-token',
            'expires_in' => 300,
        ]),
        identityBaseUrl().'/api/users/resolve' => $resolveStub ?? function ($request) {
            /** @var list<string> $subs */
            $subs = $request['subs'];

            return Http::response(identityResolveResponse($subs));
        },
    ]);
}

function identityResolveRequests(): int
{
    $count = 0;

    Http::assertSent(function ($request) use (&$count): bool {
        if (str_ends_with($request->url(), '/api/users/resolve')) {
            $count++;
        }

        return true;
    });

    return $count;
}

/**
 * Забывает разрешённое имя.
 *
 * Нужно проверке критерия 77: снимок имени не хранится, и после смены
 * фамилии журнал обязан показать новое имя. Кэш здесь — ускорение,
 * а не источник истины.
 */
function identityForgetResolved(string $sub): void
{
    app(IdentityCache::class)->forget('user:'.$sub);
}
