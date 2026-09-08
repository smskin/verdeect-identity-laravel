<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Discovery;

use Verdeect\IdentityIntegration\Exceptions\DiscoveryException;

interface IDiscoveryClient
{
    /**
     * Документ обнаружения установки.
     *
     * @throws DiscoveryException
     */
    public function fetch(): DiscoveryDocument;
}
