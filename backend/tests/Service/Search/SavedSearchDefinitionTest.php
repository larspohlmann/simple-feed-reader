<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\SavedSearchDefinition;
use PHPUnit\Framework\TestCase;

final class SavedSearchDefinitionTest extends TestCase
{
    public function testBothMatchModesDefaultToFalse(): void
    {
        $definition = new SavedSearchDefinition('climate');

        self::assertFalse($definition->wholeWord);
        self::assertFalse($definition->phrase);
    }
}
