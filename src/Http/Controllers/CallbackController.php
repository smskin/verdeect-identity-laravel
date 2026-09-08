<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Verdeect\IdentityIntegration\Discovery\IdTokenVerifier;
use Verdeect\IdentityIntegration\Exceptions\IdTokenException;
use Verdeect\IdentityIntegration\Flow\AuthorizationFlow;
use Verdeect\IdentityIntegration\Session\IdentitySession;
use Verdeect\IdentityIntegration\Tokens\AccessTokenClaims;
use Verdeect\IdentityIntegration\Tokens\TokenExchanger;
use Verdeect\IdentityIntegration\Tokens\TokenStore;

/**
 * Возврат из потока кода авторизации.
 *
 * Здесь выполняются четыре проверки PRD 12.3 — `state`, подпись, `iss`/`aud`,
 * `nonce` — и закладывается хранение токенов ключом по `sid`.
 *
 * Токен доступа в браузер не попадает ни в каком виде (критерий 67).
 */
final readonly class CallbackController
{
    public function __construct(
        private AuthorizationFlow $flow,
        private TokenExchanger $exchanger,
        private IdTokenVerifier $verifier,
        private TokenStore $store,
        private IdentitySession $session,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $pending = $this->flow->pending();

        if ($pending === null) {
            Log::warning('[CallbackController] flow missing');

            throw new HttpException(400, 'Поток входа не начат либо истёк. Повторите вход.');
        }

        /*
         * Отказ установки приходит параметрами `error` и `state`. Сессию
         * при этом менять нельзя: пользователь остаётся тем же, кем был.
         */
        if ($request->has('error')) {
            $this->flow->forget();

            Log::warning('[CallbackController] authorization rejected', [
                'error' => $request->string('error')->toString(),
            ]);

            throw new HttpException(400, 'Установка отклонила вход: '.$request->string('error')->toString());
        }

        if (! hash_equals($pending['state'], $request->string('state')->toString())) {
            $this->flow->forget();

            Log::warning('[CallbackController] state mismatch');

            throw new HttpException(400, 'Значение state не совпадает. Вход прерван.');
        }

        $code = $request->string('code')->toString();

        if ($code === '') {
            $this->flow->forget();

            throw new HttpException(400, 'Установка не вернула код авторизации.');
        }

        $set = $this->exchanger->exchangeCode($code, $pending['verifier']);

        if ($set->idToken === null) {
            $this->flow->forget();

            throw new HttpException(400, 'Установка не вернула ID-токен.');
        }

        try {
            $this->verifier->verify($set->idToken, $pending['nonce']);
        } catch (IdTokenException $exception) {
            $this->flow->forget();

            if ($exception->reason === IdTokenException::REASON_NONCE) {
                Log::warning('[CallbackController] nonce mismatch');
            }

            throw new HttpException(400, 'Проверка ID-токена не пройдена: '.$exception->reason);
        }

        /*
         * `sub` и `sid` берутся из токена доступа: он выпущен для identity
         * как ресурса, поэтому разбирается **без проверки** подписи и `aud`
         * (PRD 12.2).
         */
        $claims = AccessTokenClaims::parse($set->accessToken);

        if ($claims->sid === '' || $claims->sub === '') {
            $this->flow->forget();

            throw new HttpException(400, 'В токене доступа нет sub или sid.');
        }

        /*
         * Смена идентификатора сессии — **до** записи признаков входа,
         * иначе идентификатор остался бы прежним (фиксация сессии).
         */
        $request->session()->regenerate();

        $this->store->put($claims->sid, $set);
        $this->session->establish($claims->sid, $claims->sub);

        $this->flow->forget();

        Log::debug('[CallbackController] session established', [
            'sub' => $claims->sub,
            'sid' => $claims->sid,
        ]);

        return redirect()->to($pending['intended_url'] ?? '/');
    }
}
