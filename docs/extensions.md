# Точки расширения

Прикладные решения в пакет не зашиваются. Сверх собственной обработки каждое
сообщение установки предоставляет точку расширения для продукта; по умолчанию
она пуста.

Обе точки подменяются привязкой в продукте — **пакет при этом не правится**.

## Обработчик сообщений

```php
namespace Verdeect\IdentityIntegration\Events;

interface IIdentityEventHandler
{
    public function onProfileChanged(IdentityEvent $event): void;
    public function onRightsChanged(IdentityEvent $event): void;
    public function onSessionTerminated(IdentityEvent $event): void;
    public function onNavigationChanged(IdentityEvent $event): void;
}
```

`IdentityEvent` несёт `id`, `type`, `occurredAt`, `sub`, `sid`.

Умолчание — `NullIdentityEventHandler`, пустой. Продукту, которому нужно
реагировать на сообщения своим образом, достаточно объявить реализацию:

```php
// app/Providers/AppServiceProvider.php
use Verdeect\IdentityIntegration\Events\IIdentityEventHandler;

public function register(): void
{
    $this->app->bind(IIdentityEventHandler::class, ProductEventHandler::class);
}
```

**Сброс кэшей и отметки гашения делать не нужно** — это пакет выполняет сам,
до вызова точки расширения. Здесь место тому, чего пакет знать не может:
снять роль в собственной таблице, отправить уведомление, записать в журнал
продукта.

Реализация обязана быть **идемпотентной**: брокер гарантирует доставку
«хотя бы один раз», и одно и то же сообщение может прийти дважды.

## Украшатель профиля

```php
namespace Verdeect\IdentityIntegration\Profile;

interface IProfileDecorator
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function decorate(array $profile): array;
}
```

Через него проходит `ProfileProvider::currentDecorated()` — то есть ровно то,
что уходит в разделяемые свойства Inertia. Продукт может добавить рядом
с профилем свои поля:

```php
final readonly class ProductProfileDecorator implements IProfileDecorator
{
    public function decorate(array $profile): array
    {
        return [
            ...$profile,
            'isSupervisor' => $this->supervisors->contains($profile['sub']),
        ];
    }
}
```

Умолчание — `NullProfileDecorator`, возвращающий профиль как есть.

**Токены в украшатель не попадают и попадать не должны.** Всё, что он вернёт,
уходит в браузер.

## Чего в точках расширения быть не должно

- Обращений к установке. Профиль и имена запрашивает пакет; лишний вызов
  из украшателя выполнялся бы на каждой отрисовке страницы.
- Тяжёлых запросов к базе: украшатель работает в цикле запроса.
- Решений о доступе: пакет ролей не раздаёт, а продукт принимает их
  в собственном слое авторизации.

## Отсутствие ролей — решение продукта

Если продукту роли не нужны, обе точки расширения остаются пустыми, а политики
Laravel заводятся заглушками, разрешающими все действия. Это **решение
конкретного продукта, а не свойство пакета**: другой продукт той же установки
вправе решить иначе, ничего в библиотеке не меняя.

## Дальше

- [Данные пользователей](user-data.md)
- [Сообщения установки](messaging.md)
