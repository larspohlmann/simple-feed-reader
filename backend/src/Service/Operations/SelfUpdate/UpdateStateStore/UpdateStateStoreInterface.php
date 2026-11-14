<?php

declare(strict_types=1);

namespace App\Service\SelfUpdate\UpdateStateStore;

use App\Service\SelfUpdate\Model\UpdateRequestModel;
use App\Service\SelfUpdate\Model\UpdateRunModel;

interface UpdateStateStoreInterface
{
    public function heartbeatAt(): ?\DateTimeImmutable;

    public function pendingRequestId(): ?string;

    public function lastRun(): ?UpdateRunModel;

    public function logTail(): string;

    public function saveRequest(UpdateRequestModel $request): void;
}
