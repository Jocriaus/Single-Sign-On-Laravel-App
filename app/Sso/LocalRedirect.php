<?php

namespace App\Sso;

use Illuminate\Http\RedirectResponse;

class LocalRedirect
{
    /** Redirect to the saved intended URL if it points at this server, otherwise to $default. */
    public static function intended(string $default = '/'): RedirectResponse
    {
        $url = session()->pull('url.intended');

        return redirect(is_string($url) && self::isLocal($url) ? $url : $default);
    }

    private static function isLocal(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//') && ! str_starts_with($url, '/\\');
        }

        $parts = parse_url($url);
        $request = request();

        return $parts !== false
            && isset($parts['scheme'], $parts['host'])
            && strtolower($parts['scheme']) === $request->getScheme()
            && strtolower($parts['host']) === strtolower($request->getHost())
            && ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)) === (int) $request->getPort();
    }
}
