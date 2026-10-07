<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Image\Model\DeclaredImageModel;

/** Builds and ranks the images feed elements declare by attribute. */
final class DeclaredImages
{
    public static function fromElement(\DOMElement $element, string $url): DeclaredImageModel
    {
        $width = self::positiveDimension($element->getAttribute('width'));

        return new DeclaredImageModel(
            $url,
            $width,
            self::positiveDimension($element->getAttribute('height')),
            DeclaredRenditions::ofWidth($url, $width),
        );
    }

    /**
     * An undeclared width loses to any declared one; with no widths, document order decides.
     *
     * @param list<DeclaredImageModel> $candidates
     */
    public static function widest(array $candidates): ?DeclaredImageModel
    {
        $best = $candidates[0] ?? null;
        foreach ($candidates as $candidate) {
            if (($candidate->width ?? 0) > ($best->width ?? 0)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    public static function positiveDimension(string $raw): ?int
    {
        $value = filter_var(trim($raw), FILTER_VALIDATE_INT);

        return \is_int($value) && $value > 0 ? $value : null;
    }

    private function __construct()
    {
    }
}
