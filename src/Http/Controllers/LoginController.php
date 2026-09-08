<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Verdeect\IdentityIntegration\Flow\AuthorizationFlow;
use Verdeect\IdentityIntegration\Http\ExternalRedirect;

/**
 * Начало входа.
 *
 * Собственной формы входа в продукте нет и быть не может: пароль знает
 * только установка, а поток с передачей пароля у неё выключен (справка 13).
 */
final readonly class LoginController
{
    public function __construct(private AuthorizationFlow $flow) {}

    public function __invoke(Request $request): Response
    {
        $intended = $request->session()->pull('identity.intended_url');

        return ExternalRedirect::to(
            $request,
            $this->flow->start(is_string($intended) ? $intended : null),
        );
    }
}
