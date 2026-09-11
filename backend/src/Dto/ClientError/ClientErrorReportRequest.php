<?php

declare(strict_types=1);

namespace App\Dto\ClientError;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ClientErrorReportRequest
{
    /**
     * @param list<ClientErrorItem> $errors
     */
    public function __construct(
        #[Assert\Valid]
        #[Assert\Count(min: 1, max: 10)]
        public array $errors = [],
    ) {
    }
}
