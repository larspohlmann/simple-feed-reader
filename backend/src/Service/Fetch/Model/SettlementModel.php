<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

/**
 * What a pass made of one raw answer: the key's finished value, or one more request for the same key.
 *
 * @template-covariant T
 */
final readonly class SettlementModel
{
    /** @param list<T> $outcomes empty for a follow-up, so a follow-up is a settlement of `never` */
    private function __construct(private array $outcomes, private ?RequestTicketModel $followUpTicket)
    {
    }

    /**
     * @template V
     *
     * @param V $outcome
     *
     * @return self<V>
     */
    public static function outcome(mixed $outcome): self
    {
        return new self([$outcome], null);
    }

    /** @return self<never> */
    public static function followUp(RequestTicketModel $ticket): self
    {
        return new self([], $ticket);
    }

    public function isFollowUp(): bool
    {
        return null !== $this->followUpTicket;
    }

    /** @throws \LogicException when this is a finished value */
    public function followUpTicket(): RequestTicketModel
    {
        return $this->followUpTicket ?? throw new \LogicException('A finished value asks for no follow-up.');
    }

    /**
     * @return T
     *
     * @throws \LogicException when this is a follow-up
     */
    public function settledOutcome(): mixed
    {
        if ([] === $this->outcomes) {
            throw new \LogicException('A follow-up carries no outcome yet.');
        }

        return $this->outcomes[0];
    }

    /**
     * @template U
     *
     * @param \Closure(T): U $mapping
     *
     * @return self<U>
     */
    public function mapOutcome(\Closure $mapping): self
    {
        if (null !== $this->followUpTicket) {
            return self::followUp($this->followUpTicket);
        }

        return self::outcome($mapping($this->settledOutcome()));
    }
}
