<?php

declare(strict_types=1);

// Fixtures for DomainKnowsNoHttpRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixtures {
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

    final class ThrowsHttp
    {
        public function fail(): never
        {
            throw new NotFoundHttpException();
        }

        public function statusText(): string
        {
            return Response::$statusTexts[404];
        }
    }
}

namespace App\Service\Fixtures\Exception {
    use App\Http\Problem\ApiProblem;
    use Symfony\Component\HttpFoundation\Response;

    final class KnowsItsStatus extends \RuntimeException
    {
        public const int STATUS = Response::HTTP_CONFLICT;

        public function problem(): ?ApiProblem
        {
            return null;
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
    }
}

namespace App\Http\Fixtures {
    use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

    final class HttpLayer
    {
        public function fail(): never
        {
            throw new NotFoundHttpException();
        }
    }
}
