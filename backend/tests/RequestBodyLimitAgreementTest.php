<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Five files in two stacks declare the request-body ceiling, and they must agree: nginx above php lets a body die in
 * PHP as an empty 200 instead of nginx's readable 413, and a dev stack out of step with prod passes on localhost only.
 */
final class RequestBodyLimitAgreementTest extends TestCase
{
    /** Line-anchored, so a commented-out mention never matches; a trailing comment is legal and still matches. */
    private const string NGINX_PATTERN = '/^[ \t]*client_max_body_size\s+(\d+)([kmg]?)\s*;/mi';
    private const string PHP_PATTERN = '/^[ \t]*post_max_size\s*=\s*(\d+)([kmg]?)[ \t]*(?:;.*)?$/mi';

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
        $this->requireStackConfigs();

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

    /**
     * The app container mounts only backend/, so where the docker/ tree is absent there is nothing to compare: skip.
     * One file missing while the tree exists is drift, and declaredLimitIn() fails on it.
     */
    private function requireStackConfigs(): void
    {
        if (!is_dir(\dirname(__DIR__, 2) . '/docker')) {
            self::markTestSkipped(
                'The Docker stack configs (repo-root docker/) are not mounted here '
                . '(e.g. inside the app container); this static check runs on the host / CI leg.',
            );
        }
    }

    /**
     * Every declaration in the file, not merely the first: two of these files
     * hold more than one `server` block, so a limit added to the wrong one
     * would otherwise win the match and hide the real declaration.
     */
    private function declaredLimitIn(string $relativePath, string $pattern): int
    {
        $absolutePath = \dirname(__DIR__, 2) . '/' . $relativePath;
        $contents = file_get_contents($absolutePath);
        self::assertIsString($contents, sprintf('Cannot read %s', $relativePath));

        preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER);
        self::assertCount(
            1,
            $matches,
            sprintf(
                '%s must declare the request-body limit exactly once; found %d.',
                $relativePath,
                \count($matches),
            ),
        );

        return (int) $matches[0][1] * $this->multiplierFor($matches[0][2]);
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
