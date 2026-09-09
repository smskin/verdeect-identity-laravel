<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Support;

use Illuminate\Support\Facades\Log;
use Verdeect\IdentityIntegration\Api\NavigationClient;
use Verdeect\IdentityIntegration\Session\IdentitySession;

/**
 * Origin'ы хранилища иконок для политики содержимого страницы.
 *
 * Пара к `IdentityOrigin`, и существует ровно по тому же доводу: `img-src`
 * обязан включать источник, откуда приходят изображения, иначе на их месте
 * окажется пустота, а причина будет видна только в консоли обозревателя.
 *
 * **Почему отдельный класс, а не origin установки.** Логотип раздаёт сама
 * установка, а иконки пунктов лежат в её объектном хранилище, и адрес
 * у него свой: в разработке это разные порты одного узла, в эксплуатации —
 * разные узлы, а то и чужая служба (S3, MinIO за отдельным именем).
 *
 * **Почему адрес не берётся из настройки продукта.** Взятый оттуда, он
 * задваивает знание: установка переносит хранилище — и все продукты
 * показывают заглушки вместо иконок, молча, до тех пор пока кто-нибудь
 * не заглянет в консоль обозревателя. Адрес приходит в самих ссылках,
 * и единственный источник истины — они.
 *
 * **Обращения к установке этот класс не делает.** Он читает уже разобранные
 * данные рейла; к моменту, когда продукт составляет заголовок, они получены
 * — свойства интерфейса собираются раньше, а их сборка кладёт ответ в кэш
 * (`IdentityProps::share()`). На запросе без рейла список пуст, и это верно:
 * изображений оттуда на такой странице нет.
 *
 * **Политику пакет не составляет** — набор директив принадлежит продукту.
 * Пакет отвечает за единственное: правильно вычислить origin'ы.
 */
final class IconOrigins
{
    public function __construct(
        private readonly IdentitySession $session,
        private readonly NavigationClient $navigation,
    ) {}

    /**
     * Origin'ы иконок текущего пользователя, без повторов.
     *
     * Список, а не одна строка: ведро у пунктов общее сегодня, но контракт
     * этого не обещает, а политика содержимого перечисление допускает —
     * и обходится оно дешевле, чем предположение, которое однажды перестанет
     * быть верным.
     *
     * @return list<string>
     */
    public function forSession(): array
    {
        $sub = $this->session->sub();

        if ($sub === null) {
            return [];
        }

        $origins = [];

        foreach ($this->navigation->for($sub)->items as $item) {
            $origin = $this->originOf($item->iconUrl);

            if ($origin !== '' && ! in_array($origin, $origins, true)) {
                $origins[] = $origin;
            }
        }

        Log::debug('[IconOrigins.forSession] origins resolved', [
            'sub' => $sub,
            'origins' => $origins,
        ]);

        return $origins;
    }

    /**
     * Схема, узел и порт ссылки без пути и подписи.
     *
     * Пустая строка при пустой либо неразбираемой ссылке — не отказ: пустая
     * ссылка законна и означает «ссылки не пришло», а рейл показывает такой
     * пункт первой буквой имени. Разрешать в политике нечего.
     */
    private function originOf(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            Log::warning('[IconOrigins.originOf] icon url is not parsable', [
                // Сама ссылка не пишется: она даёт доступ к объекту.
                'scheme' => $parts['scheme'] ?? null,
            ]);

            return '';
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
