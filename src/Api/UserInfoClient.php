<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Api;

use Illuminate\Support\Facades\Log;
use Throwable;
use Verdeect\IdentityIntegration\Discovery\IDiscoveryClient;
use Verdeect\IdentityIntegration\Support\IdentityHttp;

/**
 * Сведения о владельце токена.
 *
 * Адрес берётся из документа обнаружения; токен — пользовательский,
 * с областью `openid` (справка 4.3).
 */
final class UserInfoClient
{
    public function __construct(
        private readonly IDiscoveryClient $discovery,
        private readonly IdentityHttp $http,
    ) {}

    /**
     * @return array<string, mixed>|null null — установка недоступна
     */
    public function fetch(string $accessToken): array|null
    {
        $endpoint = $this->discovery->fetch()->userinfoEndpoint;

        try {
            $response = $this->http->request()
                ->withToken($accessToken)
                ->acceptJson()
                ->get($endpoint);
        } catch (Throwable $exception) {
            Log::warning('[UserInfoClient.fetch] userinfo unreachable', [
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('[UserInfoClient.fetch] userinfo rejected', [
                'status' => $response->status(),
            ]);

            return null;
        }

        /** @var array<string, mixed> */
        return $response->json() ?? [];
    }
}
