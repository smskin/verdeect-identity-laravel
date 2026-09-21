# Тестирование продукта

Экраны продукта живут за входом, поэтому его собственные наборы обязаны уметь
входить. Пакет поставляет для этого оснастку — писать поддельную установку
заново не нужно.

## Оснастка приходит автозагрузкой

Файл `testing/helpers.php` попадает в поставку через `autoload.files`
композера: он лежит в `testing/`, а не в `tests/`, потому что `tests/`
в архив composer не входит.

Подключать вручную ничего не нужно — функции доступны в наборах продукта сразу
после установки пакета. Все они несут приставку `identity`: файлы Pest
объявляют функции глобально, и одноимённая функция другого набора уронила бы
весь прогон.

## Окружение прогона

В `phpunit.xml` продукта задаётся поддельная установка. Значения обязаны быть
непустыми: пакет отвечает на пустую настройку внятным отказом, и он же
сработал бы в прогоне.

```xml
<env name="IDENTITY_BASE_URL" value="https://id.example.test"/>
<env name="IDENTITY_REDIRECT_URI" value="http://localhost/auth/callback"/>
<env name="IDENTITY_POST_LOGOUT_REDIRECT_URI" value="http://localhost/"/>
<env name="IDENTITY_WEB_CLIENT_ID" value="test-web-client"/>
<env name="IDENTITY_WEB_CLIENT_SECRET" value="test-web-secret"/>
<env name="IDENTITY_SERVICE_CLIENT_ID" value="test-service-client"/>
<env name="IDENTITY_SERVICE_CLIENT_SECRET" value="test-service-secret"/>
<!-- Хранилище с поддержкой блокировок; Redis в прогоне не нужен. -->
<env name="IDENTITY_CACHE_STORE" value="array"/>
<env name="BROKER_QUEUE" value="product.identity.events.testing"/>
```

Настоящая установка в прогоне не участвует: обращения перехватывает
`Http::fake()`.

## Вход в наборах

```php
it('shows the dashboard to a signed in user', function (): void {
    identityFakeNavigation();                // ответы /api/navigation
    identityAuthenticate('sid-1', 'sub-1');  // сессия входа и токены

    $this->get('/')->assertOk();
});
```

| Функция | Что делает |
| --- | --- |
| `identityFakeHttp(array $extra = [])` | документ обнаружения, набор ключей и обмен токена; `$extra` — свои образцы адресов |
| `identityFakeNavigation(mixed $userStub = null, mixed $guestStub = null)` | то же плюс обе операции `/api/navigation`: `POST` — рейл вошедшего, `GET` — гостевой |
| `identityFakeResolve(mixed $stub = null)` | то же плюс ответ `/api/users/resolve` |
| `identityAuthenticate(string $sid, string $sub, bool $withIdToken = true, array $roles = ['user'], array $entitlements = [])` | сессия входа с токенами в хранилище; `$roles` и `$entitlements` кладутся в токен доступа — оттуда их читает `CurrentIdentity` |
| `identityBaseUrl()` | адрес поддельной установки |
| `identityAccessToken()`, `identityIdToken()` | токены с нужными утверждениями |
| `identityNavigationRequests()`, `identityGuestNavigationRequests()`, `identityResolveRequests()` | сколько обращений ушло — для проверки кэша и пакетности; счётчики навигации разведены по глаголу, потому что адрес у операций общий |
| `identityForgetResolved(string $sub)` | сброс кэша имени между проверками |

Сняв `withIdToken`, вы проверяете путь выхода без пригодной подсказки:
перенаправление на `/end-session` в этом случае невозможно.

### Обмен токена подделан по умолчанию

`identityFakeHttp()` отвечает и на `/token`, отдавая **тот же** токен, что лежит
в хранилище: обмен происходит, но утверждения не меняются.

Это нужно потому, что токен обменивается не только по сроку. Посредники прав
обменивают его перед отказом, а отметка `user.rights.changed` — в начале
запроса. Без образца подделка отвечала бы на `/token` пустым `200`, набор
токенов обнулялся бы посреди набора и унёс с собой роль и ограничения —
проверка отказа объяснила бы причину неверно.

Набору, который проверяет **изменившиеся** права, довольно передать свой
образец: он побеждает умолчание.

```php
it('applies a promoted role at once', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::response([
            'access_token' => identityAccessToken(['roles' => ['admin']]),
            'refresh_token' => 'refresh-new',
            'expires_in' => 300,
        ]),
    ]);

    identityAuthenticate(roles: ['user']);

    $this->get('/settings')->assertOk();
});
```

**Ответ обмена без `access_token` считается отказом** и уводит на вход: пустая
строка в наборе затирала бы рабочий токен вместе с правами.

## Что стоит проверять в продукте

**Экран закрыт входом:**

```php
it('sends visitors without a session to the installation login', function (): void {
    identityFakeHttp();

    $this->get('/')->assertRedirect(route('identity.login'));
});
```

**Маршрут закрыт по роли** — и закрыт сервером, а не разметкой:

```php
it('keeps a regular employee out of the admin screens', function (): void {
    identityFakeHttp();
    identityAuthenticate(roles: ['user']);

    $this->get('/settings')->assertForbidden();
});

it('lets an administrator in', function (): void {
    identityFakeHttp();
    identityAuthenticate(roles: ['admin']);

    $this->get('/settings')->assertOk();
});
```

**Запись закрыта при ограничении, а чтение — нет.** Вторая половина здесь
важнее первой: ограничение закрывает запись, а не доступ к продукту, и набор,
проверяющий только отказ, пропустил бы экран, ставший недоступным целиком:

```php
it('denies a write in the read-only mode but keeps reading', function (): void {
    identityFakeHttp();
    identityAuthenticate(entitlements: ['read_only']);

    $this->get('/reports')->assertOk();
    $this->post('/reports', [/* … */])->assertForbidden();
});
```

**Незнакомое ограничение тоже закрывает запись.** Проверка стоит строки,
а ловит расширение прав при появлении второго ограничения в установке:

```php
it('denies a write for an entitlement the product does not know', function (): void {
    identityFakeHttp();
    identityAuthenticate(entitlements: ['something_new']);

    $this->post('/reports', [/* … */])->assertForbidden();
});
```

**Токен не попадает в браузер** — проверка дешёвая, а цена ошибки высокая:

```php
it('never puts tokens into the page', function (): void {
    identityFakeNavigation();
    identityAuthenticate('sid-1', 'sub-1');

    $response = $this->get('/');
    $accessToken = app(TokenStore::class)->get('sid-1')->accessToken;

    expect($response->getContent())->not->toContain($accessToken);
});
```

**Имена разрешаются пакетно** — счётчик обращений ловит цикл лучше любого
ревью:

```php
it('resolves all authors in one call', function (): void {
    identityFakeResolve();
    identityAuthenticate();

    $this->get('/journal')->assertOk();

    expect(identityResolveRequests())->toBe(1);
});
```

**Origin установки в политике содержимого:**

```php
$policy = (string) $this->get('/')->headers->get('Content-Security-Policy');

expect($policy)->toContain('img-src')
    ->and($policy)->toContain(identityBaseUrl());
```

Проверять сам поток кода, обмен токенов, разбор JWKS и потребление сообщений
продукту **не нужно** — это покрыто прогоном пакета (141 набор). Продукт
проверяет своё: что экраны закрыты, данные доехали и токен не утёк.

## Браузерные сценарии

Против поддельной установки поток входа не проверяется: PKCE, `state`, `nonce`,
проверка подписи и точное совпадение адреса возврата имеют смысл только при
настоящем сервере авторизации. Для браузерных прогонов поднимается **реальная
установка**, а хелпер входа поставляет npm-пакет
`@verdeect/identity-nav-vue` входом `./playwright`.

## Дальше

- [Чек-лист интеграции](checklist.md)
- [Сообщения установки](messaging.md)
