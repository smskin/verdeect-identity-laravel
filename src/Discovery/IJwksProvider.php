<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Discovery;

use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Verdeect\IdentityIntegration\Exceptions\UnknownKeyException;

interface IJwksProvider
{
    public function keySet(): JWKSet;

    /**
     * Ключ по идентификатору из заголовка токена.
     *
     * Неизвестный `kid` вызывает **одно** перечитывание набора: ротация
     * ключей установки — штатное событие, и отказывать на нём нельзя
     * (критерий 71).
     *
     * @throws UnknownKeyException
     */
    public function keyFor(string $kid): JWK;

    public function refresh(): JWKSet;
}
