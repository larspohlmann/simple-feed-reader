<?php

declare(strict_types=1);

namespace App\Tests\Dto\Passkey;

use App\Dto\Passkey\RegisterPasskeyRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RegisterPasskeyRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testLabelAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = new RegisterPasskeyRequest(handle: 'h', credential: ['k' => 'v'], label: str_repeat('a', 100));
        $overLimit = new RegisterPasskeyRequest(handle: 'h', credential: ['k' => 'v'], label: str_repeat('a', 101));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }
}
