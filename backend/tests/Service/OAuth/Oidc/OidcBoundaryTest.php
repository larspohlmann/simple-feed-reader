<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth\Oidc;

use App\Service\OAuth\AbstractOidcProvider;
use App\Service\OAuth\Oidc\IdToken;
use App\Service\OAuth\Oidc\IdTokenVerifier;
use App\Service\OAuth\Oidc\TokenEndpoint;
use PHPUnit\Framework\TestCase;

/**
 * Structural guards, not behavioural ones.
 *
 * {@see IdTokenVerifier} does not check the ID token's signature. It is only
 * entitled to skip that because of where the token came from — straight off the
 * token endpoint, over validated TLS, with no redirect — so "where the token
 * came from" is a security control and needs to be enforced, not just described.
 *
 * Apple's `form_post` callback is the concrete danger: it carries an `id_token`
 * in the request body, which did NOT arrive by direct communication with the
 * token endpoint and would need full JWKS verification that nothing here does.
 * The defence is that there is no way to hand such a token to the verifier —
 * it accepts an {@see IdToken}, and only {@see TokenEndpoint} makes one.
 *
 * Every assertion below fails the build the moment that stops being true. They
 * replace the reflection guard that used to assert readIdentity() was private on
 * the provider, which was the same property expressed against the old shape.
 */
final class OidcBoundaryTest extends TestCase
{
    /**
     * The type wall itself.
     *
     * A `string` parameter here would let any caller holding a raw JWT — from a
     * callback body, a cookie, a database row — into a verifier that skips
     * signature checking.
     */
    public function testTheVerifierAcceptsOnlyAFetchedIdTokenNeverARawString(): void
    {
        $parameters = (new \ReflectionMethod(IdTokenVerifier::class, 'verify'))->getParameters();
        $type = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(
            IdToken::class,
            $type->getName(),
            'verify() must take an IdToken: a string parameter would accept a token from any channel.',
        );
    }

    /**
     * The other half of the wall. The type only means "fetched over pinned TLS"
     * for as long as the fetching class is the one place that mints it.
     *
     * Production code only — the unit tests construct IdTokens deliberately, to
     * exercise the verifier without a network.
     */
    public function testOnlyTheTokenEndpointConstructsAnIdToken(): void
    {
        $sites = [];

        foreach ($this->productionSources() as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);

            if ($this->constructsIdToken($source)) {
                $sites[] = basename($path);
            }
        }

        self::assertSame(
            ['TokenEndpoint.php'],
            $sites,
            'IdToken must be constructed only where the TLS preconditions are enforced. '
            . 'A new construction site means a token of unknown provenance can reach the verifier.',
        );
    }

    /**
     * Whether the source contains a real `new IdToken(...)` expression.
     *
     * Tokenised rather than matched with a regex so that neither a docblock
     * discussing the rule nor a string containing it can trip the guard — and,
     * more importantly, so that nobody can slip a construction past it by
     * writing it unusually. Only T_NEW followed by a name resolving to IdToken
     * counts, whether written short or fully qualified.
     */
    private function constructsIdToken(string $source): bool
    {
        $tokens = token_get_all($source);
        $total = \count($tokens);
        $nameTokens = [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED];

        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || \T_NEW !== $token[0]) {
                continue;
            }

            for ($next = $index + 1; $next < $total; ++$next) {
                $candidate = $tokens[$next];

                if (\is_array($candidate) && \T_WHITESPACE === $candidate[0]) {
                    continue;
                }

                if (!\is_array($candidate) || !\in_array($candidate[0], $nameTokens, true)) {
                    break;
                }

                $segments = explode('\\', $candidate[1]);

                if (IdToken::class === $candidate[1] || 'IdToken' === end($segments)) {
                    return true;
                }

                break;
            }
        }

        return false;
    }

    /**
     * An override could otherwise skip the token-endpoint fetch entirely and
     * feed the verifier whatever it liked.
     */
    public function testTheExchangeIsFinalSoNoSubclassCanSupplyItsOwnToken(): void
    {
        self::assertTrue(
            (new \ReflectionMethod(AbstractOidcProvider::class, 'exchangeCode'))->isFinal(),
        );
    }

    /**
     * Each claim check is a step in one argument, and the order matters. A
     * public guard would invite a caller to run some of them and skip the rest,
     * which is how a "verified" identity ends up unverified in one dimension.
     */
    public function testVerifyIsTheOnlyWayIntoTheClaimChecks(): void
    {
        $public = [];

        foreach ((new \ReflectionClass(IdTokenVerifier::class))->getMethods() as $method) {
            if ($method->isPublic() && !$method->isConstructor()) {
                $public[] = $method->getName();
            }
        }

        self::assertSame(['verify'], $public);
    }

    /**
     * The fetch enforces the three preconditions the whole exemption rests on,
     * so a subclass must not be able to replace it with one that does not.
     */
    public function testTheTokenEndpointCannotBeSubclassed(): void
    {
        self::assertTrue((new \ReflectionClass(TokenEndpoint::class))->isFinal());
        self::assertTrue((new \ReflectionClass(IdTokenVerifier::class))->isFinal());
        self::assertTrue((new \ReflectionClass(IdToken::class))->isFinal());
    }

    /**
     * @return list<string> every .php file under src/
     */
    private function productionSources(): array
    {
        $file = (new \ReflectionClass(IdToken::class))->getFileName();
        self::assertIsString($file);

        // src/Service/OAuth/Oidc/IdToken.php -> src/
        $src = \dirname($file, 4);
        self::assertSame('src', basename($src));

        $paths = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));

        foreach ($files as $entry) {
            if ($entry instanceof \SplFileInfo && 'php' === $entry->getExtension()) {
                $paths[] = $entry->getPathname();
            }
        }

        self::assertNotEmpty($paths);
        sort($paths);

        return $paths;
    }
}
