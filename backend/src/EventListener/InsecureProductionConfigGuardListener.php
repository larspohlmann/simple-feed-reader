<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses every production request while ALTCHA_HMAC_KEY still holds the public placeholder from .env, which makes
 * the proof-of-work forgeable. On kernel.request, not at build time: docs/security.md#insecure-production-config
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 4096)]
final readonly class InsecureProductionConfigGuardListener
{
    /** The literal committed to .env: a check for "not overridden", not a strength heuristic. */
    public const string PLACEHOLDER_ALTCHA_HMAC_KEY = 'test-altcha-hmac-key-not-for-production';

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%env(ALTCHA_HMAC_KEY)%')]
        private string $altchaHmacKey,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $problems = $this->problems();

        if ([] === $problems) {
            return;
        }

        throw new \RuntimeException(
            'Refusing to serve: production is still using a committed placeholder. '
            . implode(' ', $problems),
        );
    }

    /**
     * Public so the rules can be asserted directly, in both directions, without
     * standing up a prod kernel per case.
     *
     * @return list<string> one operator-actionable sentence per problem, empty when the config is sound
     */
    public function problems(): array
    {
        // dev and test rely on this default: the test suite solves real ALTCHA
        // challenges with the committed key. Only prod is held to the rule.
        if ('prod' !== $this->environment) {
            return [];
        }

        $problems = [];

        if (self::PLACEHOLDER_ALTCHA_HMAC_KEY === $this->altchaHmacKey) {
            $problems[] = 'Set ALTCHA_HMAC_KEY to a long random secret; it still holds the '
                . 'placeholder committed to .env, which is public, so anyone can forge a '
                . 'solved proof-of-work and the ALTCHA gate on /register and '
                . '/password-reset-request is void.';
        }

        return $problems;
    }
}
