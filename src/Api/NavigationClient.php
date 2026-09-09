<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Api;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;
use Verdeect\IdentityIntegration\Events\SessionMarks;
use Verdeect\IdentityIntegration\Exceptions\ServiceApiException;
use Verdeect\IdentityIntegration\Support\IdentityCache;
use Verdeect\IdentityIntegration\Support\IdentityConfig;

/**
 * Данные рейла кросс-сервисной навигации.
 *
 * **Операций две, адрес один.** Пользовательская идёт `POST` с телом `{sub}`,
 * гостевая — `GET` без тела: у гостя адресата нет, и подать в теле нечего.
 * Различие целиком в отправке; кэш, признак устаревания и деградация общие.
 *
 * Кэш живёт **на бэкенде**, а не в браузере (критерий 89), и сбрасывается
 * двумя сообщениями — `navigation.changed` и `user.rights.changed`, — а не
 * по сроку: смена роли обязана доходить до рейла без нового входа
 * (справка 9.1).
 *
 * **В кэше лежат уже подписанные ссылки на иконки пунктов**
 * (`NavItem::$iconUrl`), и это накладывает на `identity.cache.navigation_ttl`
 * и `identity.cache.navigation_guest_ttl` верхнюю границу: срок кэша обязан
 * быть заметно меньше срока подписи, который задаёт установка. Проверить
 * соотношение в одиночку продукт не в состоянии — срок подписи знает только
 * установка, — поэтому она держит его заметно выше поставляемого с пакетом
 * часа и предупреждает при сближении. Подняв свой срок, продукт обязан
 * убедиться, что запас сохранился: ссылка, протухшая раньше кэша, даёт битые
 * иконки у всех его пользователей до следующего промаха. **У гостевого срока
 * это правило строже:** запись одна на установку, и протухшая подпись бьёт
 * по каждой странице без сессии сразу.
 */
final class NavigationClient
{
    private const PATH = '/api/navigation';

    /**
     * Ключ гостевой записи.
     *
     * Запись **единственная на установку**: гостю отдаётся один и тот же
     * набор, различать некого. Отдельный сегмент `guest` вместо места `sub`
     * держит гостевые и пользовательские ключи непересекающимися
     * по построению, а не по предположению о форме `sub`.
     */
    private const GUEST_KEY = 'navigation:v3:guest';

    public function __construct(
        private readonly ServiceApiClient $api,
        private readonly IdentityCache $cache,
        private readonly SessionMarks $marks,
        private readonly IdentityConfig $config,
    ) {}

    public function for(string $sub): NavigationData
    {
        return $this->resolve(
            key: $this->key($sub),
            ttl: $this->config->navigationTtl(),
            method: 'for',
            subject: 'rail',
            context: ['sub' => $sub],
            /*
             * `404 user_not_found` здесь — **отказ**, в отличие от разрешения
             * имён: адресат один, и его отсутствие означает, что спросили
             * не то (справка 7.3). Асимметрия реальна, и обе операции нельзя
             * обрабатывать одним кодом.
             */
            fetch: fn (): array => $this->api->post(self::PATH, ['sub' => $sub], 'navigation.user'),
        );
    }

    /**
     * Рейл неавторизованного посетителя.
     *
     * Кода `404 user_not_found` у этой операции нет вовсе: адресата нет,
     * и спросить «не то» здесь не о ком.
     */
    public function forGuest(): NavigationData
    {
        return $this->resolve(
            key: self::GUEST_KEY,
            ttl: $this->config->navigationGuestTtl(),
            method: 'forGuest',
            subject: 'guest rail',
            context: [],
            fetch: fn (): array => $this->api->get(self::PATH, 'navigation.guest'),
        );
    }

    public function forget(string $sub): void
    {
        $this->cache->forget($this->key($sub));
    }

    /**
     * Общий ход обеих операций: чтение кэша, обращение, запись, деградация.
     *
     * Вынесено целиком, чтобы правило жило один раз: разойдясь, гостевая
     * и пользовательская ветви начали бы по-разному переживать недоступность
     * установки, и расхождение обнаружилось бы только в тот день, когда она
     * недоступна.
     *
     * `$method` и `$subject` участвуют только в журнале: приставка обязана
     * называть вызванный метод, а не общий закрытый путь. У гостевой ветви
     * `$context` пуст — поля `sub` в её записях нет, потому что нет адресата.
     *
     * @param  array<string, mixed>  $context
     * @param  Closure(): array<string, mixed>  $fetch
     */
    private function resolve(
        string $key,
        int $ttl,
        string $method,
        string $subject,
        array $context,
        Closure $fetch,
    ): NavigationData {
        $cached = $this->cache->get($key);

        if (is_array($cached) && ! $this->isStale($cached)) {
            /** @var array<string, mixed> $cached */
            return NavigationData::fromArray($cached);
        }

        try {
            $payload = $fetch();
        } catch (ServiceApiException $exception) {
            Log::warning('[NavigationClient.'.$method.'] '.$subject.' unavailable', [
                ...$context,
                'error' => $exception->error,
            ]);

            return $this->staleOrEmpty($cached);
        } catch (Throwable $exception) {
            Log::warning('[NavigationClient.'.$method.'] '.$subject.' unreachable', [
                ...$context,
                'reason' => $exception->getMessage(),
            ]);

            return $this->staleOrEmpty($cached);
        }

        $data = NavigationData::fromResponse($payload);

        $this->cache->put($key, [
            ...$data->toArray(),
            'cached_at' => CarbonImmutable::now()->toIso8601String(),
        ], $ttl);

        Log::debug('[NavigationClient.'.$method.'] '.$subject.' resolved', [
            ...$context,
            'items' => count($data->items),
        ]);

        return $data;
    }

    /**
     * Запись кэша старше отметки `navigation.changed` считается устаревшей.
     *
     * Отметка — время, а не снимаемый признак: снимаемый забрало бы первое
     * же устройство пользователя (справка 8.1, критерий 75).
     *
     * **Отметка общая, и гостевая запись обесценивается ею же.** Отдельного
     * способа сброса гостевого ключа заводить не нужно: `navigation.changed` —
     * единственный тип сообщения без `sub` и `sid`, состав меняется для всех
     * сразу, и второй механизм сброса того же ключа был бы лишней сущностью.
     *
     * @param  array<string, mixed>  $cached
     */
    private function isStale(array $cached): bool
    {
        $mark = $this->marks->productMark();

        if ($mark === null) {
            return false;
        }

        $cachedAt = $cached['cached_at'] ?? null;

        if (! is_string($cachedAt)) {
            return true;
        }

        return CarbonImmutable::parse($cachedAt)->lessThan($mark);
    }

    private function staleOrEmpty(mixed $cached): NavigationData
    {
        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return NavigationData::fromArray($cached);
        }

        // Пустой `items` — допустимый ответ: рейл не рисуется вовсе,
        // включая логотип (критерий 91).
        return NavigationData::empty();
    }

    /**
     * Ключ записи кэша вошедшего.
     *
     * **Отметка состава (`v3`) — не украшение, а защита от молчаливой
     * деградации при обновлении пакета.** Запись кэша хранит уже разобранный
     * состав, а не сырой ответ, и всякая правка `NavItem::toArray()`
     * либо `NavigationData::toArray()` этот состав меняет. Разборщик новой
     * версии, прочитав запись, положенную прежней, не находит поля
     * и подставляет законные пустые значения — а рейл показывает заглушки
     * у всех пользователей продукта до истечения срока кэша.
     *
     * Отказа при этом нет ни в журнале, ни на экране: заглушки выглядят
     * намеренными. Обнаружено на продукте установки 09.09.2026, после
     * выкатки 0.2, когда `icon` стал `icon_url` при отметке `v1`.
     *
     * Отметка снимает вопрос без обязанности продукта помнить про сброс кэша
     * при обновлении: записи прежнего состава просто не читаются и уходят
     * сами по своему сроку. В 0.4 она поднята с `v2` до `v3`: из состава
     * ушёл `sub`.
     */
    private function key(string $sub): string
    {
        return 'navigation:v3:user:'.$sub;
    }
}
