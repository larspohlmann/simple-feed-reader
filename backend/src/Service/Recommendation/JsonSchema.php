<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * A named JSON Schema for a structured completion. The provider client wraps
 * it into the wire shape a `response_format: json_schema` request needs, so the
 * domain describes the answer it wants without knowing the transport framing.
 */
final readonly class JsonSchema
{
    /** @param array<string, mixed> $schema a JSON Schema document for the answer object */
    public function __construct(
        public string $name,
        public array $schema,
    ) {
    }
}
