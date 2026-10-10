<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\ShareLinkFeed\GitHubRepositoryFeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitHubRepositoryFeedTest extends TestCase
{
    #[DataProvider('repositoryLinks')]
    public function testResolvesARepositoryLinkToItsReleasesFeed(string $enteredUrl, string $feedUrl): void
    {
        self::assertSame($feedUrl, (new GitHubRepositoryFeed())->feedUrl($enteredUrl));
    }

    /** @return iterable<string, array{string, string}> */
    public static function repositoryLinks(): iterable
    {
        $symfony = 'https://github.com/symfony/symfony/releases.atom';

        yield 'the repository page' => ['https://github.com/symfony/symfony', $symfony];
        yield 'a trailing slash' => ['https://github.com/symfony/symfony/', $symfony];
        yield 'the www host' => ['https://www.github.com/symfony/symfony', $symfony];
        yield 'a mixed-case host' => ['https://GitHub.com/symfony/symfony', $symfony];
        yield 'a plain http link' => ['http://github.com/symfony/symfony', $symfony];
        yield 'a file in the tree' => ['https://github.com/symfony/symfony/tree/7.4/src/Symfony', $symfony];
        yield 'the releases page' => ['https://github.com/symfony/symfony/releases', $symfony];
        yield 'an issue' => ['https://github.com/symfony/symfony/issues/12', $symfony];
        yield 'a clone address' => ['https://github.com/symfony/symfony.git', $symfony];
        yield 'a query and a fragment' => ['https://github.com/symfony/symfony?tab=readme#install', $symfony];
        yield 'a repository name with dots' => [
            'https://github.com/jquery/jquery.com',
            'https://github.com/jquery/jquery.com/releases.atom',
        ];
        yield 'a repository name starting with a dot' => [
            'https://github.com/symfony/.github',
            'https://github.com/symfony/.github/releases.atom',
        ];
        yield 'an owner with a hyphen keeps its case' => [
            'https://github.com/Lars-Pohlmann/My_Repo',
            'https://github.com/Lars-Pohlmann/My_Repo/releases.atom',
        ];
    }

    #[DataProvider('otherLinks')]
    public function testLeavesEverythingElseAlone(string $enteredUrl): void
    {
        self::assertNull((new GitHubRepositoryFeed())->feedUrl($enteredUrl));
    }

    /** @return iterable<string, array{string}> */
    public static function otherLinks(): iterable
    {
        yield 'a user or org page' => ['https://github.com/symfony'];
        yield 'the GitHub home page' => ['https://github.com/'];
        yield 'the host without a path' => ['https://github.com'];
        yield 'an org route' => ['https://github.com/orgs/symfony/repositories'];
        yield 'a mixed-case org route' => ['https://github.com/Orgs/symfony/repositories'];
        yield 'a user route' => ['https://github.com/users/symfony/projects'];
        yield 'the settings' => ['https://github.com/settings/profile'];
        yield 'a topic' => ['https://github.com/topics/php'];
        yield 'the marketplace' => ['https://github.com/marketplace/actions'];
        yield 'a sponsors page' => ['https://github.com/sponsors/symfony'];
        yield 'a releases feed already' => ['https://github.com/symfony/symfony/releases.atom'];
        yield 'a commits feed already' => ['https://github.com/symfony/symfony/commits/7.4.atom'];
        yield 'a parent-directory segment' => ['https://github.com/symfony/..'];
        yield 'a current-directory segment' => ['https://github.com/symfony/./x'];
        yield 'an owner starting with a hyphen' => ['https://github.com/-symfony/symfony'];
        yield 'a gist' => ['https://gist.github.com/symfony/abc123'];
        yield 'raw content' => ['https://raw.githubusercontent.com/symfony/symfony/7.4/README.md'];
        yield 'a look-alike host' => ['https://github.com.evil.example/symfony/symfony'];
        yield 'another host' => ['https://gitlab.com/symfony/symfony'];
        yield 'text that is not a URL' => ['not a url'];
    }
}
