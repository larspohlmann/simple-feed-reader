<?php

declare(strict_types=1);

namespace App\Tests\Dto\Admin;

use App\Dto\Admin\InstanceSettingsRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Mirrors ProxySettingsRequestTest's boundary style for the two passkey
 * fields #624 added to this DTO.
 */
final class InstanceSettingsRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testPasskeyRpIdAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = new InstanceSettingsRequest(passkeyRpId: str_repeat('a', 255));
        $overLimit = new InstanceSettingsRequest(passkeyRpId: str_repeat('a', 256));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testPasskeyRpNameAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = new InstanceSettingsRequest(passkeyRpName: str_repeat('a', 100));
        $overLimit = new InstanceSettingsRequest(passkeyRpName: str_repeat('a', 101));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }
}
