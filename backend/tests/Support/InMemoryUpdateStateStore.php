<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\SelfUpdate\Model\UpdateRequestModel;
use App\Service\SelfUpdate\Model\UpdateRunModel;
use App\Service\SelfUpdate\UpdateStateStore\UpdateStateStoreInterface;

final class InMemoryUpdateStateStore implements UpdateStateStoreInterface
{
    /** @var list<UpdateRequestModel> */
    public array $savedRequests = [];

    public function __construct(
        public ?\DateTimeImmutable $heartbeat = null,
        public ?string $pendingRequestId = null,
        public ?UpdateRunModel $lastRun = null,
        public string $log = '',
    ) {
    }

    public function heartbeatAt(): ?\DateTimeImmutable
    {
        return $this->heartbeat;
    }

    public function pendingRequestId(): ?string
    {
        return $this->pendingRequestId;
    }

    public function lastRun(): ?UpdateRunModel
    {
        return $this->lastRun;
    }

    public function logTail(): string
    {
        return $this->log;
    }

    public function saveRequest(UpdateRequestModel $request): void
    {
        $this->savedRequests[] = $request;
        $this->pendingRequestId = $request->id;
    }
}
