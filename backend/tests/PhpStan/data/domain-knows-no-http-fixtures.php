<?php

declare(strict_types=1);

// Fixtures for DomainKnowsNoHttpRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixtures {
    use App\Http\RecommendationFeedJson;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

    final class KnowsHttp
    {
        public function fail(): never
        {
            throw new NotFoundHttpException();
        }

        public function statusText(): string
        {
            return Response::$statusTexts[404];
        }

        public function clientIp(Request $request): ?string
        {
            return $request->getClientIp();
        }

        public function mapper(): string
        {
            return RecommendationFeedJson::class;
        }

        public function mapperByName(): string
        {
            return 'App\Http\RecommendationFeedJson';
        }

        public function responseByName(): string
        {
            return '\Symfony\Component\HttpFoundation\Response';
        }
    }
}

namespace App\Service\Fixtures\Exception {
    use App\Http\Problem\ApiProblem;
    use Symfony\Component\HttpFoundation\Exception\BadRequestException;
    use Symfony\Component\Security\Core\Exception\AccessDeniedException;

    final class KnowsItsProblem extends \RuntimeException
    {
        public function problem(): ?ApiProblem
        {
            return null;
        }

        public function badRequest(): BadRequestException
        {
            return new BadRequestException();
        }

        public function denied(): AccessDeniedException
        {
            return new AccessDeniedException();
        }
    }
}

namespace App\Repository\Fixtures {
    final class ThrowsInlineHttp
    {
        public function fail(): never
        {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException();
        }

        public function cursor(): string
        {
            return \App\Http\EntryPage::class;
        }
    }
}

namespace App\Pagination\Fixtures {
    use Symfony\Component\HttpFoundation\Cookie;

    final class BakesCookies
    {
        public function cookie(): Cookie
        {
            return Cookie::create('name');
        }
    }
}

namespace App\Http\Fixtures {
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

    final class HttpLayer
    {
        public function fail(): never
        {
            throw new NotFoundHttpException('App\Http\Anything');
        }

        public function status(): int
        {
            return Response::HTTP_OK;
        }
    }
}

namespace App\Repository\Fixtures\Clean {
    final class NamesNoHttp
    {
        public function label(): string
        {
            return 'App\HttpClientSettings is not the HTTP layer';
        }
    }
}
