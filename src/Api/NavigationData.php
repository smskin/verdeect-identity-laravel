<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Api;

/**
 * Данные рейла кросс-сервисной навигации.
 *
 * Пункты приходят **уже отфильтрованными по роли** и в порядке отображения.
 * Своих правил видимости продукт не применяет: их целиком знает установка
 * (справка 7.3, критерий 90).
 *
 * **Форма одна на вошедшего и на гостя.** `sub` из ответа установки ушёл:
 * он был эхом присланного и ничего не добавлял, а гостевая операция подать
 * его неоткуда. Вторая форма ради одного отсутствующего поля заставила бы
 * каждого потребителя различать два состава там, где показывать нужно одно
 * и то же.
 *
 * **Пустой `profileUrl` означает «страницы профиля у смотрящего нет».**
 * У гостя `profile_url` в ответе отсутствует, и необязательное поле
 * заставило бы различать «не пришло» и «пусто», хотя показывать в обоих
 * случаях нечего. Тот же приём уже применён к `UserProfile::$email`.
 */
final readonly class NavigationData
{
    /**
     * @param  list<NavItem>  $items
     */
    public function __construct(
        public string $profileUrl,
        public string $logoUrl,
        public array $items,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromResponse(array $payload): self
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($payload['items'] ?? null) ? array_values($payload['items']) : [];

        return new self(
            profileUrl: is_string($payload['profile_url'] ?? null) ? $payload['profile_url'] : '',
            logoUrl: is_string($payload['logo_url'] ?? null) ? $payload['logo_url'] : '',
            // Порядок сохраняется как пришёл; пересортировка запрещена.
            items: array_map(NavItem::fromResponse(...), $rows),
        );
    }

    public static function empty(): self
    {
        return new self(profileUrl: '', logoUrl: '', items: []);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'profileUrl' => $this->profileUrl,
            'logoUrl' => $this->logoUrl,
            'items' => array_map(static fn (NavItem $item): array => $item->toArray(), $this->items),
        ];
    }

    /**
     * Чтение записи кэша.
     *
     * **Разбор пункта свой, а не общий с ответом установки.** Прежде формы
     * совпадали поле в поле; с переименованием `icon` → `icon_url` совпадение
     * кончилось — ответ несёт `snake_case`, запись кэша `camelCase`, — и
     * разборщики разведены (`NavItem::fromArray()` против `fromResponse()`).
     * Прочие поля записи (`profileUrl`, `logoUrl`) в `camelCase` были и раньше.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($payload['items'] ?? null) ? array_values($payload['items']) : [];

        return new self(
            profileUrl: (string) ($payload['profileUrl'] ?? ''),
            logoUrl: (string) ($payload['logoUrl'] ?? ''),
            items: array_map(NavItem::fromArray(...), $rows),
        );
    }
}
