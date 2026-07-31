<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads OAuth callback parameters from a request, and from nowhere the provider
 * did not put them.
 */
final class CallbackParameters
{
    /**
     * Reads a callback parameter from the query string or the form body, and
     * from nowhere else.
     *
     * Explicitly NOT Request::get(): that also searches the request attributes,
     * which is where the router puts `{provider}` and `_route`. A callback
     * parameter must come from the provider, not from the routing table, and a
     * reader that can silently fall back to an attribute is one added route
     * placeholder away from surprising. Blank is treated as absent, so `?code=`
     * cannot pass a non-empty-string check by being a string.
     */
    public static function read(Request $request, string $name): ?string
    {
        $value = $request->query->get($name) ?? $request->request->get($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
