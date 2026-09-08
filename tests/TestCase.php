<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Verdeect\IdentityIntegration\IdentityServiceProvider;

/**
 * Основа прогона пакета.
 *
 * Приложение поднимает testbench: собственного приложения у пакета нет,
 * а проверять поток входа, обмен токенов и потребление сообщений без
 * контейнера Laravel нечем.
 *
 * База данных не подключается: пакет в неё не пишет — `sub` хранит продукт.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Провайдер пакета регистрируется вручную: пакет ставится потребителем
     * через обнаружение composer, а внутри собственного репозитория этого
     * механизма нет.
     *
     * Сигнатура повторяет исходную из testbench **без типа возврата**:
     * объявленный здесь тип разошёлся бы с родительской и уронил прогон.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app)
    {
        return [IdentityServiceProvider::class];
    }
}
