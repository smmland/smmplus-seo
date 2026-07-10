<?php

/**
 * Some CloudLinux "alt-php" builds ship mbstring compiled without the Oniguruma
 * regex component, so the base mb_* functions exist but mb_ereg()/mb_split() don't -
 * even though cPanel reports "mbstring" as enabled (it's not an on/off toggle we
 * can fix from the panel; the extension binary itself is missing the symbol).
 *
 * Laravel's Str::studly()/headline()/etc. call mb_split('\s+', $value) internally,
 * so without this the app fails to boot on those hosts. Every real call site in the
 * framework only ever splits on a plain whitespace pattern, so a preg_split-based
 * shim covers the framework's actual usage; it's not a general mb_split replacement.
 */
if (! function_exists('mb_split')) {
    function mb_split(string $pattern, string $string, int $limit = -1): array|false
    {
        $delimited = '/'.str_replace('/', '\/', $pattern).'/u';

        $result = @preg_split($delimited, $string, $limit < 0 ? -1 : $limit);

        return $result === false ? [$string] : $result;
    }
}
