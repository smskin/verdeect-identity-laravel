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
    identityFakeServices();                  // ответы /api/users/services
    identityAuthenticate('sid-1', 'sub-1');  // сессия входа и токены

    $this->get('/')->assertOk();
});
```

| Функция | Что делает |
| --- | --- |
| `identityFakeHttp(array $extra = [])` | документ обнаружения и набор ключей; `$extra` — свои образцы адресов |
| `identityFakeServices(mixed $stub = null)` | то же плюс ответ `/api/users/services` |
| `identityFakeResolve(mixed $stub = null)` | то же плюс ответ `/api/users/resolve` |
| `identityAuthenticate(string $sid, string $sub, bool $withIdToken = true)` | сессия входа с токенами в хранилище |
| `identityBaseUrl()` | адрес поддельной установки |
| `identityAccessToken()`, `identityIdToken()` | токены с нужными утверждениями |
| `identityServicesRequests()`, `identityResolveRequests()` | сколько обращений ушло — для проверки кэша и пакетности |
| `identityForgetResolved(string $sub)` | сброс кэша имени между проверками |

Сняв `withIdToken`, вы проверяете путь выхода без пригодной подсказки:
перенаправление на `/end-session` в этом случае невозможно.

## Что стоит проверять в продукте

**Экран закрыт входом:**

```php
it('sends visitors without a session to the installation login', function (): void {
    identityFakeHttp();

    $this->get('/')->assertRedirect(route('identity.login'));
});
```

**Токен не попадает в браузер** — проверка дешёвая, а цена ошибки высокая:

```php
it('never puts tokens into the page', function (): void {
    identityFakeServices();
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
продукту **не нужно** — это покрыто прогоном пакета (82 набора). Продукт
проверяет своё: что экраны закрыты, данные доехали и токен не утёк.

## Браузерные сценарии

Против поддельной установки поток входа не проверяется: PKCE, `state`, `nonce`,
проверка подписи и точное совпадение адреса возврата имеют смысл только при
настоящем сервере авторизации. Для браузерных прогонов поднимается **реальная
установка**, а хелпер входа поставляет npm-пакет
`@verdeect/identity-integration-vue` входом `./playwright`.

## Дальше

- [Чек-лист интеграции](checklist.md)
- [Сообщения установки](messaging.md)
