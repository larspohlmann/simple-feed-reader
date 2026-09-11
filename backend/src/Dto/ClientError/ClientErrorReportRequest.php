<?php

declare(strict_types=1);

namespace App\Dto\ClientError;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ClientErrorReportRequest
{
    /** @var list<ClientErrorItem> */
    #[Assert\Valid]
    #[Assert\Count(min: 1, max: 10)]
    public array $errors;

    /**
     * @param list<ClientErrorItem> $errors
     */
    public function __construct(array $errors = [])
    {
        $this->errors = $errors;
    }
}
