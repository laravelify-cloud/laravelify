<?php

namespace App\Support;

/**
 * Node.js versions a website may ask for: a major from the list (22), or any release from 14 on,
 * as a major, minor or exact version (19, 19.4, 19.0.0). A missing one is installed with n.
 */
class NodeVersion
{
    public const PATTERN = '/^(1[4-9]|2[0-9])(\.[0-9]{1,2}){0,2}$/';

    /**
     * @return list<string>
     */
    public static function rule(): array
    {
        return ['required', 'string', 'max:10', 'regex:'.self::PATTERN];
    }
}
