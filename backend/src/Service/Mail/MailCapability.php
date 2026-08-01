<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether this instance may send mail at all.
 *
 * A DEPLOY-TIME fact, not a runtime setting: docker-compose.prod.yml and
 * InsecureProductionConfigGuard both act before the database is reachable, so
 * the switch has to be an environment variable rather than a stored row.
 * MAIL_DISABLED=1 is the deliberate, opt-in "no mail" mode (issue #230); a
 * MAILER_DSN left at null://null WITHOUT this flag stays a forgotten-config
 * failure, not a mailless instance.
 */
final readonly class MailCapability
{
    public function __construct(
        #[Autowire('%env(default::MAIL_DISABLED)%')]
        private string $disabledFlag,
    ) {
    }

    public function isEnabled(): bool
    {
        return !\in_array(strtolower(trim($this->disabledFlag)), ['1', 'true', 'yes', 'on'], true);
    }
}
