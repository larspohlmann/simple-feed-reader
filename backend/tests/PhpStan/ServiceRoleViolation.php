<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

final readonly class ServiceRoleViolation
{
    public function __construct(
        public ServiceRoleCheck $check,
        public ServiceRoleClass $class,
        public string $problem,
        public ?string $home = null,
    ) {
    }

    /** The trailing "Its home is …." is what the #1202 move scripts read; keep its wording. */
    public function toError(): IdentifierRuleError
    {
        $message = sprintf('Service role "%s": %s %s.', $this->check->value, $this->class->name(), $this->problem);
        if (null !== $this->home) {
            $message .= sprintf(' Its home is %s.', $this->home);
        }

        return RuleErrorBuilder::message($message)
            ->identifier($this->check->identifier())
            ->file($this->class->file)
            ->line($this->class->line)
            ->build();
    }
}
