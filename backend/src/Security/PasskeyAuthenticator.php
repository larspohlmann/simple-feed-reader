<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\Passkey\AssertionVerifier;
use App\Service\Passkey\Exception\PasskeySignInFailureExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Passkey login as a firewall, so it issues the JWT through json_login's success and failure handlers.
 * Verification stays inside the UserBadge loader: eager verify() would run before LoginThrottlingListener's 429.
 * Why: docs/security.md#passkey-login-firewall
 */
final class PasskeyAuthenticator extends AbstractAuthenticator
{
    private const string LOGIN_PATH = '/api/auth/passkey/login';

    /**
     * A fixed sentinel, never client input: a discoverable login has no identifier and UserBadge deprecates ''.
     * The throttle keys on `identifier-IP`, so this is one bucket per client IP.
     */
    private const string THROTTLE_IDENTIFIER = 'passkey';

    public function __construct(
        private readonly AssertionVerifier $verifier,
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_success')]
        private readonly AuthenticationSuccessHandlerInterface $successHandler,
        private readonly LoginFailureHandler $failureHandler,
    ) {
    }

    public function supports(Request $request): bool
    {
        return self::LOGIN_PATH === $request->getPathInfo() && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        $payload = self::decodedPayload($request);

        return new SelfValidatingPassport(
            new UserBadge(self::THROTTLE_IDENTIFIER, fn (): User => $this->verifiedUser($payload)),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->successHandler->onAuthenticationSuccess($request, $token);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->failureHandler->onAuthenticationFailure($request, $exception);
    }

    /**
     * Every PasskeySignInFailureExceptionInterface becomes a plain AuthenticationException (the original kept as
     * `previous`), so LoginFailureHandler treats it like a password failure.
     *
     * @param array<string, mixed> $payload
     */
    private function verifiedUser(array $payload): User
    {
        $handle = $payload['handle'] ?? null;
        $credential = $payload['credential'] ?? null;

        (\is_string($handle) && \is_array($credential))
            || throw new AuthenticationException('Malformed passkey login request.');

        try {
            /** @var array<string, mixed> $credential */
            return $this->verifier->verify($handle, $credential)->getUser();
        } catch (PasskeySignInFailureExceptionInterface $exception) {
            throw new AuthenticationException('Passkey assertion rejected.', previous: $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodedPayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        if (!\is_array($payload)) {
            return [];
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
