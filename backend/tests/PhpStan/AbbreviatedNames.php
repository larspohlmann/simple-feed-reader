<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/** The names AbbreviatedNameRule rejects (CLAUDE.md "Names reveal intent", #1172). */
final class AbbreviatedNames
{
    private const array TRUNCATIONS = [
        'args', 'arr', 'attr', 'attrs', 'btn', 'cfg', 'cnt', 'conf', 'ctx', 'cur', 'curr', 'dir', 'doc', 'dom', 'ec',
        'el', 'elem', 'em', 'fav', 'favs', 'idx', 'len', 'msg', 'ns', 'num', 'obj', 'params', 'pos', 'prefs', 'prev',
        'ref', 'repo', 'req', 'res', 'resp', 'st', 'str', 'sub', 'subs', 'svc', 'ts', 'val',
    ];

    private const array VAGUE = ['data', 'info', 'tmp', 'temp'];

    /** Coordinates keep their mathematical names: pixels, elliptic-curve points. */
    private const array COORDINATES = ['x', 'y'];

    public static function isAbbreviated(string $name): bool
    {
        if (\in_array($name, [...self::TRUNCATIONS, ...self::VAGUE], true)) {
            return true;
        }

        return 1 === preg_match('/^[a-z_]\d*$/', $name) && !\in_array($name, self::COORDINATES, true);
    }

    private function __construct()
    {
    }
}
