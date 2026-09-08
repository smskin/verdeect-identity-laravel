<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Exceptions;

use RuntimeException;

/**
 * Базовый отказ интеграции с identity.
 *
 * Существует, чтобы продукт мог отличить отказ интеграции от любого другого
 * одним `catch`, не перечисляя частных случаев.
 */
abstract class IdentityException extends RuntimeException {}
