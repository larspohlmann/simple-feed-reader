<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

/**
 * What a key comes to: its finished value, or a request for it.
 *
 * @template-covariant T
 */
final readonly class SettlementModel
{
    /** @param list<T> $outcomes empty for a request, so a request is a settlement of `never` */
    private function __construct(private array $outcomes, private ?RequestTicketModel $ticket)
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
    public static function request(RequestTicketModel $ticket): self
    {
        return new self([], $ticket);
    }

    public function isRequest(): bool
    {
        return null !== $this->ticket;
    }

    /** @throws \LogicException when this is a finished value */
    public function requestTicket(): RequestTicketModel
    {
        return $this->ticket ?? throw new \LogicException('A finished value asks for no request.');
    }

    /**
     * @return T
     *
     * @throws \LogicException when this is a request
     */
    public function settledOutcome(): mixed
    {
        if ([] === $this->outcomes) {
            throw new \LogicException('A request carries no outcome yet.');
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
        if (null !== $this->ticket) {
            return self::request($this->ticket);
        }

        return self::outcome($mapping($this->settledOutcome()));
    }
}
