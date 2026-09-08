<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Discovery;

use Illuminate\Support\Facades\Log;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Throwable;
use Verdeect\IdentityIntegration\Exceptions\IdTokenException;
use Verdeect\IdentityIntegration\Support\IdentityConfig;

/**
 * Проверка ID-токена.
 *
 * Пять проверок: подпись, издатель, получатель, срок, `nonce`. Каждая даёт
 * свой код причины — по нему различаются критерии приёмки 69 и 70 и ветвится
 * журналирование.
 *
 * Издатель сверяется **со значением документа обнаружения**, а не с литералом:
 * установка разработчика работает по HTTP (`http://localhost:81`), тестовая —
 * по HTTPS, и зашитая строка сделала бы вход невозможным на одной из них
 * (index.md, «Решение 9б»).
 */
final class IdTokenVerifier
{
    private const CLOCK_SKEW_SECONDS = 60;

    public function __construct(
        private readonly IJwksProvider $jwks,
        private readonly IDiscoveryClient $discovery,
        private readonly IdentityConfig $config,
    ) {}

    /**
     * @return array<string, mixed> утверждения токена
     */
    public function verify(string $idToken, string $expectedNonce): array
    {
        $serializer = new CompactSerializer;

        try {
            $jws = $serializer->unserialize($idToken);
        } catch (Throwable $exception) {
            throw IdTokenException::because(
                IdTokenException::REASON_MALFORMED,
                'ID-токен нечитаем: '.$exception->getMessage(),
            );
        }

        $header = $jws->getSignature(0)->getProtectedHeader();

        if (($header['alg'] ?? null) !== 'RS256') {
            throw IdTokenException::because(
                IdTokenException::REASON_SIGNATURE,
                'Алгоритм подписи ID-токена не RS256',
            );
        }

        $kid = $header['kid'] ?? null;

        if (! is_string($kid) || $kid === '') {
            throw IdTokenException::because(
                IdTokenException::REASON_SIGNATURE,
                'В заголовке ID-токена нет идентификатора ключа',
            );
        }

        $key = $this->jwks->keyFor($kid);
        $verifier = new JWSVerifier(new AlgorithmManager([new RS256]));

        if (! $verifier->verifyWithKey($jws, $key, 0)) {
            Log::error('[IdTokenVerifier.verify] signature invalid', ['kid' => $kid]);

            throw IdTokenException::because(
                IdTokenException::REASON_SIGNATURE,
                'Подпись ID-токена неверна',
            );
        }

        $payload = $jws->getPayload();

        /** @var array<string, mixed> $claims */
        $claims = is_string($payload) ? (json_decode($payload, true) ?? []) : [];

        $this->assertIssuer($claims);
        $this->assertAudience($claims);
        $this->assertNotExpired($claims);
        $this->assertNonce($claims, $expectedNonce);

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertIssuer(array $claims): void
    {
        $expected = $this->discovery->fetch()->issuer;

        if (($claims['iss'] ?? null) !== $expected) {
            throw IdTokenException::because(
                IdTokenException::REASON_ISSUER,
                'Издатель ID-токена не совпадает с издателем установки',
            );
        }
    }

    /**
     * Получатель ID-токена — `client_id` (справка 5.2).
     *
     * Это отличает его от токена доступа, где `aud` — массив идентификаторов
     * ресурсов и проверяется вхождение. Здесь допускается обе формы: некоторые
     * серверы выдают `aud` массивом и при одном получателе.
     *
     * @param  array<string, mixed>  $claims
     */
    private function assertAudience(array $claims): void
    {
        $expected = $this->config->webClientId();
        $audience = $claims['aud'] ?? null;

        $matches = is_array($audience)
            ? in_array($expected, $audience, true)
            : $audience === $expected;

        if (! $matches) {
            throw IdTokenException::because(
                IdTokenException::REASON_AUDIENCE,
                'ID-токен выдан другому клиенту',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertNotExpired(array $claims): void
    {
        $now = time();

        /*
         * `exp` и `iat` — значения типа NumericDate: RFC 7519 допускает
         * дробную часть, и установка её действительно отдаёт
         * (`exp: 1788559475.622715`). Проверка на целое отвергала бы
         * совершенно исправный токен, и отказ выглядел бы как истёкший срок.
         */
        $exp = $this->numericDate($claims['exp'] ?? null);
        $iat = $this->numericDate($claims['iat'] ?? null);

        if ($exp === null || $exp + self::CLOCK_SKEW_SECONDS < $now) {
            /*
             * Значения пишутся в журнал: расхождение часов установки
             * и продукта выглядит как «токен просрочен» и без них
             * не отличается от настоящего истечения срока.
             */
            Log::warning('[IdTokenVerifier.verify] id token expired', [
                'exp' => $exp,
                'iat' => $iat,
                'now' => $now,
            ]);

            throw IdTokenException::because(
                IdTokenException::REASON_EXPIRED,
                'Срок действия ID-токена истёк',
            );
        }

        if ($iat !== null && $iat - self::CLOCK_SKEW_SECONDS > $now) {
            Log::warning('[IdTokenVerifier.verify] id token issued in the future', [
                'iat' => $iat,
                'now' => $now,
            ]);

            throw IdTokenException::because(
                IdTokenException::REASON_EXPIRED,
                'ID-токен выдан будущим временем',
            );
        }
    }

    /**
     * Значение типа NumericDate в секундах.
     *
     * Дробная часть отбрасывается: сравнение идёт с точностью до секунды,
     * и допуск на расхождение часов много её больше.
     */
    private function numericDate(mixed $value): int|null
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertNonce(array $claims, string $expectedNonce): void
    {
        if (($claims['nonce'] ?? null) !== $expectedNonce) {
            throw IdTokenException::because(
                IdTokenException::REASON_NONCE,
                'Значение nonce ID-токена не совпадает с отправленным',
            );
        }
    }
}
