<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AccountController
{
    #[Route('/account/email', name: 'account_email', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED_RECENTLY')]
    public function changeEmail(): Response
    {
        return new Response("changed the email address\n");
    }

    #[Route('/account/delete', name: 'account_delete', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_VERY_RECENTLY')]
    public function deleteAccount(): Response
    {
        return new Response("deleted the account\n");
    }

    /**
     * A safe method, so this is the case where the re-authentication sends the user
     * back to the page they were denied. /account/delete above is the other case.
     */
    #[Route('/account/api-keys', name: 'account_api_keys', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_VERY_RECENTLY')]
    public function listApiKeys(): Response
    {
        return new Response("list of API keys\n");
    }

    /**
     * Guarded by two attributes in one security.yaml rule, on purpose: this is the
     * endpoint that shows a multi-attribute denial never offering a step-up.
     */
    #[Route('/admin/api-key', name: 'admin_api_key', methods: ['POST'])]
    public function rotateApiKey(): Response
    {
        return new Response("rotated the API key\n");
    }

    /**
     * A freshness check and a role check as two attributes. Whether this offers a
     * re-authentication or a bare 403 is the question the demo answers.
     */
    #[Route('/admin/billing', name: 'admin_billing', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_RECENTLY')]
    #[IsGranted('ROLE_ADMIN')]
    public function changeBilling(): Response
    {
        return new Response("changed the billing details\n");
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(): Response
    {
        return new Response("login form\n");
    }

    #[Route('/confirm-password', name: 'app_confirm_password', methods: ['GET'])]
    public function confirmPassword(): Response
    {
        return new Response("confirm your password\n");
    }
}
