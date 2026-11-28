<?php

declare(strict_types=1);

namespace App\Service\Library\SavedListExport\Model;

final readonly class SavedListFilenameModel
{
    private const string APP_SLUG = 'simplefeedreader';

    public function __construct(
        private SavedListScopeModel $scope,
        private \DateTimeImmutable $now,
    ) {
    }

    public function withExtension(string $extension): string
    {
        $fields = array_filter([
            self::APP_SLUG,
            self::sanitised($this->scope->listName()),
            $this->labelField(),
            $this->now->format('Ymd'),
        ], static fn (?string $field): bool => $field !== null);

        return implode('-', $fields) . $extension;
    }

    private function labelField(): ?string
    {
        if ($this->scope->isUnlabeled()) {
            return 'unlabeled';
        }
        if ($this->scope->labelId === null) {
            return null;
        }
        $name = self::sanitised($this->scope->labelName ?? '');

        return $name === '' ? 'label-' . $this->scope->labelId : $name;
    }

    private static function sanitised(string $value): string
    {
        $withoutForeignCharacters = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($value)) ?? '';
        $withoutRepeatedHyphens = preg_replace('/-{2,}/', '-', $withoutForeignCharacters) ?? '';
        $withoutRepeatedUnderscores = preg_replace('/_{2,}/', '_', $withoutRepeatedHyphens) ?? '';

        return trim($withoutRepeatedUnderscores, '-_');
    }
}
