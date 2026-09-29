<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth\Oidc;

use App\Service\OAuth\OAuthProvider\AbstractOidcProvider;
use App\Service\OAuth\Oidc\Model\IdTokenModel;
use App\Service\OAuth\Oidc\Pass\IdTokenVerifier;
use App\Service\OAuth\Oidc\Pass\TokenEndpoint;
use PHPUnit\Framework\TestCase;

/**
 * Structural guards for the ID-token trust boundary: the verifier skips the signature, so only a token TokenEndpoint
 * fetched may reach it. docs/oauth-sign-in.md#the-id-token-trust-boundary
 */
final class OidcBoundaryTest extends TestCase
{
    /** A `string` parameter would admit a raw JWT from any channel into a verifier that skips signatures. */
    public function testTheVerifierAcceptsOnlyAFetchedIdTokenNeverARawString(): void
    {
        $parameters = (new \ReflectionMethod(IdTokenVerifier::class, 'verify'))->getParameters();
        $type = $parameters[0]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(
            IdTokenModel::class,
            $type->getName(),
            'verify() must take an IdToken: a string parameter would accept a token from any channel.',
        );
    }

    /** Production code only: the tests construct IdTokenModels on purpose, to exercise the verifier offline. */
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

    /** Tokenised, not matched: a comment cannot trip it, and no spelling of `new IdTokenModel` slips past. */
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

                if (IdTokenModel::class === $candidate[1] || 'IdTokenModel' === end($segments)) {
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
        self::assertTrue((new \ReflectionClass(IdTokenModel::class))->isFinal());
    }

    /**
     * @return list<string> every .php file under src/
     */
    private function productionSources(): array
    {
        $file = (new \ReflectionClass(IdTokenModel::class))->getFileName();
        self::assertIsString($file);

        // src/Service/OAuth/Oidc/Model/IdTokenModel.php -> src/
        $src = \dirname($file, 5);
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
