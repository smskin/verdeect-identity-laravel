<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Verdeect\IdentityIntegration\Exceptions\SessionExpiredException;
use Verdeect\IdentityIntegration\Http\Middleware\DenyWritesWhenRestricted;
use Verdeect\IdentityIntegration\Http\Middleware\RequireIdentityRole;
use Verdeect\IdentityIntegration\Rights\CurrentIdentity;
use Verdeect\IdentityIntegration\Tokens\TokenSet;
use Verdeect\IdentityIntegration\Tokens\TokenStore;

/**
 * Маршрут-пробник под перечисленными посредниками.
 *
 * Собственных экранов у пакета нет, а проверяется именно связка посредника
 * с правами вошедшего — как в наборе сообщений установки.
 *
 * @param  list<class-string|string>  $middleware
 */
function identityGuardedRoute(array $middleware): void
{
    Route::middleware(['web', ...$middleware])
        ->get('/probe', static fn (): string => 'ok');
}

// ============================================================================
// Права вошедшего
// ============================================================================

it('reads roles from the current token', function (): void {
    identityAuthenticate(roles: ['admin']);

    expect(app(CurrentIdentity::class)->roles())->toBe(['admin'])
        ->and(app(CurrentIdentity::class)->hasRole('admin'))->toBeTrue()
        ->and(app(CurrentIdentity::class)->hasRole('user'))->toBeFalse();
});

/**
 * Гость — не отказ: вопрос «какие у него права» осмыслен и до входа.
 * Требование входа остаётся за `RequireIdentitySession`.
 */
it('answers with empty rights for a guest', function (): void {
    $identity = app(CurrentIdentity::class);

    expect($identity->roles())->toBe([])
        ->and($identity->entitlements())->toBe([])
        ->and($identity->hasRole('admin'))->toBeFalse()
        ->and($identity->allowsWrites())->toBeFalse();
});

/**
 * **Роль берётся из токена на каждом обращении, а не запоминается.**
 *
 * Понижение роли доезжает истечением токена доступа либо сообщением
 * `user.rights.changed`; запомненный ответ пережил бы оба, и понижение
 * применилось бы только к следующему входу (справка 13).
 */
it('never remembers the role between calls', function (): void {
    identityAuthenticate(roles: ['admin']);

    $identity = app(CurrentIdentity::class);

    expect($identity->hasRole('admin'))->toBeTrue();

    // Установка понизила роль; продукт получил новый токен обменом.
    identityAuthenticate(roles: ['user']);

    expect($identity->hasRole('admin'))->toBeFalse()
        ->and($identity->roles())->toBe(['user']);
});

// ============================================================================
// Ограничения
// ============================================================================

it('allows writes when there are no entitlements', function (): void {
    identityAuthenticate();

    expect(app(CurrentIdentity::class)->allowsWrites())->toBeTrue()
        ->and(app(CurrentIdentity::class)->isReadOnly())->toBeFalse();
});

it('denies writes in the read-only mode', function (): void {
    identityAuthenticate(entitlements: ['read_only']);

    expect(app(CurrentIdentity::class)->allowsWrites())->toBeFalse()
        ->and(app(CurrentIdentity::class)->isReadOnly())->toBeTrue();
});

/**
 * **Главное в наборе.** Перечень ограничений задаёт установка и расширяет
 * со временем; продукт, встретивший незнакомое значение, обязан запрещать всё,
 * кроме чтения. Проверка вида `in_array('read_only', …)` этому не отвечает:
 * ограничение, добавленное позже, такой продукт не узнал бы, и человек получил
 * бы полные права — без отказа, то есть незаметно.
 *
 * Поэтому запись разрешена только при пустом перечне.
 */
it('denies writes for an entitlement it does not know', function (): void {
    identityAuthenticate(entitlements: ['no_export']);

    expect(app(CurrentIdentity::class)->allowsWrites())->toBeFalse();
});

/**
 * `isReadOnly()` предназначен показу человеку, а не решению: отказ с причиной
 * отличается от отказа «недостаточно прав» (справка 4.7.4). Незнакомое
 * ограничение режимом чтения не является, но запись всё равно закрывает.
 */
it('tells the read-only mode apart from an unknown entitlement', function (): void {
    identityAuthenticate(entitlements: ['no_export']);

    expect(app(CurrentIdentity::class)->isReadOnly())->toBeFalse()
        ->and(app(CurrentIdentity::class)->allowsWrites())->toBeFalse();
});

// ============================================================================
// Посредник роли
// ============================================================================

it('lets the named role through', function (): void {
    identityAuthenticate(roles: ['admin']);

    identityGuardedRoute([RequireIdentityRole::class.':admin']);

    $this->get('/probe')->assertOk();
});

it('refuses a role the route does not name', function (): void {
    identityAuthenticate(roles: ['user']);

    identityGuardedRoute([RequireIdentityRole::class.':admin']);

    $this->get('/probe')->assertForbidden();
});

/**
 * Несколько ролей означают «любая из», а не «все сразу»: ролей в установке
 * две, и случая для пересечения нет.
 */
it('accepts any of the named roles', function (): void {
    identityAuthenticate(roles: ['user']);

    identityGuardedRoute([RequireIdentityRole::class.':admin,user']);

    $this->get('/probe')->assertOk();
});

// ============================================================================
// Посредник записи
// ============================================================================

it('lets a write through without entitlements', function (): void {
    identityAuthenticate();

    identityGuardedRoute([DenyWritesWhenRestricted::class]);

    $this->get('/probe')->assertOk();
});

it('refuses a write in the read-only mode', function (): void {
    identityAuthenticate(entitlements: ['read_only']);

    identityGuardedRoute([DenyWritesWhenRestricted::class]);

    $this->get('/probe')->assertForbidden();
});

it('refuses a write for an unknown entitlement', function (): void {
    identityAuthenticate(entitlements: ['something_new']);

    identityGuardedRoute([DenyWritesWhenRestricted::class]);

    $this->get('/probe')->assertForbidden();
});

/**
 * Ограничение закрывает запись, а не чтение: человек в режиме «только чтение»
 * обязан видеть данные продукта. Посредник вешается на маршруты записи,
 * и маршрут без него остаётся открытым.
 */
it('leaves a route without the guard open in the read-only mode', function (): void {
    identityAuthenticate(entitlements: ['read_only']);

    identityGuardedRoute([]);

    $this->get('/probe')->assertOk();
});

/**
 * Истёкшую сессию обрабатывает `RefreshIdentityToken` в начале запроса;
 * до `CurrentIdentity` такой случай не доходит. Но дойти он может из
 * консольной команды и обработчика очереди, где посредника нет, — и там отказ
 * обязан быть видимым, а не превращаться в «прав нет».
 */
it('surfaces an expired session instead of reporting no rights', function (): void {
    session(['identity.sid' => 'sid-1', 'identity.sub' => 'sub-1']);

    app(CurrentIdentity::class)->roles();
})->throws(SessionExpiredException::class);

/**
 * Токен просрочен, а установка отвергла обмен. Отказ тот же по существу:
 * молчаливое «прав нет» увело бы разбор в сторону учётной записи, тогда как
 * дело в сессии.
 */
it('surfaces a refused exchange instead of reporting no rights', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::response(
            ['error' => 'invalid_grant'],
            400,
        ),
    ]);

    app(TokenStore::class)->put('sid-1', new TokenSet(
        accessToken: identityAccessToken(),
        refreshToken: 'refresh-current',
        idToken: null,
        issuedAt: CarbonImmutable::now()->subHour(),
        expiresAt: CarbonImmutable::now()->subMinutes(30),
        scope: 'openid profile',
        sid: 'sid-1',
        sub: 'sub-1',
    ));

    session(['identity.sid' => 'sid-1', 'identity.sub' => 'sub-1']);

    app(CurrentIdentity::class)->allowsWrites();
})->throws(SessionExpiredException::class);

// ============================================================================
// Повтор перед отказом
// ============================================================================

/**
 * Ответ установки на обмен с заданными правами.
 *
 * @param  list<string>  $roles
 * @param  list<string>  $entitlements
 */
function identityRightsExchange(array $roles = ['user'], array $entitlements = []): void
{
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::response([
            'access_token' => identityAccessToken([
                'sid' => 'sid-1',
                'sub' => 'sub-1',
                'roles' => $roles,
                'entitlements' => $entitlements,
            ]),
            'refresh_token' => 'refresh-new',
            'expires_in' => 300,
        ]),
    ]);
}

/** Число обращений к эндпоинту выдачи токенов за прогон. */
function identityRightsExchanges(): int
{
    $count = 0;

    Http::recorded(static function ($request) use (&$count): bool {
        if (str_ends_with($request->url(), '/token')) {
            $count++;
        }

        return true;
    });

    return $count;
}

/**
 * Справка 9, «Дополнительно»: повышение роли применяется сразу.
 *
 * Сообщение `user.rights.changed` могло задержаться или не дойти вовсе —
 * потребитель у продукта может быть не поднят. Поэтому перед отказом
 * посредник обменивает токен и перепроверяет роль.
 */
it('lets a promoted role through after a forced exchange', function (): void {
    identityRightsExchange(roles: ['admin']);

    identityAuthenticate(roles: ['user']);

    identityGuardedRoute([RequireIdentityRole::class.':admin']);

    $this->get('/probe')->assertOk();

    expect(identityRightsExchanges())->toBe(1);
});

/**
 * То же для снятого ограничения: запись открывается первым же запросом.
 */
it('lets a write through after the restriction is lifted', function (): void {
    identityRightsExchange(entitlements: []);

    identityAuthenticate(entitlements: ['read_only']);

    identityGuardedRoute([DenyWritesWhenRestricted::class]);

    $this->get('/probe')->assertOk();

    expect(identityRightsExchanges())->toBe(1);
});

/**
 * Обмен подтвердил отказ — отказ остаётся отказом, и обмен ровно один.
 */
it('refuses when the exchange confirms the denial', function (): void {
    identityRightsExchange(roles: ['user']);

    identityAuthenticate(roles: ['user']);

    identityGuardedRoute([RequireIdentityRole::class.':admin']);

    $this->get('/probe')->assertForbidden();

    expect(identityRightsExchanges())->toBe(1);
});

/**
 * **Дроссель.** Честный отказ — случай обычный, и без дросселя каждая попытка
 * открыть чужой экран звала бы установку.
 */
it('throttles repeated forced exchanges', function (): void {
    identityRightsExchange(roles: ['user']);

    identityAuthenticate(roles: ['user']);

    identityGuardedRoute([RequireIdentityRole::class.':admin']);

    $this->get('/probe')->assertForbidden();
    $this->get('/probe')->assertForbidden();

    expect(identityRightsExchanges())->toBe(1);
});

/**
 * Обмен без refresh-токена дал бы `SessionExpiredException`, и честный отказ
 * обернулся бы выходом из продукта. Поэтому обмена нет вовсе.
 */
it('keeps the denial when there is nothing to exchange', function (): void {
    identityRightsExchange(roles: ['admin']);

    identityAuthenticate(roles: ['user']);

    $stored = app(TokenStore::class)->get('sid-1');

    app(TokenStore::class)->put('sid-1', new TokenSet(
        accessToken: $stored->accessToken,
        refreshToken: null,
        idToken: $stored->idToken,
        issuedAt: $stored->issuedAt,
        expiresAt: $stored->expiresAt,
        scope: $stored->scope,
        sid: $stored->sid,
        sub: $stored->sub,
    ));

    identityGuardedRoute([RequireIdentityRole::class.':admin']);

    $this->get('/probe')->assertForbidden();

    expect(identityRightsExchanges())->toBe(0)
        ->and(session()->get('identity.sid'))->toBe('sid-1');
});

/**
 * Гость до посредника роли доходит только без `RequireIdentitySession`
 * впереди, и обменивать ему нечего: сессии нет.
 */
it('refuses a guest without calling identity', function (): void {
    identityFakeHttp();

    identityGuardedRoute([RequireIdentityRole::class.':admin']);

    $this->get('/probe')->assertForbidden();

    expect(identityRightsExchanges())->toBe(0);
});

/**
 * Установка отвергла обмен: человек не «без прав», а больше не вошёл —
 * и уходит на форму входа, а не получает отказ.
 */
it('sends the visitor to login when the exchange is refused', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    identityAuthenticate(roles: ['user']);

    identityGuardedRoute([RequireIdentityRole::class.':admin']);

    $this->get('/probe')->assertRedirect(route('identity.login'));

    expect(session()->has('identity.sid'))->toBeFalse();
});

/**
 * Недоступность установки не роняет страницу (`DESCRIPTION.md`): отказ
 * остаётся отказом, а не превращается в `500`.
 */
it('keeps the denial when identity is unreachable', function (): void {
    Http::fake([
        identityBaseUrl().'/.well-known/openid-configuration' => Http::response('', 500),
    ]);

    identityAuthenticate(roles: ['user']);

    identityGuardedRoute([RequireIdentityRole::class.':admin']);

    $this->get('/probe')->assertForbidden();

    expect(session()->get('identity.sid'))->toBe('sid-1');
});
