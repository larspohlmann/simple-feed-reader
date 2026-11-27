<?php

declare(strict_types=1);

namespace App\Service\Library\Search\Membership\Model;

/**
 * The members below the settled ceiling, grouped by their mark, lowest mark first.
 *
 * @template TMember
 */
final readonly class MarkGroupsModel
{
    /** @param array<int, non-empty-list<TMember>> $membersByMark */
    private function __construct(public int $ceiling, public array $membersByMark)
    {
    }

    /**
     * @template TGrouped
     *
     * @param list<TGrouped>         $members
     * @param \Closure(TGrouped): int $readMark
     *
     * @return self<TGrouped>
     */
    public static function fromMembers(int $ceiling, array $members, \Closure $readMark): self
    {
        $groups = [];
        foreach ($members as $member) {
            $groups[$readMark($member)][] = $member;
        }
        ksort($groups);

        return new self($ceiling, $groups);
    }
}
