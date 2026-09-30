<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

final class AdminRouteGuardTest extends ApiTestCase
{
    private const string ADMIN_NAMESPACE = 'App\\Controller\\Admin\\';
    private const string ADMIN_PREFIX = '/api/admin/';

    public function testEveryAdminRouteSitsUnderTheAdminPrefix(): void
    {
        $outsideThePrefix = array_filter(
            array_map(static fn (Route $route): string => $route->getPath(), $this->adminRoutes()),
            static fn (string $path): bool => !str_starts_with($path, self::ADMIN_PREFIX),
        );

        self::assertSame([], $outsideThePrefix, 'Admin routes outside ' . self::ADMIN_PREFIX);
    }

    public function testEveryRouteUnderTheAdminPrefixIsAnAdminController(): void
    {
        $strays = array_filter(
            $this->router()->getRouteCollection()->all(),
            static fn (Route $route): bool => str_starts_with($route->getPath(), self::ADMIN_PREFIX)
                && !self::isAdminController($route),
        );

        self::assertSame(
            [],
            array_keys($strays),
            'Routes under ' . self::ADMIN_PREFIX . ' outside ' . self::ADMIN_NAMESPACE,
        );
    }

    public function testEveryAdminRouteRefusesANonAdmin(): void
    {
        $client = self::createClient();
        $member = $this->factory()->create('member@example.com');
        $authorization = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($member)];

        $statuses = [];
        foreach ($this->oneRequestPerMethod($member->requireId()) as [$method, $url]) {
            $client->request($method, $url, server: $authorization);
            $statuses[$method . ' ' . $url] = $client->getResponse()->getStatusCode();
        }

        self::assertSame(array_fill_keys(array_keys($statuses), Response::HTTP_FORBIDDEN), $statuses);
    }

    /** @return array<string, Route> */
    private function adminRoutes(): array
    {
        $adminRoutes = array_filter(
            $this->router()->getRouteCollection()->all(),
            self::isAdminController(...),
        );

        self::assertNotEmpty($adminRoutes, 'The admin route selector found no route.');

        return $adminRoutes;
    }

    /** @return list<array{string, string}> */
    private function oneRequestPerMethod(int $fixtureId): array
    {
        $router = $this->router();
        $requests = [];
        foreach ($this->adminRoutes() as $name => $route) {
            $url = $router->generate($name, self::everyPathVariableSetTo($route, $fixtureId));
            foreach ($route->getMethods() ?: ['GET'] as $method) {
                $requests[] = [$method, $url];
            }
        }

        return $requests;
    }

    /** @return array<string, int> */
    private static function everyPathVariableSetTo(Route $route, int $fixtureId): array
    {
        return array_fill_keys(array_filter($route->compile()->getPathVariables(), is_string(...)), $fixtureId);
    }

    private static function isAdminController(Route $route): bool
    {
        $controller = $route->getDefault('_controller');

        return \is_string($controller) && str_starts_with($controller, self::ADMIN_NAMESPACE);
    }

    private function router(): RouterInterface
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router;
    }

    private function tokenFor(User $user): string
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        return $manager->create($user);
    }
}
