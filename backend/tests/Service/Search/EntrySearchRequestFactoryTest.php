<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\User;
use App\Exception\ValidationException;
use App\Http\EntryCursor;
use App\Repository\EntryQuery;
use App\Service\Search\EntrySearchRequestFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class EntrySearchRequestFactoryTest extends TestCase
{
    private EntrySearchRequestFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new EntrySearchRequestFactory();
    }

    public function testBuildsAQueryFromQAlone(): void
    {
        $request = Request::create('/api/entries/search?q=angular');

        $query = $this->factory->fromRequest($request, $this->buildUser());

        self::assertSame(['angular'], $query->terms->terms);
        self::assertNull($query->cursor);
        self::assertSame(EntryQuery::DEFAULT_LIMIT, $query->limit);
    }

    public function testUsesTheIdOfThePassedUser(): void
    {
        $request = Request::create('/api/entries/search?q=angular');
        $user = $this->buildUser();
        // User has no id setter: the id only exists once Doctrine assigns it,
        // and this test builds the row by hand without booting the kernel.
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, 42);

        $query = $this->factory->fromRequest($request, $user);

        self::assertSame(42, $query->userId);
    }

    public function testRejectsAnUnknownQueryParameter(): void
    {
        $request = Request::create('/api/entries/search?q=angular&tag=3');

        try {
            $this->factory->fromRequest($request, $this->buildUser());
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame(['Unknown parameter "tag".'], $exception->errors['query'] ?? null);
        }
    }

    public function testRejectsQSentAsAList(): void
    {
        $request = Request::create('/api/entries/search?q[]=x');

        try {
            $this->factory->fromRequest($request, $this->buildUser());
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame(['Send one value for "q", not a list.'], $exception->errors['q'] ?? null);
        }
    }

    public function testRejectsCursorSentAsAList(): void
    {
        $request = Request::create('/api/entries/search?q=angular&cursor[]=x');

        try {
            $this->factory->fromRequest($request, $this->buildUser());
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame(['Send one value for "cursor", not a list.'], $exception->errors['cursor'] ?? null);
        }
    }

    public function testRejectsLimitSentAsAList(): void
    {
        $request = Request::create('/api/entries/search?q=angular&limit[]=x');

        try {
            $this->factory->fromRequest($request, $this->buildUser());
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame(['Send one value for "limit", not a list.'], $exception->errors['limit'] ?? null);
        }
    }

    public function testRejectsANonNumericLimit(): void
    {
        $request = Request::create('/api/entries/search?q=angular&limit=abc');

        try {
            $this->factory->fromRequest($request, $this->buildUser());
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame(['The limit must be a whole number.'], $exception->errors['limit'] ?? null);
        }
    }

    public function testRejectsAMalformedCursor(): void
    {
        $request = Request::create('/api/entries/search?q=angular&cursor=not-a-real-cursor');

        try {
            $this->factory->fromRequest($request, $this->buildUser());
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame(['The cursor is malformed.'], $exception->errors['cursor'] ?? null);
        }
    }

    public function testAcceptsAValidCursor(): void
    {
        $encoded = EntryCursor::encode(new \DateTimeImmutable('2026-07-01T00:00:00Z'), 7);
        $request = Request::create('/api/entries/search?q=angular&cursor=' . $encoded);

        $query = $this->factory->fromRequest($request, $this->buildUser());

        self::assertNotNull($query->cursor);
        self::assertSame(7, $query->cursor->id);
    }

    public function testClampsALimitAboveMaxLimitToTheCeiling(): void
    {
        // The query object clamps at construction, so `$query->limit` is the
        // EFFECTIVE page size rather than the size the client wished for. That
        // matters beyond the row count: `EntryPage::of()` decides whether a
        // page was full by comparing against this number, so a raw 150 here
        // would read a full page of 100 rows as short and end the list with no
        // nextCursor.
        $request = Request::create('/api/entries/search?q=angular&limit=' . (EntryQuery::MAX_LIMIT + 50));

        $query = $this->factory->fromRequest($request, $this->buildUser());

        self::assertSame(EntryQuery::MAX_LIMIT, $query->limit);
    }

    public function testRaisesALimitBelowOneToTheFloor(): void
    {
        $request = Request::create('/api/entries/search?q=angular&limit=0');

        $query = $this->factory->fromRequest($request, $this->buildUser());

        self::assertSame(1, $query->limit);
    }

    public function testRejectsAMissingQ(): void
    {
        $request = Request::create('/api/entries/search');

        $this->expectException(ValidationException::class);

        $this->factory->fromRequest($request, $this->buildUser());
    }

    private function buildUser(): User
    {
        return new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
    }
}
