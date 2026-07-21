<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses to serve production traffic while a fail-open placeholder is still in
 * place.
 *
 * Two committed defaults turn a forgotten override into a silent failure, and
 * silence is the whole problem — neither one breaks anything visibly, so a
 * deployment that missed them looks healthy indefinitely:
 *
 *  * ALTCHA_HMAC_KEY ships as a known string in a PUBLIC repository. The key is
 *    the ONLY thing making a challenge unforgeable: anyone holding it picks a
 *    salt and a number, sets `expires` far out, computes the HMAC, and submits
 *    a valid solution having done one hash instead of ~150k. The proof-of-work
 *    on /register and /password-reset-request becomes free, and the endpoints
 *    still answer 200, so nothing anywhere reports a problem.
 *
 *  * MAILER_DSN=null://null discards every message and reports SUCCESS. Not an
 *    error, not a warning, not a log line — `null://` sending is a successful
 *    send. Registration returns 202 with no verification mail, the admin queue
 *    never fills because nobody can verify, password reset silently does
 *    nothing, and every user concludes the site is broken while the logs stay
 *    clean.
 *
 * Both fail OPEN. This guard makes them fail closed, which is the stance
 * App\Controller\MaintenanceController::isAuthorized() already takes when its
 * token is empty: refuse, rather than accept and hope.
 *
 * WHY kernel.request AND NOT A COMPILER PASS OR A KERNEL BOOT CHECK. A deploy
 * runs `cache:warmup` on the production host, in prod, before the `current`
 * symlink flips. Both of those hooks execute during warmup, so a misconfigured
 * — or merely differently-configured — environment would abort the warmup and
 * take the whole deploy down with it, including deploys that were going to be
 * fine. That failure mode is worse than the one being fixed: it converts a
 * config mistake into an outage of the release process itself.
 *
 * The distinction that makes kernel.request safe is that secrets need not be
 * present at BUILD time. A host that injects ALTCHA_HMAC_KEY per-request (an
 * Apache SetEnv, a php-fpm pool directive) legitimately has only the .env
 * default visible while warmup runs; aborting there would be a false positive.
 * By the time a real request arrives the true value is in the environment, so
 * checking then is both correct and non-blocking. Warmup, migrations and every
 * other console command are untouched — this listener only ever runs on an
 * incoming HTTP request.
 *
 * The throw surfaces as a 500. Deliberately: the operator's message goes to the
 * log (App\EventListener\ApiExceptionListener suppresses exception messages in
 * non-debug responses), so the client learns nothing about the configuration
 * while the log says exactly which variable to set. Refusing every route rather
 * than only the affected ones is intentional too — a half-serving instance with
 * a void CAPTCHA or a black-hole mailer is not a degraded site, it is a site
 * quietly failing at the things it is for.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 4096)]
final readonly class InsecureProductionConfigGuard
{
    /**
     * The literals committed to .env. Matching on the exact placeholder rather
     * than on some notion of "weak" keeps this a check for "you forgot to
     * override", which is a fact, instead of a strength heuristic that would
     * both miss real weak keys and reject fine unusual ones.
     */
    public const PLACEHOLDER_ALTCHA_HMAC_KEY = 'test-altcha-hmac-key-not-for-production';
    public const NULL_MAILER_DSN = 'null://null';

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%env(ALTCHA_HMAC_KEY)%')]
        private string $altchaHmacKey,
        #[Autowire('%env(MAILER_DSN)%')]
        private string $mailerDsn,
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
        // dev and test rely on these defaults: the test suite solves real
        // ALTCHA challenges with the committed key, and null:// is what keeps
        // a local run from mailing anyone. Only prod is held to the rule.
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

        if (self::NULL_MAILER_DSN === $this->mailerDsn) {
            $problems[] = 'Set MAILER_DSN to a real transport; it is still null://null, which '
                . 'discards every message and reports success, so verification and '
                . 'password-reset mail is silently lost and nothing logs an error.';
        }

        return $problems;
    }
}
