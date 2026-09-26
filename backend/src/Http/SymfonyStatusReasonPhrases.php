<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Reader\StatusReasonPhrases;
use Symfony\Component\HttpFoundation\Response;

final readonly class SymfonyStatusReasonPhrases implements StatusReasonPhrases
{
    public function of(int $status): string
    {
        return Response::$statusTexts[$status] ?? '';
    }
}
