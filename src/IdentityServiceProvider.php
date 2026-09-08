<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Verdeect\IdentityIntegration\Api\IUserResolver;
use Verdeect\IdentityIntegration\Api\NavigationClient;
use Verdeect\IdentityIntegration\Api\ServiceApiClient;
use Verdeect\IdentityIntegration\Api\ServiceTokenProvider;
use Verdeect\IdentityIntegration\Api\UserInfoClient;
use Verdeect\IdentityIntegration\Api\UserResolver;
use Verdeect\IdentityIntegration\Discovery\DiscoveryClient;
use Verdeect\IdentityIntegration\Discovery\IDiscoveryClient;
use Verdeect\IdentityIntegration\Discovery\IdTokenVerifier;
use Verdeect\IdentityIntegration\Discovery\IJwksProvider;
use Verdeect\IdentityIntegration\Discovery\JwksProvider;
use Verdeect\IdentityIntegration\Events\ConsumeIdentityEventsCommand;
use Verdeect\IdentityIntegration\Events\IIdentityEventHandler;
use Verdeect\IdentityIntegration\Events\NullIdentityEventHandler;
use Verdeect\IdentityIntegration\Events\SessionMarks;
use Verdeect\IdentityIntegration\Inertia\IdentityProps;
use Verdeect\IdentityIntegration\Profile\IProfileDecorator;
use Verdeect\IdentityIntegration\Profile\NullProfileDecorator;
use Verdeect\IdentityIntegration\Profile\ProfileProvider;
use Verdeect\IdentityIntegration\Support\IdentityCache;
use Verdeect\IdentityIntegration\Support\IdentityOrigin;
use Verdeect\IdentityIntegration\Tokens\TokenExchanger;
use Verdeect\IdentityIntegration\Tokens\TokenManager;
use Verdeect\IdentityIntegration\Tokens\TokenStore;

/**
 * Провайдер пакета интеграции с identity.
 *
 * Пакет — отдельная единица Composer со своим namespace: граница PRD 12.1
 * держится структурой, а не договорённостью. Ни один класс пакета
 * не ссылается на `App\`.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/identity.php', 'identity');

        $this->app->singleton(IdentityCache::class);

        $this->app->singleton(IDiscoveryClient::class, DiscoveryClient::class);
        $this->app->singleton(IJwksProvider::class, JwksProvider::class);
        $this->app->singleton(IdTokenVerifier::class);

        $this->app->singleton(TokenStore::class);
        $this->app->singleton(TokenExchanger::class);
        $this->app->singleton(TokenManager::class);

        $this->app->singleton(ServiceTokenProvider::class);
        $this->app->singleton(ServiceApiClient::class);
        $this->app->singleton(IUserResolver::class, UserResolver::class);
        $this->app->singleton(NavigationClient::class);
        $this->app->singleton(UserInfoClient::class);
        $this->app->singleton(ProfileProvider::class);

        $this->app->singleton(SessionMarks::class);

        /*
         * Сборка разделяемых свойств и вычисление origin — то, что продукт
         * вызывает из своего middleware. Пакет их предоставляет, но ни
         * состава свойств страницы, ни политики содержимого за продукт
         * не решает.
         */
        $this->app->singleton(IdentityProps::class);
        $this->app->singleton(IdentityOrigin::class);

        /*
         * Точки расширения объявлены пустыми реализациями: сервис уборки
         * своих не заводит — ролей у него нет (PRD 12.1), — но продукт
         * установки, которому они понадобятся, подменяет привязку у себя
         * и пакет при этом не правит.
         */
        $this->app->bind(IIdentityEventHandler::class, NullIdentityEventHandler::class);
        $this->app->bind(IProfileDecorator::class, NullProfileDecorator::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/identity.php');

        $this->publishes([
            __DIR__.'/../config/identity.php' => config_path('identity.php'),
        ], 'identity-config');

        /*
         * Компоненты кросс-сервисной навигации этот пакет не публикует.
         *
         * Они живут в npm-пакете `@verdeect/identity-integration-vue`
         * и приходят продукту зависимостью, а не копированием в `resources/`:
         * опубликованная копия расходилась бы с эталоном на первой же правке.
         */

        if ($this->app->runningInConsole()) {
            $this->commands([ConsumeIdentityEventsCommand::class]);
        }

        /*
         * Адрес установки секретом не является и в журнал попадает;
         * секреты клиентов не журналируются никогда.
         *
         * Отсутствие адреса здесь **не приводит к отказу**: иначе
         * `php artisan config:cache` валился бы на незаполненном окружении.
         * Отказ поднимается при первом обращении.
         */
        Log::debug('[IdentityServiceProvider.boot] package booted', [
            'base_url' => config('identity.base_url'),
        ]);
    }
}
