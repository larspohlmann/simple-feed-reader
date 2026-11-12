<?php

declare(strict_types=1);

namespace App\Dto\Subscription;

use App\Service\Url\Support\EnteredUrl;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SubscribeRequest
{
    #[Assert\NotBlank]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
    #[Assert\Length(max: 750)]
    public string $url;

    /**
     * A SourceFormat::VERBATIM value subscribes as that format, without re-discovery; any other value takes discovery.
     * Not an enum on purpose, so an unknown value never fails validation.
     */
    #[Assert\Length(max: 20)]
    public ?string $format;

    /**
     * Tag ids for the new feed. Ids the caller does not own are dropped when resolved, so this validates only shape.
     *
     * @var list<int>
     */
    #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
    public array $tagIds;

    #[Assert\Length(max: 512)]
    public ?string $title;

    /**
     * @param list<int> $tagIds
     */
    public function __construct(string $url = '', ?string $format = null, array $tagIds = [], ?string $title = null)
    {
        $this->url = EnteredUrl::withScheme($url);
        $this->format = $format;
        $this->tagIds = $tagIds;
        $this->title = $title;
    }
}
