<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Service\OAuth\AppleOAuthProvider;
use App\Service\OAuth\GoogleOAuthProvider;
use App\Service\OAuth\OAuthProviderRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The unit tests build the registry by hand, so they would stay green if
 * autowiring stopped collecting providers entirely. This one proves the
 * container actually finds them.
 *
 * It is not hypothetical. The first cut of this task used
 * `#[AutowireIterator(OAuthProviderInterface::class)]` on the strength of
 * `autoconfigure: true`, and it collected NOTHING: autoconfiguration does not
 * register a tag named after a plain application interface. The registry built
 * without complaint and answered UnknownProviderException for every provider,
 * which is byte-identical to a correctly wired deployment that holds no
 * credentials. Only booting the kernel and looking showed the difference.
 *
 * ## Why Apple's key is injected here instead of set in `.env.test`
 *
 * The plan offered two ways to make this test's premise true, since
 * APPLE_OAUTH_PRIVATE_KEY is deliberately empty in `.env.test` — a multi-line
 * PEM in a dotenv file is a portability trap. The options were: put a
 * single-line PEM there anyway, or assert only `google` here and leave Apple to
 * its unit tests.
 *
 * Neither was taken. The second is the weaker test the container change above
 * argues against: with only `google` asserted, a regression that dropped Apple
 * specifically would sail through, and "which providers does the container
 * collect" is the single question this file exists to answer. The first works
 * but keeps a second copy of the test key in a second format, and a duplicated
 * key drifts from the fixture the moment either is regenerated.
 *
 * So the key is supplied at runtime from the same fixture the Apple unit tests
 * use. Symfony resolves `%env(...)%` per container instance rather than baking
 * it into the compiled container, so writing $_ENV before bootKernel() reaches
 * the provider exactly as a real deployment's environment would — with one
 * source of truth for the key and nothing to keep in sync. `.env.test` stays
 * portable.
 */
final class OAuthProviderWiringTest extends KernelTestCase
{
    private const APPLE_KEY_VAR = 'APPLE_OAUTH_PRIVATE_KEY';

    /** @var array{bool, string|null} */
    private array $originalAppleKey = [false, null];

    protected function setUp(): void
    {
        parent::setUp();

        $existing = $_ENV[self::APPLE_KEY_VAR] ?? null;
        $this->originalAppleKey = [
            \array_key_exists(self::APPLE_KEY_VAR, $_ENV),
            \is_string($existing) ? $existing : null,
        ];
    }

    protected function tearDown(): void
    {
        // Restore before the next test boots a kernel: a leaked key would make
        // some later test believe this deployment offers Apple.
        [$existed, $value] = $this->originalAppleKey;
        if ($existed) {
            $_ENV[self::APPLE_KEY_VAR] = $value;
        } else {
            unset($_ENV[self::APPLE_KEY_VAR]);
        }

        parent::tearDown();
    }

    public function testTheContainerCollectsBothProviders(): void
    {
        $_ENV[self::APPLE_KEY_VAR] = (string) file_get_contents(
            __DIR__ . '/../../Fixtures/oauth/apple-test-key.p8',
        );

        $registry = $this->registry();

        $names = $registry->getConfiguredNames();
        // Sorted before comparing on purpose. The ORDER of this list is a real
        // property — it becomes the order of the sign-in buttons — but it is
        // pinned in OAuthProviderRegistryTest, where the input order is
        // controlled. Here the order is whatever sequence the container
        // happened to scan `src/Service/OAuth/` in, and asserting on that would
        // be a test that passes by accident and breaks on an unrelated rename.
        sort($names);

        self::assertSame(['apple', 'google'], $names);

        // Not just the right names — the real wired services. A registry that
        // somehow collected two stand-ins would satisfy the assertion above.
        self::assertInstanceOf(GoogleOAuthProvider::class, $registry->get('google'));
        self::assertInstanceOf(AppleOAuthProvider::class, $registry->get('apple'));
    }

    /**
     * The other half of the same guarantee: with Apple's key absent — which is
     * how `.env.test` ships, and how any deployment without Apple credentials
     * runs — Apple is collected but invisible, and Google is unaffected.
     *
     * This is what stops the test above from passing for the wrong reason. If
     * the key injection silently did nothing, both tests would still have to
     * agree, and they cannot: one asserts Apple is offered and the other that
     * it is not.
     */
    public function testAnUnconfiguredProviderIsCollectedButNotOffered(): void
    {
        $_ENV[self::APPLE_KEY_VAR] = '';

        $registry = $this->registry();

        self::assertSame(['google'], $registry->getConfiguredNames());
        self::assertInstanceOf(GoogleOAuthProvider::class, $registry->get('google'));
    }

    private function registry(): OAuthProviderRegistry
    {
        self::bootKernel();

        $registry = self::getContainer()->get(OAuthProviderRegistry::class);
        self::assertInstanceOf(OAuthProviderRegistry::class, $registry);

        return $registry;
    }
}
