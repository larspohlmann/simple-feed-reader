<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Extra\CssInliner\CssInlinerExtension;
use Twig\Loader\FilesystemLoader;

/**
 * Builds a Twig environment for the digest templates the way the container wires
 * it: the email translation domain plus the CSS inliner. Keeps the renderer tests
 * hermetic and deterministic, without booting the kernel.
 */
final class DigestTwigEnvironment
{
    public static function withTranslator(TranslatorInterface $translator): Environment
    {
        $loader = new FilesystemLoader([\dirname(__DIR__, 2) . '/templates']);
        $environment = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
        $environment->addExtension(new TranslationExtension($translator));
        $environment->addExtension(new CssInlinerExtension());

        return $environment;
    }
}
