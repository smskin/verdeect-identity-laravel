<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Единственная точка построения HTTP-запросов к установке.
 *
 * Здесь же живёт **подмена узла при соединении** — то же, что `--resolve`
 * у curl: адрес запроса остаётся ровно таким, каким его отдал документ
 * обнаружения, а меняется только то, куда открывается соединение.
 *
 * Зачем она нужна. Установка разработчика объявляет себя по адресу
 * `http://localhost:81`, и все её эндпоинты в документе обнаружения
 * абсолютные. Внутри контейнера `localhost` — сам контейнер, и обращение
 * к `/token` уходило бы в никуда. Переписывать узел в адресе нельзя:
 * PRD 12.2 требует брать эндпоинты из документа обнаружения как есть,
 * а `iss` ID-токена сверяется с `issuer` того же документа — подмена
 * рассогласовала бы проверку.
 *
 * В эксплуатации перечень пуст: там имя установки разрешается обычным DNS.
 */
final class IdentityHttp
{
    public function __construct(private readonly IdentityConfig $config) {}

    public function request(): PendingRequest
    {
        $request = Http::timeout($this->config->httpTimeout());

        $resolve = $this->resolveEntries();

        if ($resolve === []) {
            return $request;
        }

        return $request->withOptions(['curl' => [CURLOPT_RESOLVE => $resolve]]);
    }

    /**
     * Записи вида `узел:порт:адрес` для CURLOPT_RESOLVE.
     *
     * Цель записи может быть именем: имена контейнерной среды
     * (`host.docker.internal`) разрешаются здесь, потому что curl ждёт
     * адрес, а не имя.
     *
     * @return list<string>
     */
    private function resolveEntries(): array
    {
        $entries = [];

        foreach ($this->config->httpResolve() as $entry) {
            $parts = explode(':', $entry);

            if (count($parts) !== 3) {
                continue;
            }

            [$host, $port, $target] = $parts;

            $address = filter_var($target, FILTER_VALIDATE_IP) !== false
                ? $target
                : gethostbyname($target);

            if ($address === $target && filter_var($address, FILTER_VALIDATE_IP) === false) {
                // Имя не разрешилось: запись бесполезна, но отказывать
                // из-за неё нельзя — обычный DNS может справиться сам.
                continue;
            }

            $entries[] = $host.':'.$port.':'.$address;
        }

        return $entries;
    }
}
