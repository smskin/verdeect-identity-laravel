---
name: identity-package-tests
description: >-
  Соглашения наборов пакета verdeect/identity-laravel: Pest 5 поверх
  orchestra/testbench 11, отсутствие базы данных, оснастка identity* из
  testing/helpers.php, поддельная установка через Http::fake, окружение
  прогона в phpunit.xml. Применять перед созданием или правкой любого файла
  в tests/ и testing/. Use when writing, editing or debugging Pest tests for
  this Laravel library package, adding a test helper, or faking the identity
  installation.
metadata:
  author: verdeect
  version: "1.0"
  category: testing
---

# Наборы пакета verdeect/identity-laravel

Прогон: `composer test` (Pest 5 поверх `orchestra/testbench` 11).
Перед сдачей — `composer ci:check`: PHPStan уровня 7, затем прогон.

Соглашения самого кода — в скилле `identity-package-conventions`.

## Устройство прогона

- Собственного приложения у пакета нет: контейнер Laravel поднимает testbench.
- `tests/TestCase.php` регистрирует `IdentityServiceProvider` **вручную**
  через `getPackageProviders()`. Обнаружения Composer внутри собственного
  репозитория нет.

  Сигнатура `getPackageProviders($app)` повторяет исходную из testbench
  **без типа возврата**: объявленный здесь тип разойдётся с родительской
  и уронит прогон.
- `tests/Pest.php` распространяет `TestCase` на `Feature` и `Unit`.
- **Базы данных нет.** `RefreshDatabase` не подключается, фабрик и миграций
  не заводить: `sub` хранит продукт, пакет в базу не пишет.
- Окружение прогона задано в `phpunit.xml` и из окружения разработчика
  не берётся: адреса возврата сверяются точным совпадением, и порт из чужого
  `.env` сделал бы прогон невоспроизводимым.
  Кэш — `array` (хранилище с поддержкой блокировок; Redis не нужен),
  сессии — `array`, очередь — `sync`.
- Установка identity в прогоне поддельная, значения непустые: пакет отвечает
  на пустую настройку внятным отказом, и он же сработал бы здесь.

## Где что лежит

| Каталог | Что | Уходит в поставку |
|---|---|---|
| `testing/helpers.php` | общая оснастка: поддельная установка, ключ, токены | **да**, через `autoload.files` |
| `tests/Feature/` | наборы поверх контейнера | нет |
| `tests/Unit/` | наборы отдельных вычислений | нет |

**Новую общую оснастку класть в `testing/`, а не в `tests/`.** Каталог `tests/`
в архив Composer не входит: наборы продукта тоже проверяют экраны за входом,
и оставленная в `tests/` оснастка была бы доступна только самому пакету.

## Оснастка

Все функции — с приставкой `identity`. Приставка обязательна: файлы Pest
объявляют функции глобально, и одноимённая в другом наборе уронит весь прогон.

| Функция | Что делает |
|---|---|
| `identityBaseUrl()` | адрес поддельной установки |
| `identitySigningKey()` | ключ RSA-2048, порождается один раз на прогон |
| `identityJwks(?$kid)` | открытая часть ключа набором JWKS; `$kid` проверяет ротацию |
| `identityDiscovery()` | документ обнаружения |
| `identityIdToken($claims, $key)` | подписанный ID-токен с переопределениями |
| `identityAccessToken($claims)` | токен доступа (подпись не важна: продукт разбирает его без проверки) |
| `identityFakeHttp($extra)` | образцы обнаружения и JWKS плюс переданные |
| `identityAuthenticate($sid, $sub, $withIdToken)` | сессия входа с токенами в хранилище |
| `identityFakeNavigation($userStub, $guestStub)` | обе операции `/api/navigation`: `POST` — рейл вошедшего, `GET` — гостевой |
| `identityNavigationRequests()` / `identityGuestNavigationRequests()` | счётчики обращений; разведены по глаголу, потому что адрес общий |
| `identityFakeResolve($stub)` / `identityResolveRequests()` | разрешение имён и счётчик обращений |
| `identityForgetResolved($sub)` | забыть разрешённое имя |

Ключ подписи порождается один раз на прогон: генерация RSA-2048 занимает
заметное время, а наборам нужен один и тот же ключ во всех сценариях.

## Http::fake

**`Http::fake()` при повторном вызове образцы не заменяет, а дополняет,
и первый совпавший выигрывает.**

Значит: образец объявляется **один раз за набор**, а не в `beforeEach`
с переопределением внутри `it()`. Переопределение молча проиграет первому
объявленному образцу, и набор пройдёт не то, что задумывался.

```php
it('refreshes ahead of expiry', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::response([
            'access_token' => 'access-new',
            'refresh_token' => 'refresh-new',
            'expires_in' => 300,
            'scope' => 'openid profile',
        ]),
    ]);

    // …
});
```

Нужен другой ответ установки — заводите отдельный `it()`, а не второй
`Http::fake()`.

## Как пишется набор

```php
<?php

declare(strict_types=1);

use Verdeect\IdentityIntegration\Support\IdentityOrigin;

/**
 * Пустой адрес — не отказ: политика содержимого обязана строиться
 * и на неподнятом окружении, иначе сборка конфигурации валилась бы
 * на пустом `.env`.
 */
it('returns an empty origin when the address is not set', function (): void {
    config(['identity.base_url' => null]);

    expect(app(IdentityOrigin::class)->resolve())->toBe('');
});
```

- `declare(strict_types=1);` в каждом файле набора.
- Классов нет: функциональный стиль Pest, `it('…', function (): void { … })`.
  Тип возврата у замыкания указывается.
- Название набора — по-английски, строчными, описывает поведение
  (`it('never truncates')`), а не имя метода.
- Докблок над `it()` — **по-русски и про причину**: почему это поведение
  вообще требуется. Пишется там, где причина неочевидна; над тривиальным
  набором не нужен.
- Зависимости достаются из контейнера через `app(Класс::class)`.
- Настройка подменяется через `config([...])` прямо в наборе.
- Утверждения цепочкой `expect(...)->toBe(...)->and(...)`.
- Локальная оснастка одного файла объявляется функцией в нём же — с той же
  приставкой `identity` (например `identityStoredTokens()`), иначе она
  столкнётся с одноимённой из соседнего набора.

## Что проверять обязательно

- **Граница пакета.** `tests/Feature/ProviderTest.php` перебирает `src/`
  и ищет `App\`. Правя этот набор, не ослабляйте образец.
- **Состав публикуемого.** Публикуется только `identity-config`; группы
  компонентов интерфейса быть не должно.
- **Деградацию.** Недоступность установки не роняет страницу: устаревший кэш
  обнаружения, неразрешённые `sub` как есть, локальный выход.
- **Отсутствие токенов наружу.** В разделяемых свойствах Inertia токенов нет.
- **Состязания.** Обмен под блокировкой: параллельные обращения не должны
  предъявить установке прежний refresh-токен.

## Отладка

```
vendor/bin/pest --filter 'часть названия'
vendor/bin/pest tests/Feature/TokenRefreshTest.php
composer types:check
```

Уровень PHPStan не снижается и предупреждения не подавляются. Если разбор
ругается на набор — правится набор, а не `phpstan.neon`.
