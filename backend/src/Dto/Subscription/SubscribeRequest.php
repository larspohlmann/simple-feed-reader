<?php

declare(strict_types=1);

namespace App\Dto\Subscription;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SubscribeRequest
{
    #[Assert\NotBlank]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
    #[Assert\Length(max: 750)]
    public string $url;

    /**
     * 'scraped' subscribes the page itself, without re-discovery. Not an enum on purpose: any other value, even one
     * an old client or a new format sends, takes the discovery path instead of failing validation.
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
        $this->url = self::normalizeUrl($url);
        $this->format = $format;
        $this->tagIds = $tagIds;
        $this->title = $title;
    }

    /**
     * Prefixes `https://` to a bare host so "example.com" passes the Url constraint. A value with any scheme stays as
     * it is, so `ftp://` still fails the protocol check.
     */
    private static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ('' === $url || 1 === preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) {
            return $url;
        }

        // Strip a protocol-relative "//" so it does not become "https:////host".
        return 'https://' . ltrim($url, '/');
    }
}
