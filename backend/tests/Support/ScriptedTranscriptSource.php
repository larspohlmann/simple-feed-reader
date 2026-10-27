<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Transcript\Exception\TranscriptUnusableException;
use App\Service\Transcript\TranscriptSource\TranscriptSourceInterface;
use Symfony\Component\HttpFoundation\Response;

/** Answers every transcript URL with one body, or refuses it, and remembers what it was asked for. */
final class ScriptedTranscriptSource implements TranscriptSourceInterface
{
    /** @var list<string> */
    public array $fetchedUrls = [];

    private function __construct(private readonly ?string $body)
    {
    }

    public static function answering(string $body): self
    {
        return new self($body);
    }

    public static function refusing(): self
    {
        return new self(null);
    }

    public function bodyAt(string $url, float $budgetSeconds): string
    {
        $this->fetchedUrls[] = $url;

        return $this->body ?? throw TranscriptUnusableException::answered($url, Response::HTTP_NOT_FOUND);
    }
}
