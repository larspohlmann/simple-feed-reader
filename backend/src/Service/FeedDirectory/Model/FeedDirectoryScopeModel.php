<?php

declare(strict_types=1);

namespace App\Service\FeedDirectory\Model;

use App\Service\FeedDirectory\Exception\InvalidFeedDirectoryScopeException;

final readonly class FeedDirectoryScopeModel
{
    /** @var list<string> */
    public array $countries;

    /** @var list<string> */
    public array $languages;

    /**
     * @param list<string> $countries
     * @param list<string> $languages
     */
    public function __construct(array $countries, array $languages)
    {
        if (($countries === []) !== ($languages === [])) {
            throw new InvalidFeedDirectoryScopeException('Choose at least one country and one language, or neither.');
        }
        $this->countries = array_values(array_unique($countries));
        $this->languages = array_values(array_unique($languages));
    }

    /**
     * @param list<string> $countries
     * @param list<string> $languages
     */
    public static function orNone(array $countries, array $languages): self
    {
        if ($countries === [] || $languages === []) {
            return new self([], []);
        }

        return new self($countries, $languages);
    }
}
