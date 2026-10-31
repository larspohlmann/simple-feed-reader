<?php

declare(strict_types=1);

namespace App\Service\SelfUpdate\UpdateStateStore;

use App\Service\SelfUpdate\Exception\MalformedUpdateStateException;
use App\Service\SelfUpdate\Exception\UpdateRequestNotSavedException;
use App\Service\SelfUpdate\Model\UpdateRequestModel;
use App\Service\SelfUpdate\Model\UpdateRunModel;
use App\Service\SelfUpdate\Pass\StateFileFields;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FileUpdateStateStore implements UpdateStateStoreInterface
{
    private const int LOG_TAIL_BYTES = 16384;

    private const string HEARTBEAT = 'outbox/heartbeat';

    private const string STATUS = 'outbox/status.json';

    private const string RUN_LOG = 'outbox/run.log';

    private const string INBOX = 'inbox';

    private const string REQUEST = self::INBOX . '/request.json';

    public function __construct(
        #[Autowire('%update_state_directory%')]
        private string $directory,
    ) {
    }

    public function heartbeatAt(): ?\DateTimeImmutable
    {
        $seconds = trim($this->contentsOrEmpty(self::HEARTBEAT));
        if (!ctype_digit($seconds)) {
            return null;
        }

        return new \DateTimeImmutable('@' . $seconds);
    }

    public function pendingRequestId(): ?string
    {
        return $this->decodedFile(self::REQUEST)?->requiredString('id');
    }

    public function lastRun(): ?UpdateRunModel
    {
        $status = $this->decodedFile(self::STATUS);
        if (null === $status) {
            return null;
        }

        return new UpdateRunModel(
            $status->requiredString('requestId'),
            $status->phase('phase'),
            $status->dateOrNull('startedAt'),
            $status->dateOrNull('finishedAt'),
            $status->integerOrNull('exitCode'),
        );
    }

    public function logTail(): string
    {
        $path = $this->path(self::RUN_LOG);
        $size = is_file($path) ? filesize($path) : false;
        if (false === $size) {
            return '';
        }

        return $this->tailOf($path, $size);
    }

    public function saveRequest(UpdateRequestModel $request): void
    {
        $inbox = $this->path(self::INBOX);
        if (!is_writable($inbox)) {
            throw new UpdateRequestNotSavedException(sprintf('%s is not a writable directory.', $inbox));
        }

        $temporaryPath = $this->path(self::REQUEST . '.tmp');
        if (false === file_put_contents($temporaryPath, $request->toStateFileJson())) {
            throw new UpdateRequestNotSavedException(sprintf('%s could not be written.', $temporaryPath));
        }
        if (!rename($temporaryPath, $this->path(self::REQUEST))) {
            throw new UpdateRequestNotSavedException(sprintf('%s could not be moved into place.', $temporaryPath));
        }
    }

    private function decodedFile(string $name): ?StateFileFields
    {
        if (!is_file($this->path($name))) {
            return null;
        }

        try {
            $decoded = json_decode($this->contentsOrEmpty($name), flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new MalformedUpdateStateException(sprintf('%s is not valid JSON.', $name), previous: $exception);
        }
        if (!$decoded instanceof \stdClass) {
            throw new MalformedUpdateStateException(sprintf('%s does not hold a JSON object.', $name));
        }

        return new StateFileFields($name, get_object_vars($decoded));
    }

    private function contentsOrEmpty(string $name): string
    {
        $path = $this->path($name);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function tailOf(string $path, int $size): string
    {
        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return '';
        }
        if ($size > self::LOG_TAIL_BYTES) {
            fseek($handle, -self::LOG_TAIL_BYTES, \SEEK_END);
        }
        $tail = stream_get_contents($handle);
        fclose($handle);

        return false === $tail ? '' : $tail;
    }

    private function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }
}
