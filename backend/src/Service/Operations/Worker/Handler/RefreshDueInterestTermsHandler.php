<?php

declare(strict_types=1);

namespace App\Service\Operations\Worker\Handler;

use App\Service\Curation\Recommendation\Stream\InterestTermSweep;
use App\Service\Operations\Worker\Message\RefreshDueInterestTerms;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RefreshDueInterestTermsHandler
{
    public function __construct(
        private InterestTermSweep $interestTerms,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshDueInterestTerms $message): void
    {
        $report = $this->interestTerms->refreshDue();
        $this->logger->info('Worker interest-term refresh finished.', ['report' => $report->toLogContext()]);
    }
}
