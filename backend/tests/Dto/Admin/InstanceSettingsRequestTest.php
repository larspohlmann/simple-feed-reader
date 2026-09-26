<?php

declare(strict_types=1);

namespace App\Tests\Dto\Admin;

use App\Dto\Admin\InstanceSettingsRequest;
use App\Tests\Support\SettingsRequests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

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
        $atLimit = self::withRelyingParty(str_repeat('a', 255), null);
        $overLimit = self::withRelyingParty(str_repeat('a', 256), null);

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testPasskeyRpNameAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = self::withRelyingParty(null, str_repeat('a', 100));
        $overLimit = self::withRelyingParty(null, str_repeat('a', 101));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testTheUpdateCarriesEverySettingAndThePasskeyInvalidationDefaultsToOff(): void
    {
        $request = SettingsRequests::instance(
            requireEmailConfirmation: false,
            requireApproval: true,
            publicBaseUrl: 'https://reader.example',
            passkeyRpId: 'reader.example',
            passkeyRpName: 'Reader',
            passkeySignInEnabled: true,
        );

        $update = $request->toUpdate();

        self::assertFalse($request->invalidateExistingPasskeys);
        self::assertFalse($update->requireEmailConfirmation);
        self::assertTrue($update->requireApproval);
        self::assertSame('https://reader.example', $update->publicBaseUrl);
        self::assertSame('reader.example', $update->passkeyRpId);
        self::assertSame('Reader', $update->passkeyRpName);
        self::assertTrue($update->passkeySignInEnabled);
    }

    private static function withRelyingParty(?string $passkeyRpId, ?string $passkeyRpName): InstanceSettingsRequest
    {
        return SettingsRequests::instance(passkeyRpId: $passkeyRpId, passkeyRpName: $passkeyRpName);
    }
}
