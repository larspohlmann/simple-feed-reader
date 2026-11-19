<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\LinkResolution;

use App\Entity\LinkCardContent;
use App\Enum\LinkResolution;
use App\Service\Content\LinkResolution\Model\LinkLandingModel;
use App\Service\Content\LinkResolution\Model\LinkResolutionBudgetModel;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Tests\Support\LinkLandingWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class LinkLandingTest extends TestCase
{
    private const string SHORT = 'https://n.pr/x';
    private const string ARTICLE = 'https://www.npr.org/2026/11/09/a';

    private LinkLandingWiring $wiring;

    protected function setUp(): void
    {
        $this->wiring = new LinkLandingWiring();
    }

    public function testAShortLinkResolvesToItsCleanedLanding(): void
    {
        $landing = $this->landingOf(self::SHORT, [
            LinkLandingWiring::redirect(self::ARTICLE . '?utm_source=bsky.app'),
            LinkLandingWiring::answer(Response::HTTP_OK),
        ]);

        self::assertEquals(LinkLandingModel::resolved(self::ARTICLE, self::hashOf(self::ARTICLE)), $landing);
        self::assertSame(['HEAD ' . self::SHORT, 'HEAD ' . self::ARTICLE . '?utm_source=bsky.app'], $this->requests());
    }

    public function testARedirectToABlockedArticleResolves(): void
    {
        $landing = $this->landingOf(self::SHORT, [
            LinkLandingWiring::redirect(self::ARTICLE),
            LinkLandingWiring::answer(Response::HTTP_FORBIDDEN),
        ]);

        self::assertSame(LinkResolution::Resolved, $landing->resolution);
        self::assertSame(['HEAD ' . self::SHORT, 'HEAD ' . self::ARTICLE], $this->requests());
    }

    public function testARedirectToAServerErrorResolves(): void
    {
        $landing = $this->landingOf(self::SHORT, [
            LinkLandingWiring::redirect(self::ARTICLE),
            LinkLandingWiring::answer(Response::HTTP_SERVICE_UNAVAILABLE),
        ]);

        self::assertSame(self::ARTICLE, $landing->url);
    }

    public function testASameSiteLongFormResolves(): void
    {
        $longForm = 'https://taz.de/Zehn-Jahre/!6227406/';

        $landing = $this->landingOf('https://taz.de/!6227406', [
            LinkLandingWiring::redirect($longForm),
            LinkLandingWiring::answer(Response::HTTP_OK),
        ]);

        self::assertEquals(LinkLandingModel::resolved($longForm, self::hashOf($longForm)), $landing);
    }

    public function testARedirectToATrackingVariantIsUnchanged(): void
    {
        $landing = $this->landingOf(self::SHORT, [
            LinkLandingWiring::redirect(self::SHORT . '?utm_medium=social'),
            LinkLandingWiring::answer(Response::HTTP_OK),
        ]);

        self::assertEquals(LinkLandingModel::unchanged(), $landing);
    }

    public function testASlashOnlyRedirectResolves(): void
    {
        $landing = $this->landingOf('https://t.example/abc', [
            LinkLandingWiring::redirect('https://t.example/abc/'),
            LinkLandingWiring::answer(Response::HTTP_OK),
        ]);

        self::assertSame('https://t.example/abc/', $landing->url);
    }

    public function testNoRedirectIsUnchanged(): void
    {
        $landing = $this->landingOf(self::SHORT, [LinkLandingWiring::answer(Response::HTTP_OK)]);

        self::assertEquals(LinkLandingModel::unchanged(), $landing);
        self::assertSame(['HEAD ' . self::SHORT], $this->requests());
    }

    public function testAMissingPageIsUnchanged(): void
    {
        $landing = $this->landingOf(self::SHORT, [
            LinkLandingWiring::answer(Response::HTTP_NOT_FOUND),
            LinkLandingWiring::answer(Response::HTTP_NOT_FOUND),
        ]);

        self::assertEquals(LinkLandingModel::unchanged(), $landing);
        self::assertSame(['HEAD ' . self::SHORT, 'GET ' . self::SHORT], $this->requests());
    }

    public function testARefusedHeadFallsBackToGet(): void
    {
        $landing = $this->landingOf(self::SHORT, [
            LinkLandingWiring::answer(Response::HTTP_TOO_MANY_REQUESTS),
            LinkLandingWiring::redirect(self::ARTICLE),
            LinkLandingWiring::answer(Response::HTTP_OK),
        ]);

        self::assertSame(self::ARTICLE, $landing->url);
        self::assertSame(['HEAD ' . self::SHORT, 'GET ' . self::SHORT, 'GET ' . self::ARTICLE], $this->requests());
    }

    public function testAHeadRedirectWithoutLocationFallsBackToGet(): void
    {
        $landing = $this->landingOf(self::SHORT, [
            LinkLandingWiring::answer(Response::HTTP_MOVED_PERMANENTLY),
            LinkLandingWiring::redirect(self::ARTICLE),
            LinkLandingWiring::answer(Response::HTTP_OK),
        ]);

        self::assertSame(self::ARTICLE, $landing->url);
    }

    public function testATargetThatTimesOutStillLands(): void
    {
        $landing = $this->landingOf(self::SHORT, [
            LinkLandingWiring::redirect(self::ARTICLE . '?utm_source=bsky.app'),
            LinkLandingWiring::failure(),
        ]);

        self::assertEquals(LinkLandingModel::resolved(self::ARTICLE, self::hashOf(self::ARTICLE)), $landing);
        self::assertSame(['HEAD ' . self::SHORT, 'HEAD ' . self::ARTICLE . '?utm_source=bsky.app'], $this->requests());
    }

    public function testATimedOutHeadIsNotRepeatedAsGet(): void
    {
        $landing = $this->landingOf(self::SHORT, [
            LinkLandingWiring::failure(),
            LinkLandingWiring::redirect(self::ARTICLE),
        ]);

        self::assertEquals(LinkLandingModel::retryable(), $landing);
        self::assertSame(['HEAD ' . self::SHORT], $this->requests());
    }

    public function testALandingLongerThanTheColumnIsUnresolved(): void
    {
        $tooLong = self::ARTICLE . '?continue=' . str_repeat('a', 2_048);

        $landing = $this->landingOf(self::SHORT, [
            LinkLandingWiring::redirect($tooLong),
            LinkLandingWiring::answer(Response::HTTP_OK),
        ]);

        self::assertEquals(LinkLandingModel::unresolved(), $landing);
    }

    public function testALandingThatFillsTheColumnResolves(): void
    {
        $fits = self::ARTICLE . '?continue=' . str_repeat('a', LinkCardContent::URL_MAX - \strlen(self::ARTICLE) - 10);

        $landing = $this->landingOf(self::SHORT, [
            LinkLandingWiring::redirect($fits),
            LinkLandingWiring::answer(Response::HTTP_OK),
        ]);

        self::assertSame($fits, $landing->url);
    }

    public function testAConnectionFailureIsRetryable(): void
    {
        $landing = $this->landingOf(self::SHORT, [LinkLandingWiring::failure()]);

        self::assertEquals(LinkLandingModel::retryable(), $landing);
    }

    public function testAThrottlingShortenerIsRetryable(): void
    {
        $landing = $this->landingOf(self::SHORT, [
            LinkLandingWiring::answer(Response::HTTP_TOO_MANY_REQUESTS),
            LinkLandingWiring::answer(Response::HTTP_TOO_MANY_REQUESTS),
        ]);

        self::assertEquals(LinkLandingModel::retryable(), $landing);
    }

    public function testAFailingShortenerIsRetryable(): void
    {
        $landing = $this->landingOf(self::SHORT, [
            LinkLandingWiring::answer(Response::HTTP_BAD_GATEWAY),
            LinkLandingWiring::answer(Response::HTTP_INTERNAL_SERVER_ERROR),
        ]);

        self::assertEquals(LinkLandingModel::retryable(), $landing);
    }

    public function testAClientErrorAtTheShortenerIsNotRetried(): void
    {
        $landing = $this->landingOf(self::SHORT, [
            LinkLandingWiring::answer(Response::HTTP_METHOD_NOT_ALLOWED),
            LinkLandingWiring::answer(Response::HTTP_REQUEST_HEADER_FIELDS_TOO_LARGE),
        ]);

        self::assertEquals(LinkLandingModel::unchanged(), $landing);
    }

    public function testALoopIsUnresolved(): void
    {
        $landing = $this->landingOf(self::SHORT, array_fill(0, 12, LinkLandingWiring::redirect(self::SHORT . '1')));

        self::assertEquals(LinkLandingModel::unresolved(), $landing);
    }

    public function testARedirectWithoutLocationIsUnresolved(): void
    {
        $landing = $this->landingOf(self::SHORT, [
            LinkLandingWiring::answer(Response::HTTP_MOVED_PERMANENTLY),
            LinkLandingWiring::answer(Response::HTTP_MOVED_PERMANENTLY),
        ]);

        self::assertEquals(LinkLandingModel::unresolved(), $landing);
    }

    public function testAHopToAPrivateAddressIsUnresolved(): void
    {
        $landing = $this->landingOf(self::SHORT, [
            LinkLandingWiring::redirect('https://internal.example/'),
            LinkLandingWiring::redirect('https://internal.example/'),
        ]);

        self::assertEquals(LinkLandingModel::unresolved(), $landing);
        self::assertSame(['HEAD ' . self::SHORT, 'GET ' . self::SHORT], $this->requests());
    }

    public function testTheRequestCarriesTheBudgetAndAgent(): void
    {
        $this->landingOf(self::SHORT, [LinkLandingWiring::answer(Response::HTTP_OK)]);

        $options = $this->wiring->requestOptions[0];
        self::assertSame(3.0, $options['timeout']);
        self::assertLessThanOrEqual(3.0, $options['max_duration']);
        self::assertGreaterThan(2.0, $options['max_duration']);
        self::assertContains('User-Agent: TestAgent/1.0', $options['headers']);
        self::assertContains('Accept: text/html,*/*;q=0.8', $options['headers']);
    }

    public function testTheWorstCaseIsTwoAttempts(): void
    {
        self::assertSame(6.0, LinkResolutionBudgetModel::MAX_SECONDS);
    }

    /** @param list<MockResponse> $responses */
    private function landingOf(string $url, array $responses): LinkLandingModel
    {
        return $this->wiring->landing($responses)->resolveLanding($url);
    }

    /** @return list<string> */
    private function requests(): array
    {
        return $this->wiring->requests;
    }

    private static function hashOf(string $url): string
    {
        return (string) new UrlNormalizer()->hash($url);
    }
}
