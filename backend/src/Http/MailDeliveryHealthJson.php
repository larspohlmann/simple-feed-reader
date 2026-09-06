<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\MailSendFailure;

/** Wire shape for the admin mail failure log (#882). */
final class MailDeliveryHealthJson
{
    /**
     * @param list<MailSendFailure> $recent
     *
     * @return array{failures: list<array{kind: string, recipient: string, error: string, at: string}>}
     */
    public static function view(array $recent): array
    {
        return [
            'failures' => array_map(
                static fn (MailSendFailure $failure): array => [
                    'kind' => $failure->getKind()->value,
                    'recipient' => $failure->getRecipient(),
                    'error' => $failure->getErrorDetail(),
                    'at' => $failure->getCreatedAt()->format(\DATE_ATOM),
                ],
                $recent,
            ),
        ];
    }
}
