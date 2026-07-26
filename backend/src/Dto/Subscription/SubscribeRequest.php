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
     * Candidate format the client picked ('scraped' subscribes the page
     * itself, skipping re-discovery). Deliberately not an enum choice:
     * unknown values simply take the default discovery path, so old
     * clients and new formats never hard-fail validation.
     */
    #[Assert\Length(max: 20)]
    public ?string $format;

    /**
     * Tag ids to attach to the new feed, picked in the same add-feed form.
     * Ownership is enforced when the ids are resolved (non-owned ids are
     * dropped, mirroring the PATCH tag-sync), so validation here only pins the
     * shape: a list of positive integers.
     *
     * @var list<int>
     */
    #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
    public array $tagIds;

    /**
     * @param list<int> $tagIds
     */
    public function __construct(string $url = '', ?string $format = null, array $tagIds = [])
    {
        $this->url = self::normalizeUrl($url);
        $this->format = $format;
        $this->tagIds = $tagIds;
    }

    /**
     * Let users type a bare host: "example.com" becomes "https://example.com"
     * so it survives the Url constraint below and reaches discovery. A value
     * that already carries any scheme is left untouched — an unsupported one
     * (e.g. ftp://) then fails the protocol check rather than being masked.
     * Nonsense stays nonsense: prefixing a scheme never rescues "not a url".
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
