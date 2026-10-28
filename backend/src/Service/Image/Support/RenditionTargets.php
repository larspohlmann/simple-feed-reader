<?php

declare(strict_types=1);

namespace App\Service\Image\Support;

/** The widths the list views ask for: thumb 132 px at 2×, split 259 px, full column 680 px, each at 1× to 3×. */
final class RenditionTargets
{
    /** @var list<int> */
    public const array WIDTHS = [264, 520, 680, 1100, 1360];

    public const int LARGEST = self::WIDTHS[4];

    private const float TOP_BAND_STRETCH = 1.5;

    public static function bandOf(int $width): ?int
    {
        $band = null;
        foreach (self::WIDTHS as $target) {
            if ($width >= $target) {
                $band = $target;
            }
        }

        return $width < self::LARGEST * self::TOP_BAND_STRETCH ? $band : null;
    }

    /**
     * @param list<int> $knownWidths
     *
     * @return list<int>
     */
    public static function uncovered(array $knownWidths): array
    {
        $covered = array_map(self::bandOf(...), $knownWidths);

        return array_values(array_filter(
            self::WIDTHS,
            static fn (int $target): bool => !\in_array($target, $covered, true),
        ));
    }

    private function __construct()
    {
    }
}
