<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Profile;

use Illuminate\Support\Facades\Log;
use Throwable;
use Verdeect\IdentityIntegration\Api\UserInfoClient;
use Verdeect\IdentityIntegration\Session\IdentitySession;
use Verdeect\IdentityIntegration\Support\IdentityCache;
use Verdeect\IdentityIntegration\Tokens\TokenManager;

/**
 * Профиль текущего пользователя с кэшем.
 *
 * Кэш сбрасывается сообщением `user.profile.changed` (задача 18), а не
 * по сроку: срок здесь — страховка, а не механизм актуальности.
 */
final class ProfileProvider
{
    public function __construct(
        private readonly UserInfoClient $userInfo,
        private readonly TokenManager $tokens,
        private readonly IdentitySession $session,
        private readonly IdentityCache $cache,
        private readonly IProfileDecorator $decorator,
    ) {}

    /**
     * Профиль вошедшего либо `null`, если сессии нет.
     */
    public function current(): UserProfile|null
    {
        $sub = $this->session->sub();
        $sid = $this->session->sid();

        if ($sub === null || $sid === null) {
            return null;
        }

        $cached = $this->cache->get($this->key($sub));

        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            Log::debug('[ProfileProvider.current] profile resolved', [
                'sub' => $sub,
                'cached' => true,
            ]);

            return UserProfile::fromUserInfo($cached);
        }

        try {
            $claims = $this->userInfo->fetch($this->tokens->accessTokenFor($sid));
        } catch (Throwable $exception) {
            Log::warning('[ProfileProvider.current] profile unavailable', [
                'sub' => $sub,
                'reason' => $exception->getMessage(),
            ]);

            return UserProfile::unresolved($sub);
        }

        if ($claims === null) {
            // Установка недоступна, кэша нет: страница обязана отрисоваться
            // (справка 9, пункт 8).
            return UserProfile::unresolved($sub);
        }

        $this->cache->put(
            $this->key($sub),
            $claims,
            (int) config('identity.cache.profile_ttl', 300),
        );

        Log::debug('[ProfileProvider.current] profile resolved', [
            'sub' => $sub,
            'cached' => false,
        ]);

        return UserProfile::fromUserInfo($claims);
    }

    /**
     * Профиль с полями продукта.
     *
     * @return array<string, mixed>|null
     */
    public function currentDecorated(): array|null
    {
        $profile = $this->current();

        return $profile === null ? null : $this->decorator->decorate($profile->toArray());
    }

    public function forget(string $sub): void
    {
        $this->cache->forget($this->key($sub));
    }

    private function key(string $sub): string
    {
        return 'profile:'.$sub;
    }
}
