<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Mail\MailCapability;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses to serve production traffic while a fail-open placeholder is still in
 * place. Two committed defaults turn a forgotten override into a silent failure
 * that looks healthy indefinitely:
 *
 *  * ALTCHA_HMAC_KEY ships as a known string in a PUBLIC repository, and the key
 *    is the ONLY thing making a challenge unforgeable: anyone holding it forges
 *    a valid solution in one hash instead of ~150k. The proof-of-work on
 *    /register and /password-reset-request becomes free while the endpoints
 *    still answer 200.
 *  * MAILER_DSN=null://null discards every message and reports SUCCESS — no
 *    error, no warning, no log line. Registration returns 202 with no
 *    verification mail, the admin queue never fills, password reset does
 *    nothing, and users conclude the site is broken while the logs stay clean.
 *
 * null://null is not always that mistake: MAIL_DISABLED=1 (App\Service\Mail\
 * MailCapability, #230) makes "no mail" a deliberate opt-in state, and this
 * guard accepts null://null only under that flag. Both defaults fail OPEN; this
 * guard makes them fail closed, the stance
 * App\Controller\MaintenanceController::isAuthorized() already takes on an empty
 * token.
 *
 * WHY kernel.request, NOT A COMPILER PASS OR KERNEL BOOT CHECK: a deploy runs
 * `cache:warmup` in prod before the `current` symlink flips, so those hooks
 * would abort warmup on a misconfigured — or merely differently-configured —
 * environment and take the whole deploy down, converting a config mistake into
 * an outage of the release process itself. kernel.request is safe because
 * secrets need not exist at BUILD time: a host injecting ALTCHA_HMAC_KEY
 * per-request (Apache SetEnv, php-fpm pool directive) has only the .env default
 * visible during warmup, but the true value by the time a request arrives.
 * Warmup, migrations and every console command are untouched — this listener
 * runs only on an incoming HTTP request.
 *
 * The throw surfaces as a 500, deliberately: the operator's message goes to the
 * log (App\EventListener\ApiExceptionListener suppresses exception messages in
 * non-debug responses), so the client learns nothing while the log names the
 * variable to set. Refusing every route, not only the affected ones, is
 * intentional too — a half-serving instance with a void CAPTCHA or black-hole
 * mailer is a site quietly failing at what it is for.
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
    public const string PLACEHOLDER_ALTCHA_HMAC_KEY = 'test-altcha-hmac-key-not-for-production';
    public const string NULL_MAILER_DSN = 'null://null';

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%env(ALTCHA_HMAC_KEY)%')]
        private string $altchaHmacKey,
        #[Autowire('%env(MAILER_DSN)%')]
        private string $mailerDsn,
        private MailCapability $mail,
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

        if (self::NULL_MAILER_DSN === $this->mailerDsn && $this->mail->isEnabled()) {
            $problems[] = 'Set MAILER_DSN to a real transport, or set MAIL_DISABLED=1 to run '
                . 'this instance without mail; it is still null://null with mail enabled, which '
                . 'discards every message and reports success, so verification and password-reset '
                . 'mail is silently lost and nothing logs an error.';
        }

        return $problems;
    }
}
