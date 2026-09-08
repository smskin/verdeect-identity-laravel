<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Inertia;

use Illuminate\Support\Facades\Log;
use Verdeect\IdentityIntegration\Api\NavigationClient;
use Verdeect\IdentityIntegration\Profile\ProfileProvider;
use Verdeect\IdentityIntegration\Session\IdentitySession;

/**
 * Разделяемые свойства identity для интерфейса на Inertia.
 *
 * **Токенов в возвращаемом составе нет и быть не может.** Схема
 * Backend-for-Frontend обязательна: токены живут на сервере, а в браузер
 * уходят имя пользователя и готовые данные рейла. Этот класс — единственное
 * место, где решается, что именно из identity видит интерфейс.
 *
 * Пакет предоставляет сборку, но **не подменяет middleware продукта**:
 * состав разделяемых свойств принадлежит приложению, и продукт вызывает
 * сборку сам — тем же правилом, каким пакет не уничтожает сессии.
 */
final class IdentityProps
{
    public function __construct(
        private readonly IdentitySession $session,
        private readonly ProfileProvider $profiles,
        private readonly NavigationClient $navigation,
    ) {}

    /**
     * Состав свойств `identity` для текущего запроса.
     *
     * Без сессии данные рейла **пусты, а не отсутствуют**: интерфейс
     * различает «рейла нет» и «свойство не пришло», и второе означало бы
     * ошибку доставки, а не отсутствие пунктов.
     *
     * @return array{
     *     profile: array<string, mixed>|null,
     *     crossService: array{items: list<array<string, mixed>>, profileUrl: string, logoUrl: string}
     * }
     */
    public function share(): array
    {
        $sub = $this->session->sub();

        if ($sub === null) {
            Log::debug('[IdentityProps.share] no identity session', [
                'shared' => 'empty',
            ]);

            return [
                'profile' => null,
                'crossService' => [
                    'items' => [],
                    'profileUrl' => '',
                    'logoUrl' => '',
                ],
            ];
        }

        $navigation = $this->navigation->for($sub);
        $profile = $this->profiles->currentDecorated();

        Log::debug('[IdentityProps.share] identity props shared', [
            'sub' => $sub,
            'items' => count($navigation->items),
            'profile' => $profile !== null,
        ]);

        return [
            'profile' => $profile,
            'crossService' => [
                'items' => array_map(
                    static fn ($item): array => $item->toArray(),
                    $navigation->items,
                ),
                'profileUrl' => $navigation->profileUrl,
                'logoUrl' => $navigation->logoUrl,
            ],
        ];
    }
}
