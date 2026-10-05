#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Clock\TestClock;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\RoleHierarchyVoter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

require __DIR__.'/vendor/autoload.php';

$failures = 0;
$checks = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    ++$checks;

    if (!$ok) {
        ++$failures;
    }

    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? "  ($detail)" : '');
}

function expect(string $label, mixed $actual, mixed $expected): void
{
    check($label, $actual === $expected, $actual === $expected ? '' : \sprintf('got %s, want %s', var_export($actual, true), var_export($expected, true)));
}

$clock = new TestClock(__DIR__.'/var/clock');
$clock->reset();
$now = $clock->now()->getTimestamp();

$user = new InMemoryUser('michal', 'sudo', ['ROLE_USER']);

echo "== the trust resolver, called directly ==\n";

$constructor = [];
foreach ((new \ReflectionMethod(AuthenticationTrustResolver::class, '__construct'))->getParameters() as $parameter) {
    $constructor[$parameter->getName()] = $parameter->getDefaultValue();
}
expect('default recent window is 2h', $constructor['recentAuthenticationLifetime'] ?? null, 7200);
expect('default very recent window is 5min', $constructor['veryRecentAuthenticationLifetime'] ?? null, 300);

$resolver = new AuthenticationTrustResolver(7200, 300, $clock);

$withProofs = static function (array $proofs) use ($user): UsernamePasswordToken {
    $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
    $token->setAuthenticationProofs($proofs);

    return $token;
};

expect('just logged in: recently', $resolver->isAuthenticatedRecently($withProofs(['pwd' => $now])), true);
expect('just logged in: very recently', $resolver->isAuthenticatedVeryRecently($withProofs(['pwd' => $now])), true);

$tenMinutes = $withProofs(['pwd' => $now - 600]);
expect('10 min after login: recently', $resolver->isAuthenticatedRecently($tenMinutes), true);
expect('10 min after login: very recently', $resolver->isAuthenticatedVeryRecently($tenMinutes), false);

$threeHours = $withProofs(['pwd' => $now - 3 * 3600]);
expect('3h after login: recently', $resolver->isAuthenticatedRecently($threeHours), false);
expect('3h after login: very recently', $resolver->isAuthenticatedVeryRecently($threeHours), false);

expect('exactly at the 7200s boundary: recently', $resolver->isAuthenticatedRecently($withProofs(['pwd' => $now - 7200])), true);
expect('one second past 7200s: recently', $resolver->isAuthenticatedRecently($withProofs(['pwd' => $now - 7201])), false);

expect('no proof at all: recently', $resolver->isAuthenticatedRecently($withProofs([])), false);
expect('no proof at all: very recently', $resolver->isAuthenticatedVeryRecently($withProofs([])), false);

$remembered = new RememberMeToken($user, 'main', 'secret');
$remembered->setAuthenticationProofs(['pwd' => $now]);
expect('remember-me token with fresh proofs: recently', $resolver->isAuthenticatedRecently($remembered), false);
expect('remember-me token with fresh proofs: very recently', $resolver->isAuthenticatedVeryRecently($remembered), false);

expect('password 3h ago + hardware key 1min ago: recently', $resolver->isAuthenticatedRecently($withProofs(['pwd' => $now - 3 * 3600, 'hwk' => $now - 60])), true);
expect('password 3h ago + hardware key 1min ago: very recently', $resolver->isAuthenticatedVeryRecently($withProofs(['pwd' => $now - 3 * 3600, 'hwk' => $now - 60])), true);
expect('hardware key 1h ago + password now: very recently', $resolver->isAuthenticatedVeryRecently($withProofs(['pwd' => $now, 'hwk' => $now - 3600])), true);

echo "\n== through the firewall ==\n";

$client = new KernelBrowser(new Kernel('test', true));

$request = static function (string $method, string $uri, array $parameters = []) use ($client): Response {
    $client->request($method, $uri, $parameters);
    $response = $client->getResponse();

    printf("  %-4s %-22s -> %d %s\n", $method, $uri, $response->getStatusCode(), $response->headers->get('Location') ?? '');

    return $response;
};

$login = static fn (string $username): Response => $request('POST', '/login', ['_username' => $username, '_password' => 'sudo']);

// The entry point generates a path, the login success handler an absolute URL, so
// compare paths to keep the checks about the destination rather than the format.
$locationPath = static fn (Response $response): ?string => null === ($location = $response->headers->get('Location')) ? null : parse_url($location, PHP_URL_PATH);

// Which attribute the denied request asked a fresh proof for.
$requestedAttribute = static fn (): ?string => $client->getRequest()->attributes->get(SecurityRequestAttributes::RE_AUTHENTICATION_ATTRIBUTE);

$login('michal');
expect('fresh login grants the very recent action', $request('POST', '/account/delete')->getContent(), "deleted the account\n");
expect('fresh login grants the recent action', $request('GET', '/account/email')->getStatusCode(), 200);

$clock->advance(600);
echo "  -- clock advanced by 10 minutes --\n";
expect('10 min later, the recent action still passes', $request('GET', '/account/email')->getStatusCode(), 200);

$response = $request('POST', '/account/delete');
expect('10 min later, the very recent action is redirected', $response->getStatusCode(), 302);
expect('...to the confirm page', $locationPath($response), '/confirm-password');
expect('...naming the attribute it wants proved', $requestedAttribute(), 'IS_AUTHENTICATED_VERY_RECENTLY');

$response = $login('michal');
expect('after re-authenticating, a POST target is not replayed', $locationPath($response), '/');
expect('the action has to be submitted again, and now runs', $request('POST', '/account/delete')->getContent(), "deleted the account\n");

$clock->advance(3 * 3600);
echo "  -- clock advanced by 3 hours --\n";
$response = $request('GET', '/account/api-keys');
expect('3h later, a safe method is redirected too', $response->getStatusCode(), 302);
expect('...to the confirm page', $locationPath($response), '/confirm-password');

$response = $login('michal');
expect('after re-authenticating, a GET target is replayed', $locationPath($response), '/account/api-keys');
expect('and the page now loads', $request('GET', '/account/api-keys')->getContent(), "list of API keys\n");

$clock->advance(3 * 3600);
echo "  -- clock advanced by 3 more hours --\n";
$response = $request('POST', '/admin/api-key');
expect('two attributes in the rule: bare 403, no step-up', $response->getStatusCode(), 403);
expect('...and no attribute is asked for', $requestedAttribute(), null);

$response = $request('GET', '/account/email');
expect('one attribute for the same user and staleness: step-up offered', $response->getStatusCode(), 302);
expect('...naming the attribute', $requestedAttribute(), 'IS_AUTHENTICATED_RECENTLY');

$login('admin');
$clock->advance(3 * 3600);
echo "  -- admin, authenticated 3 hours ago --\n";
expect('a stale admin still gets a step-up on a freshness-only check', $request('GET', '/account/email')->getStatusCode(), 302);

$response = $request('POST', '/admin/billing');
expect('role + freshness as two #[IsGranted]: step-up offered', $response->getStatusCode(), 302);
expect('...naming the freshness check', $requestedAttribute(), 'IS_AUTHENTICATED_RECENTLY');

echo "  -- the same freshness AND role, three ways, stale admin --\n";
expect('roles: [IS_AUTHENTICATED_RECENTLY, ROLE_ADMIN] -> 200: the list is an OR, so the role alone grants', $request('POST', '/admin/api-key')->getStatusCode(), 200);
expect('...and no attribute is asked for', $requestedAttribute(), null);
expect('allow_if "freshness and role" -> step-up offered', $request('POST', '/admin/reports')->getStatusCode(), 302);
expect('...naming the freshness check', $requestedAttribute(), 'IS_AUTHENTICATED_RECENTLY');

echo "  -- why the deprecated rule's other suggestion, the role hierarchy, cannot help here --\n";
$hierarchyVoter = new RoleHierarchyVoter(new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_SUPER_ADMIN']]));
$adminToken = new UsernamePasswordToken(new InMemoryUser('admin', 'sudo', ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']);
expect('a hierarchy does decide a real role it reaches', $hierarchyVoter->vote($adminToken, null, ['ROLE_SUPER_ADMIN']), VoterInterface::ACCESS_GRANTED);
expect('but abstains on the freshness attribute in the same voter', $hierarchyVoter->vote($adminToken, null, ['IS_AUTHENTICATED_RECENTLY']), VoterInterface::ACCESS_ABSTAIN);

echo "\n".($failures ? "$failures of $checks checks FAILED\n" : "all $checks checks passed\n");

exit($failures ? 1 : 0);
