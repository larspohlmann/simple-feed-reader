<?php

declare(strict_types=1);

namespace App\Dto\Entry;

use App\Exception\ValidationException;
use App\Service\Reading\Model\ReadScopeKind;
use App\Service\Reading\Model\ReadScopeModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class MarkReadRequest
{
    public function __construct(
        #[Assert\Choice(choices: [ReadScopeKind::All->value, ReadScopeKind::Feed->value, ReadScopeKind::Tag->value])]
        public string $scope,
        public \DateTimeImmutable $until,
        #[Assert\Positive]
        public ?int $id = null,
    ) {
    }

    public function toScope(): ReadScopeModel
    {
        $kind = ReadScopeKind::tryFrom($this->scope)
            ?? throw new ValidationException(['scope' => [sprintf('Unknown scope "%s".', $this->scope)]]);

        return match ($kind) {
            ReadScopeKind::All => ReadScopeModel::all(),
            ReadScopeKind::Feed => ReadScopeModel::feed($this->requiredIdFor($kind)),
            ReadScopeKind::Tag => ReadScopeModel::tag($this->requiredIdFor($kind)),
        };
    }

    private function requiredIdFor(ReadScopeKind $kind): int
    {
        return $this->id ?? throw new ValidationException([
            'id' => [sprintf('An id is required when scope is "%s".', $kind->value)],
        ]);
    }
}
