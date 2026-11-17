<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification\Model;

use App\Service\Social\Notification\Model\ContextReferenceModel;
use PHPUnit\Framework\TestCase;

final class ContextReferenceModelTest extends TestCase
{
    public function testIsEmptyOnlyWithoutAnyReference(): void
    {
        self::assertTrue(new ContextReferenceModel()->isEmpty());
        self::assertFalse(new ContextReferenceModel(answeredId: 'parent')->isEmpty());
        self::assertFalse(new ContextReferenceModel(threadRootId: 'root')->isEmpty());
    }
}
