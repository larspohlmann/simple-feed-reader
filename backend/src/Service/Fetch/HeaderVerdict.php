<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/**
 * What the classifier concluded from a response's headers alone. Modelled as
 * one type rather than a `FetchResponse|string|null` union so the caller cannot
 * mistake "follow this URL" for "here is your answer".
 */
final readonly class HeaderVerdict
{
    private function __construct(
        public HeaderDecision $decision,
        public ?FetchResponse $response,
        public ?string $redirectUrl,
        public bool $permanent,
    ) {
    }

    public static function awaitBody(): self
    {
        return new self(HeaderDecision::AwaitBody, null, null, false);
    }

    public static function terminal(FetchResponse $response): self
    {
        return new self(HeaderDecision::Terminal, $response, null, false);
    }

    public static function permanentRedirectTo(string $url): self
    {
        return new self(HeaderDecision::Redirect, null, $url, true);
    }

    public static function temporaryRedirectTo(string $url): self
    {
        return new self(HeaderDecision::Redirect, null, $url, false);
    }
}
