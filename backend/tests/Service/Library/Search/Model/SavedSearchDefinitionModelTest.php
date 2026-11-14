<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Search\Model;

use App\Service\Library\Search\Model\SavedSearchDefinitionModel;
use PHPUnit\Framework\TestCase;

final class SavedSearchDefinitionModelTest extends TestCase
{
    public function testBothMatchModesDefaultToFalse(): void
    {
        $definition = new SavedSearchDefinitionModel('climate');

        self::assertFalse($definition->wholeWord);
        self::assertFalse($definition->phrase);
    }
}
