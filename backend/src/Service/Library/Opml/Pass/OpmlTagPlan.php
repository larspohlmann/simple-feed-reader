<?php

declare(strict_types=1);

namespace App\Service\Library\Opml\Pass;

use App\Entity\Tag;

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
        foreach (self::buildCandidates($name) as $candidate) {
            $reused = $this->findStoredAt($candidate, $parentSpot);
            if ($this->isFree($candidate, $reused)) {
                $this->hold($spot, $candidate, $reused);

                return;
            }
        }
    }

    private function isFree(string $candidate, ?Tag $reused): bool
    {
        $key = mb_strtolower($candidate);
        if (isset($this->heldNames[$key])) {
            return false;
        }

        return $reused !== null || !isset($this->storedByName[$key]);
    }

    private function hold(string $spot, string $candidate, ?Tag $reused): void
    {
        $this->heldNames[mb_strtolower($candidate)] = true;
        $this->finalNames[$spot] = $reused?->getName() ?? $candidate;
        if ($reused !== null) {
            $this->reusedTags[$spot] = $reused;
        }
    }

    private function findStoredAt(string $candidate, ?string $parentSpot): ?Tag
    {
        if ($parentSpot !== null && !isset($this->reusedTags[$parentSpot])) {
            return null;
        }
        $parent = $parentSpot === null ? null : $this->reusedTags[$parentSpot];

        return array_find(
            $this->storedByName[mb_strtolower($candidate)] ?? [],
            static fn (Tag $tag): bool => $tag->getParent()?->requireId() === $parent?->requireId(),
        );
    }

    /** @return \Generator<int, string> */
    private static function buildCandidates(string $name): \Generator
    {
        yield $name;
        for ($number = 1;; ++$number) {
            $suffix = sprintf(' (%d)', $number);

            yield mb_substr($name, 0, self::NAME_LIMIT - mb_strlen($suffix)) . $suffix;
        }
    }
}
