<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Api;

/**
 * Пункт рейла кросс-сервисной навигации.
 *
 * Имя приходит объектом по языкам: выбор языка — дело того, кто отрисовывает
 * (справка 7.3).
 *
 * **Иконка приходит подписанной ссылкой ограниченного срока, а не
 * идентификатором набора.** Набора больше нет: шрифт иконок пришлось бы
 * подключать в каждом продукте установки, а продукты о нём не знают
 * и подключать не обязаны. Файл загружает администратор установки, хранилище
 * приватно, и постоянного адреса у файла не существует.
 *
 * **Отсюда обязанность продукта:** не хранить ответ дольше, чем живёт ссылка.
 * Срок подписи задаёт установка и держит его заметно выше сроков кэша, с
 * которыми пакет поставляется (`identity.cache.navigation_ttl` и
 * `identity.cache.navigation_guest_ttl`); подняв свой срок, продукт обязан
 * убедиться, что запас сохранился. Ссылка, протухшая раньше кэша, даёт
 * битые иконки у всех его пользователей до следующего промаха кэша, а
 * у гостевой записи — на каждой странице без сессии сразу.
 *
 * **Вторая обязанность — политика содержимого:** `img-src` продукта обязан
 * пропускать не только origin установки (ради логотипа), но и origin хранилища
 * иконок. Он виден в самих ссылках; отдельным полем не приходит и зашиваться
 * в продукт не должен.
 *
 * Не загрузившаяся иконка — **штатный случай**, а не отказ: компонент рейла
 * заменяет её первой буквой имени. Так же он поступает с пустой ссылкой,
 * которую разборщик подставляет ответу установки, ещё не перешедшей
 * на загружаемые иконки.
 */
final readonly class NavItem
{
    /**
     * @param  array<string, string>  $name
     */
    public function __construct(
        public string $id,
        public array $name,
        public string $url,
        public string $iconUrl,
        public int $order,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromResponse(array $row): self
    {
        /** @var array<string, string> $name */
        $name = is_array($row['name'] ?? null)
            ? array_filter($row['name'], is_string(...))
            : [];

        return new self(
            id: is_string($row['id'] ?? null) ? $row['id'] : '',
            name: $name,
            url: is_string($row['url'] ?? null) ? $row['url'] : '',
            /*
             * Отсутствующее поле даёт пустую строку, а не отказ: разборщик
             * пакета терпим к составу ответа по общему правилу. Здесь у этого
             * есть и второе следствие — установка, ещё отдающая прежнее поле
             * `icon`, не роняет рейл продукта, а показывает его с заглушками
             * вместо иконок.
             */
            iconUrl: is_string($row['icon_url'] ?? null) ? $row['icon_url'] : '',
            order: is_int($row['order'] ?? null) ? $row['order'] : 0,
        );
    }

    /**
     * Чтение записи кэша.
     *
     * **Отдельный разборщик, а не общий с `fromResponse()`.** Прежде формы
     * совпадали поле в поле, и разбор у них был один; с переименованием
     * `icon` → `icon_url` совпадение кончилось: ответ установки несёт
     * `icon_url` в `snake_case`, а запись кэша — `iconUrl`, потому что она
     * же уходит в свойства интерфейса. Общий разборщик молча подставлял бы
     * пустую ссылку одной из двух сторон, и рейл показывал бы заглушки вместо
     * иконок — при исправной установке.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        /** @var array<string, string> $name */
        $name = is_array($row['name'] ?? null)
            ? array_filter($row['name'], is_string(...))
            : [];

        return new self(
            id: is_string($row['id'] ?? null) ? $row['id'] : '',
            name: $name,
            url: is_string($row['url'] ?? null) ? $row['url'] : '',
            iconUrl: is_string($row['iconUrl'] ?? null) ? $row['iconUrl'] : '',
            order: is_int($row['order'] ?? null) ? $row['order'] : 0,
        );
    }

    /**
     * Состав, уходящий в кэш и в свойства интерфейса.
     *
     * Ключ `iconUrl` записан в `camelCase`, в отличие от `icon_url` ответа:
     * массив попадает в prop компонента рейла, а тот объявляет поля так же,
     * как прочие свойства интерфейса.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'iconUrl' => $this->iconUrl,
            'order' => $this->order,
        ];
    }
}
