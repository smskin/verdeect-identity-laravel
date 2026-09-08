<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Api;

/**
 * Данные рейла кросс-сервисной навигации для одного человека.
 *
 * Пункты приходят **уже отфильтрованными по роли** и в порядке отображения.
 * Своих правил видимости продукт не применяет: их целиком знает установка
 * (справка 7.3, критерий 90).
 */
final readonly class NavigationData
{
    /**
     * @param  list<NavItem>  $items
     */
    public function __construct(
        public string $sub,
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
            sub: is_string($payload['sub'] ?? null) ? $payload['sub'] : '',
            profileUrl: is_string($payload['profile_url'] ?? null) ? $payload['profile_url'] : '',
            logoUrl: is_string($payload['logo_url'] ?? null) ? $payload['logo_url'] : '',
            // Порядок сохраняется как пришёл; пересортировка запрещена.
            items: array_map(NavItem::fromResponse(...), $rows),
        );
    }

    public static function empty(string $sub): self
    {
        return new self(sub: $sub, profileUrl: '', logoUrl: '', items: []);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sub' => $this->sub,
            'profileUrl' => $this->profileUrl,
            'logoUrl' => $this->logoUrl,
            'items' => array_map(static fn (NavItem $item): array => $item->toArray(), $this->items),
        ];
    }

    /**
     * Чтение записи кэша.
     *
     * Поля пункта совпадают с полями ответа установки (`id`, `name`, `url`,
     * `icon`, `order`), поэтому разбор у них один.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($payload['items'] ?? null) ? array_values($payload['items']) : [];

        return new self(
            sub: (string) ($payload['sub'] ?? ''),
            profileUrl: (string) ($payload['profileUrl'] ?? ''),
            logoUrl: (string) ($payload['logoUrl'] ?? ''),
            items: array_map(NavItem::fromResponse(...), $rows),
        );
    }
}
