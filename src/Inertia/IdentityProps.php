<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Inertia;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Verdeect\IdentityIntegration\Api\NavItem;
use Verdeect\IdentityIntegration\Api\NavigationClient;
use Verdeect\IdentityIntegration\Api\NavigationData;
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
     * **Форма одна на вошедшего и на гостя.** У гостя `profile` равен `null`,
     * а `crossService` заполняется гостевым набором установки: рейл гостю
     * положен, и прежняя пустая заглушка прятала его даже там, где установка
     * его отдаёт. Интерфейс различает «рейла нет» и «свойство не пришло»,
     * и второе означало бы ошибку доставки, а не отсутствие пунктов.
     *
     * `profileUrl` у гостя приходит пустым — страницы профиля у смотрящего
     * нет.
     *
     * @return array{
     *     profile: array<string, mixed>|null,
     *     crossService: array{items: list<array<string, mixed>>, profileUrl: string, logoUrl: string},
     *     locale: string
     * }
     */
    public function share(): array
    {
        $sub = $this->session->sub();
        $locale = $this->locale();

        if ($sub === null) {
            /*
             * Отказ установки страницу не роняет: пустой рейл — законное
             * состояние (критерий 91), и клиент навигации отдаёт его сам.
             */
            $navigation = $this->navigation->forGuest();

            Log::debug('[IdentityProps.share] guest props shared', [
                'items' => count($navigation->items),
                'locale' => $locale,
            ]);

            return [
                'profile' => null,
                'crossService' => $this->crossService($navigation),
                'locale' => $locale,
            ];
        }

        $navigation = $this->navigation->for($sub);
        $profile = $this->profiles->currentDecorated();

        Log::debug('[IdentityProps.share] identity props shared', [
            'sub' => $sub,
            'items' => count($navigation->items),
            'profile' => $profile !== null,
            'locale' => $locale,
        ]);

        return [
            'profile' => $profile,
            'crossService' => $this->crossService($navigation),
            'locale' => $locale,
        ];
    }

    /**
     * Язык, на котором продукт отрисовывает страницу.
     *
     * **Язык рейла у гостя знает продукт, а не установка.** У вошедшего его
     * берут из профиля; у гостя профиля нет, и без этого поля компонент рейла
     * откатывается на жёсткое умолчание — на англоязычной установке гостевой
     * рейл вышел бы русским. Диктовать язык установка не вправе: о госте она
     * не знает ничего, а продукт знает заголовок `Accept-Language`, куку
     * и собственное умолчание.
     */
    private function locale(): string
    {
        return App::getLocale();
    }

    /**
     * @return array{items: list<array<string, mixed>>, profileUrl: string, logoUrl: string}
     */
    private function crossService(NavigationData $navigation): array
    {
        return [
            'items' => array_map(
                static fn (NavItem $item): array => $item->toArray(),
                $navigation->items,
            ),
            'profileUrl' => $navigation->profileUrl,
            'logoUrl' => $navigation->logoUrl,
        ];
    }
}
