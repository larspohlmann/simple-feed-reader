<?php

declare(strict_types=1);

function pathOf(string $class): string
{
    $relative = str_starts_with($class, 'App\\Tests\\')
        ? 'tests/' . substr($class, strlen('App\\Tests\\'))
        : 'src/' . substr($class, strlen('App\\'));

    return str_replace('\\', '/', $relative) . '.php';
}

function namespaceOf(string $class): string
{
    $separator = strrpos($class, '\\');

    return false === $separator ? '' : substr($class, 0, $separator);
}

function shortNameOf(string $class): string
{
    $separator = strrpos($class, '\\');

    return false === $separator ? $class : substr($class, $separator + 1);
}
