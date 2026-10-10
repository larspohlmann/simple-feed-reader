<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Model;

/** One player URL family of a provider: its anchored frame pattern, what it plays, and the box it needs. */
final readonly class EmbedFrameModel
{
    public function __construct(
        public string $pattern,
        public EmbedKind $kind,
        public EmbedShape $shape,
    ) {
    }

    public function matches(string $url): bool
    {
        return preg_match('~' . $this->pattern . '~', $url) === 1;
    }

    /** @return array{pattern: string, kind: string, shape: string} */
    public function toAllowlistEntry(): array
    {
        return ['pattern' => $this->pattern, 'kind' => $this->kind->value, 'shape' => $this->shape->value];
    }
}
