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
 * - nginx ABOVE php lets a body pass the web server and die inside PHP, which
 *   does not answer 413: it drops the body and the client gets an empty 200 it
 *   cannot interpret. nginx at or below php refuses the same body itself, with
 *   a 413 the client can read. Equality is the simplest form of "not above",
 *   and it is what this test enforces -- a php limit raised on its own is safe
 *   but pointless, since nginx still refuses everything over its own cap.
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
    /** Both anchor at the start of a line, so a commented-out mention -- of
     *  which docker/nginx/default.conf has several -- can never match. Both
     *  tolerate a trailing comment, which is legal in either syntax and would
     *  otherwise read as "this file declares no limit at all". */
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
     * The five config files live under the repository root, which is present in
     * the CI runner and on a developer host but NOT inside the app container —
     * it mounts only backend/ (see docker-compose.yml). Where the whole docker/
     * tree is absent there is nothing to compare, so skip rather than fail. A
     * single file that goes missing WHILE the tree is present is a real drift
     * and still fails in declaredLimitIn().
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
