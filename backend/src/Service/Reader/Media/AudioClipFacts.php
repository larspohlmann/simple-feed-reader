<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Parser\Support\MediaDuration;
use Dom\Element;

/**
 * What a page says of one clip in the data attributes of the elements holding its URL; the first holder stating a
 * fact wins. A bare `title` names the play button's action, not the clip, so only `data-…title` counts.
 */
final readonly class AudioClipFacts
{
    private const string TITLE_ATTRIBUTE = '/^data-[\w-]*title$/';
    private const string DURATION_ATTRIBUTE = '/^data-[\w-]*duration$/';

    /** @param list<Element> $holders */
    public function titleOf(array $holders): ?string
    {
        foreach ($this->valuesNamed($holders, self::TITLE_ATTRIBUTE) as $value) {
            $title = trim($value);
            if ($title !== '') {
                return $title;
            }
        }

        return null;
    }

    /** @param list<Element> $holders */
    public function durationOf(array $holders): ?int
    {
        foreach ($this->valuesNamed($holders, self::DURATION_ATTRIBUTE) as $value) {
            $seconds = MediaDuration::seconds($value);
            if ($seconds !== null) {
                return $seconds;
            }
        }

        return null;
    }

    /**
     * @param list<Element> $holders
     *
     * @return iterable<string>
     */
    private function valuesNamed(array $holders, string $namePattern): iterable
    {
        foreach ($holders as $holder) {
            foreach ($holder->attributes as $attribute) {
                if (preg_match($namePattern, $attribute->name) === 1) {
                    yield $attribute->value;
                }
            }
        }
    }
}
