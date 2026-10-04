<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\EntryPoint\ReAuthenticationEntryPointInterface;

final class ConfirmPasswordEntryPoint implements ReAuthenticationEntryPointInterface
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function startReAuthentication(Request $request, TokenInterface $token): Response
    {
        return new RedirectResponse($this->urlGenerator->generate('app_confirm_password'));
    }
}
