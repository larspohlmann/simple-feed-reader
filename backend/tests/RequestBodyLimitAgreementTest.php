<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The request-body ceiling is declared in five files across two Docker stacks
 * that share no configuration: the dev stack (docker/nginx/default.conf,
 * docker/php/conf.d/app.ini) and the prod images (docker/web/http.conf,
 * docker/web/tls.conf, docker/php/conf.d/prod.ini). All five have to carry the
 * same number, for two different reasons:
 *
 * - nginx above php means an oversized body is refused with a raw 413, which
 *   the client can read. nginx BELOW php means bodies between the two limits
 *   are discarded by PHP, which answers an empty 200 nobody can interpret.
 * - dev out of step with prod means a limit verified on localhost is still
 *   wrong on a self-hosted install.
 *
 * Both failures have already shipped: #412 raised the limit in the dev stack
 * only, and a self-hosted install went on refusing a real account backup until
 * #458. Nothing else in the tree ties these files together, so this test is
 * the only thing that does.
 */
final class RequestBodyLimitAgreementTest extends TestCase
{
    private const string NGINX_PATTERN = '/^\s*client_max_body_size\s+(\d+)([kmg]?);/mi';
    private const string PHP_PATTERN = '/^\s*post_max_size\s*=\s*(\d+)([kmg]?)\s*$/mi';

    private const array NGINX_FILES = [
        'docker/nginx/default.conf',
        'docker/web/http.conf',
        'docker/web/tls.conf',
    ];

    private const array PHP_FILES = [
        'docker/php/conf.d/app.ini',
        'docker/php/conf.d/prod.ini',
    ];

    public function testEveryStackDeclaresTheSameRequestBodyLimit(): void
    {
        $limitsByFile = [];
        foreach (self::NGINX_FILES as $path) {
            $limitsByFile[$path] = $this->declaredLimitIn($path, self::NGINX_PATTERN);
        }
        foreach (self::PHP_FILES as $path) {
            $limitsByFile[$path] = $this->declaredLimitIn($path, self::PHP_PATTERN);
        }

        self::assertCount(
            1,
            array_unique($limitsByFile),
            'The nginx and PHP body limits have drifted apart: ' . json_encode($limitsByFile),
        );
    }

    private function declaredLimitIn(string $relativePath, string $pattern): int
    {
        $absolutePath = \dirname(__DIR__, 2) . '/' . $relativePath;
        $contents = file_get_contents($absolutePath);
        self::assertIsString($contents, sprintf('Cannot read %s', $relativePath));

        $matched = preg_match($pattern, $contents, $matches);
        self::assertSame(
            1,
            $matched,
            sprintf('%s declares no request-body limit; every stack must set one.', $relativePath),
        );

        return (int) $matches[1] * $this->multiplierFor($matches[2]);
    }

    private function multiplierFor(string $unit): int
    {
        return match (strtolower($unit)) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
    }
}
