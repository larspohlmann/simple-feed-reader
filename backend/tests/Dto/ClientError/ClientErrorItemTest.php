<?php

declare(strict_types=1);

namespace App\Tests\Dto\ClientError;

use App\Dto\ClientError\ClientErrorItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ClientErrorItemTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testRejectsABlankMessage(): void
    {
        $item = $this->itemWith('message', '');

        self::assertGreaterThan(0, $this->validator->validate($item)->count());
    }

    #[DataProvider('maxLengthFieldProvider')]
    public function testFieldAtItsLengthLimitIsValidButOneOverIsNot(string $field, int $max): void
    {
        $atLimit = $this->itemWith($field, str_repeat('a', $max));
        $overLimit = $this->itemWith($field, str_repeat('a', $max + 1));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, $this->validator->validate($overLimit)->count());
    }

    /** @return iterable<string, array{string, int}> */
    public static function maxLengthFieldProvider(): iterable
    {
        yield 'message' => ['message', 2000];
        yield 'stack' => ['stack', 8000];
        yield 'kind' => ['kind', 200];
        yield 'url' => ['url', 2000];
        yield 'route' => ['route', 500];
        yield 'buildVersion' => ['buildVersion', 200];
        yield 'userAgent' => ['userAgent', 500];
        yield 'at' => ['at', 40];
    }

    private function itemWith(string $field, string $value): ClientErrorItem
    {
        return new ClientErrorItem(
            message: 'message' === $field ? $value : 'boom',
            stack: 'stack' === $field ? $value : null,
            kind: 'kind' === $field ? $value : 'Error',
            url: 'url' === $field ? $value : null,
            route: 'route' === $field ? $value : '/reader',
            buildVersion: 'buildVersion' === $field ? $value : 'dev+local@',
            userAgent: 'userAgent' === $field ? $value : 'jest',
            at: 'at' === $field ? $value : '2026-09-11T00:00:00.000Z',
        );
    }
}
