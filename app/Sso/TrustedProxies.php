<?php

namespace App\Sso;

class TrustedProxies
{
    /** "10.0.0.1, 10.0.0.0/8" becomes a list, "*" stays "*", and nothing listed means trust none (null). */
    public static function parse(string $setting): array|string|null
    {
        $list = array_values(array_filter(array_map('trim', explode(',', $setting)), fn ($p) => $p !== ''));

        if ($list === []) {
            return null;
        }

        return in_array('*', $list, true) ? '*' : $list;
    }
}
