<?php

declare(strict_types=1);

namespace App\Tests\Dto\Entry;

use App\Dto\Entry\MarkReadRequest;
use App\Exception\ValidationException;
use App\Service\Reading\Model\ReadScopeKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkReadRequestTest extends TestCase
{
    public function testAllIgnoresAnId(): void
    {
        $scope = $this->request('all', 5)->toScope();

        self::assertSame(ReadScopeKind::All, $scope->kind);
        $this->expectException(\LogicException::class);
        $scope->targetId();
    }

    public function testFeedCarriesItsId(): void
    {
        $scope = $this->request('feed', 5)->toScope();

        self::assertSame(ReadScopeKind::Feed, $scope->kind);
        self::assertSame(5, $scope->targetId());
    }

    public function testTagCarriesItsId(): void
    {
        $scope = $this->request('tag', 6)->toScope();

        self::assertSame(ReadScopeKind::Tag, $scope->kind);
        self::assertSame(6, $scope->targetId());
    }

    #[DataProvider('scopesThatNeedAnId')]
    public function testAFeedOrTagScopeWithoutAnIdIsAValidationError(string $scope, string $message): void
    {
        $this->assertRejectedWith(['id' => [$message]], $this->request($scope, null));
    }

    /** @return iterable<string, array{string, string}> */
    public static function scopesThatNeedAnId(): iterable
    {
        yield 'feed' => ['feed', 'An id is required when scope is "feed".'];
        yield 'tag' => ['tag', 'An id is required when scope is "tag".'];
    }

    public function testAnUnknownScopeIsAValidationError(): void
    {
        $this->assertRejectedWith(['scope' => ['Unknown scope "bogus".']], $this->request('bogus', null));
    }

    private function request(string $scope, ?int $id): MarkReadRequest
    {
        return new MarkReadRequest($scope, new \DateTimeImmutable('2026-07-10T00:00:00Z'), $id);
    }

    /** @param array<string, list<string>> $errors */
    private function assertRejectedWith(array $errors, MarkReadRequest $request): void
    {
        try {
            $request->toScope();
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame($errors, $exception->errors);
        }
    }
}
