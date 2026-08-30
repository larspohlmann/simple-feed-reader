<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Contracts\Translation\TranslatorInterface;

/** Records every trans() call's parameters, so a test can assert their exact types. */
final class RecordingTranslator implements TranslatorInterface
{
    /** @var list<array{id: string, parameters: array<string, mixed>}> */
    public array $calls = [];

    /** @param array<string, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $this->calls[] = ['id' => $id, 'parameters' => $parameters];

        return $id;
    }

    public function getLocale(): string
    {
        return 'en';
    }
}
