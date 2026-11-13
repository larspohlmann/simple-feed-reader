<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\DigestSettingsController;
use App\Controller\Api\MeController;
use App\Controller\Api\ReaderPreferencesController;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\Routing\RouterInterface;

final class MeRoutesTest extends ApiTestCase
{
    private const array ROUTES = [
        'api_me' => ['GET', '/api/me', MeController::class . '::show'],
        'api_me_update_locale' => ['PATCH', '/api/me', MeController::class . '::updateLocale'],
        'api_me_delete' => ['DELETE', '/api/me', MeController::class . '::delete'],
        'api_me_resend_verification' => [
            'POST',
            '/api/me/resend-verification',
            MeController::class . '::resendVerification',
        ],
        'api_me_update_digest' => ['PATCH', '/api/me/digest', DigestSettingsController::class . '::updateDigest'],
        'api_me_digest_test' => ['POST', '/api/me/digest/test', DigestSettingsController::class . '::sendTestDigest'],
        'api_me_update_preferences' => [
            'PATCH',
            '/api/me/preferences',
            ReaderPreferencesController::class . '::updatePreferences',
        ],
        'api_me_update_magazine_style' => [
            'PATCH',
            '/api/me/magazine-style',
            ReaderPreferencesController::class . '::updateMagazineStyle',
        ],
        'api_me_update_handedness' => [
            'PATCH',
            '/api/me/handedness',
            ReaderPreferencesController::class . '::updateHandedness',
        ],
        'api_me_update_sharing' => [
            'PATCH',
            '/api/me/sharing',
            ReaderPreferencesController::class . '::updateSharing',
        ],
        'api_me_update_trending' => [
            'PATCH',
            '/api/me/trending',
            ReaderPreferencesController::class . '::updateTrending',
        ],
        'api_me_update_feed_directory_scope' => [
            'PATCH',
            '/api/me/feed-directory-scope',
            ReaderPreferencesController::class . '::updateFeedDirectoryScope',
        ],
    ];

    public function testEveryAccountRouteKeepsItsNameMethodAndPathOnTheControllerOfItsConcern(): void
    {
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection();

        $actual = [];
        foreach (array_keys(self::ROUTES) as $name) {
            $route = $routes->get($name);
            $actual[$name] = $route === null
                ? null
                : [implode('|', $route->getMethods()), $route->getPath(), $route->getDefault('_controller')];
        }

        self::assertSame(self::ROUTES, $actual);
    }
}
