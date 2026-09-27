<?php

declare(strict_types=1);

namespace App\Tests\Dto\SavedSearch;

use App\Dto\SavedSearch\CreateSavedSearchRequest;
use PHPUnit\Framework\TestCase;

final class CreateSavedSearchRequestTest extends TestCase
{
    public function testToDefinitionCarriesTheTermAndBothMatchModes(): void
    {
        $definition = (new CreateSavedSearchRequest('climate', true, false))->toDefinition();

        self::assertSame(['term' => 'climate', 'wholeWord' => true, 'phrase' => false], get_object_vars($definition));
    }

    public function testToDefinitionKeepsThePhraseModeApartFromTheWholeWordMode(): void
    {
        $definition = (new CreateSavedSearchRequest('climate', false, true))->toDefinition();

        self::assertSame(['term' => 'climate', 'wholeWord' => false, 'phrase' => true], get_object_vars($definition));
    }
}
