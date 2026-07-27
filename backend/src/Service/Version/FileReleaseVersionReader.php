<?php

declare(strict_types=1);

namespace App\Service\Version;

use App\Service\Version\Exception\MalformedVersionFileException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the version.json the release build drops at the project root. It sits
 * outside public/, so the file itself is never web-served — only the endpoint
 * exposes what it holds.
 */
final readonly class FileReleaseVersionReader implements ReleaseVersionReader
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/version.json')]
        private string $versionFilePath,
    ) {
    }

    public function read(): ReleaseVersion
    {
        if (!is_file($this->versionFilePath)) {
            return ReleaseVersion::development();
        }

        $fields = $this->decode($this->contents());

        return new ReleaseVersion(
            $this->stringField($fields, 'version'),
            $this->stringField($fields, 'commit'),
            $this->stringField($fields, 'builtAt'),
        );
    }

    private function contents(): string
    {
        $contents = file_get_contents($this->versionFilePath);
        if (false === $contents) {
            throw new MalformedVersionFileException(
                sprintf('%s could not be read.', $this->versionFilePath),
            );
        }

        return $contents;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $contents): array
    {
        try {
            $decoded = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new MalformedVersionFileException(
                sprintf('%s is not valid JSON.', $this->versionFilePath),
                previous: $error,
            );
        }

        if (!is_array($decoded)) {
            throw new MalformedVersionFileException(
                sprintf('%s does not hold a JSON object.', $this->versionFilePath),
            );
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    private function stringField(array $fields, string $name): string
    {
        $value = $fields[$name] ?? null;
        if (!is_string($value) || '' === $value) {
            throw new MalformedVersionFileException(
                sprintf('%s carries no "%s" string.', $this->versionFilePath, $name),
            );
        }

        return $value;
    }
}
