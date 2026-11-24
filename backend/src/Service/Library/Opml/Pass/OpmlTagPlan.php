<?php

declare(strict_types=1);

namespace App\Service\Library\Opml\Pass;

use App\Entity\Tag;

/** The final tag name of every spot one import plans: a stored tag where it sits at that spot, else a free name. */
final class OpmlTagPlan
{
    private const int NAME_LIMIT = 100;

    /** @var array<string, list<Tag>> by lower-cased name */
    private array $storedByName = [];

    /** @var array<string, string> by spot */
    private array $finalNames = [];

    /** @var array<string, Tag> by spot, only for spots that reuse a stored tag */
    private array $reusedTags = [];

    /** @var array<string, true> by lower-cased final name */
    private array $heldNames = [];

    /** @param list<Tag> $storedTags */
    public function __construct(array $storedTags)
    {
        foreach ($storedTags as $tag) {
            $this->storedByName[mb_strtolower($tag->getName())][] = $tag;
        }
    }

    /**
     * @param list<string> $path
     *
     * @return list<string>
     */
    public function resolve(array $path): array
    {
        $resolved = [];
        $parentSpot = null;
        foreach ($path as $name) {
            $parentSpot = $this->resolveSpot($parentSpot, mb_substr($name, 0, self::NAME_LIMIT));
            $resolved[] = $this->finalNames[$parentSpot];
        }

        return $resolved;
    }

    private function resolveSpot(?string $parentSpot, string $name): string
    {
        $spot = $parentSpot . "\0" . mb_strtolower($name);
        if (!isset($this->finalNames[$spot])) {
            $this->claim($spot, $parentSpot, $name);
        }

        return $spot;
    }

    private function claim(string $spot, ?string $parentSpot, string $name): void
    {
        $candidate = $name;
        for ($number = 1; !$this->isFreeAt($candidate, $parentSpot); ++$number) {
            $candidate = self::buildNumberedName($name, $number);
        }
        $this->heldNames[mb_strtolower($candidate)] = true;
        $reused = $this->findStoredAt($candidate, $parentSpot);
        $this->finalNames[$spot] = $reused?->getName() ?? $candidate;
        if ($reused !== null) {
            $this->reusedTags[$spot] = $reused;
        }
    }

    private function isFreeAt(string $candidate, ?string $parentSpot): bool
    {
        if (isset($this->heldNames[mb_strtolower($candidate)])) {
            return false;
        }

        return !isset($this->storedByName[mb_strtolower($candidate)])
            || $this->findStoredAt($candidate, $parentSpot) !== null;
    }

    private function findStoredAt(string $candidate, ?string $parentSpot): ?Tag
    {
        if ($parentSpot !== null && !isset($this->reusedTags[$parentSpot])) {
            return null;
        }
        $parent = $parentSpot === null ? null : $this->reusedTags[$parentSpot];
        return array_find(
            $this->storedByName[mb_strtolower($candidate)] ?? [],
            static fn (Tag $tag): bool => $tag->getParent() === $parent,
        );
    }

    private static function buildNumberedName(string $name, int $number): string
    {
        $suffix = sprintf(' (%d)', $number);

        return mb_substr($name, 0, self::NAME_LIMIT - mb_strlen($suffix)) . $suffix;
    }
}
