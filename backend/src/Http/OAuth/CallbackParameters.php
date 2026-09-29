<?php

declare(strict_types=1);

namespace App\Http\OAuth;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads OAuth callback parameters from a request, and from nowhere the provider
 * did not put them.
 */
final class CallbackParameters
{
    /** Never Request::get(), which also reads route attributes such as `{provider}`. A blank value counts as absent. */
    public static function read(Request $request, string $name): ?string
    {
        $value = $request->query->get($name) ?? $request->request->get($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
