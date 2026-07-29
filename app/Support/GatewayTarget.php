<?php

namespace App\Support;

class GatewayTarget
{
    /**
     * Collapses equivalent link formats (with/without protocol, @-prefix, trailing slash,
     * query string) down to one canonical key, so the same channel/profile can't be reused
     * to bypass the per-target rate limit under superficially different URLs.
     */
    public static function normalize(string $link): string
    {
        $v = trim(strtolower($link));
        $v = urldecode($v);
        $v = rtrim($v, '/');

        if (str_starts_with($v, '@')) {
            $v = substr($v, 1);
        }

        $v = preg_replace('#^https?://#', '', $v);
        $v = preg_replace('#^www\.#', '', $v);

        $v = explode('?', $v)[0];
        $v = explode('#', $v)[0];

        if (preg_match('#^(t\.me|telegram\.me)/([^/]+)#', $v, $m)) {
            return 'telegram:'.$m[2];
        }

        if (preg_match('#^(instagram\.com)/([^/]+)#', $v, $m)) {
            return 'instagram:'.$m[2];
        }

        return 'raw:'.$v;
    }
}
