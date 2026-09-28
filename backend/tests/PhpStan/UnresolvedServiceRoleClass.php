<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

final readonly class UnresolvedServiceRoleClass
{
    public function __construct(public string $name, public string $file, public int $line)
    {
    }

    public function toError(): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Service role: %s cannot be reflected, so no role check sees it.',
            $this->name,
        ))
            ->identifier('simpleFeedReader.serviceRole.unresolved')
            ->file($this->file)
            ->line($this->line)
            ->build();
    }
}
