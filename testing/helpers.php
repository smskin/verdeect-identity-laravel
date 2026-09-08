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
        'scope' => 'openid profile',
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
 */
function identityAuthenticate(
    string $sid = 'sid-1',
    string $sub = 'sub-1',
    bool $withIdToken = true,
): void {
    app(TokenStore::class)->put($sid, new TokenSet(
        accessToken: identityAccessToken(['sid' => $sid, 'sub' => $sub]),
        refreshToken: 'refresh-current',
        idToken: $withIdToken ? identityIdToken() : null,
        issuedAt: CarbonImmutable::now(),
        expiresAt: CarbonImmutable::now()->addSeconds(300),
        scope: 'openid profile',
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
 * @return array<string, mixed>
 */
function identityServicesResponse(): array
{
    return [
        'sub' => 'sub-1',
        'profile_url' => identityBaseUrl().'/profile',
        'logo_url' => identityBaseUrl().'/installation/logo',
        'items' => [
            [
                'id' => 'item-b',
                'name' => ['ru' => 'Пользователи', 'en' => 'Users'],
                'url' => identityBaseUrl().'/users',
                'icon' => 'pi-users',
                'order' => 5,
            ],
            [
                'id' => 'item-a',
                'name' => ['ru' => 'Уборка', 'en' => 'Cleaning'],
                'url' => 'http://localhost/',
                'icon' => 'pi-home',
                // Равный порядок: пункты обязаны сохранить порядок ответа.
                'order' => 5,
            ],
        ],
    ];
}

function identityFakeServices(mixed $stub = null): void
{
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::response([
            'access_token' => 'service-token',
            'expires_in' => 300,
        ]),
        identityBaseUrl().'/api/users/services' => $stub ?? Http::response(identityServicesResponse()),
    ]);
}

function identityServicesRequests(): int
{
    $count = 0;

    Http::assertSent(function ($request) use (&$count): bool {
        if (str_ends_with($request->url(), '/api/users/services')) {
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
