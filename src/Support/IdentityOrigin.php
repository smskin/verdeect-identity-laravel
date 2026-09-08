<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Support;

use Illuminate\Support\Facades\Log;

/**
 * Origin установки для политики содержимого страницы.
 *
 * Существует ради одной строки в политике продукта: `img-src` обязан
 * включать origin установки, иначе на месте логотипа окажется пустота,
 * а причина будет видна только в консоли обозревателя. Тот же origin нужен
 * `form-action`: вход уходит на установку.
 *
 * **Политику пакет не составляет.** Набор директив принадлежит продукту —
 * у него свои источники скриптов, стилей и шрифтов. Пакет отвечает
 * за единственное: правильно вычислить origin.
 */
final class IdentityOrigin
{
    /**
     * Схема, узел и порт адреса установки без пути.
     *
     * Пустая строка при незаполненном либо неразбираемом адресе — **не
     * отказ**: политика содержимого обязана строиться и на неподнятом
     * окружении, иначе сборка конфигурации валилась бы на пустом `.env`.
     * Отказ по отсутствию адреса поднимается при первом обращении
     * к установке, а не здесь.
     */
    public function resolve(): string
    {
        $baseUrl = config('identity.base_url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            Log::debug('[IdentityOrigin.resolve] installation address is not set', [
                'origin' => '',
            ]);

            return '';
        }

        $parts = parse_url($baseUrl);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            /*
             * Адрес задан, но не разбирается — это ошибка настройки, и молчать
             * о ней нельзя: логотип установки просто не отрисуется, а причина
             * останется в консоли обозревателя.
             */
            Log::warning('[IdentityOrigin.resolve] installation address is not parsable', [
                'base_url' => $baseUrl,
            ]);

            return '';
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        Log::debug('[IdentityOrigin.resolve] origin resolved', [
            'origin' => $origin,
        ]);

        return $origin;
    }
}
