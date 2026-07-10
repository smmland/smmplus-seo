<?php

namespace App\Services;

/**
 * Turns a raw sitemap <loc> into a categorized record, purely from its URL shape.
 * We have no CMS/API access to smm.plus, so the URL path is the only signal available.
 */
class UrlClassifierService
{
    private const DEFAULT_LANG = 'en';

    // Slugs that carry no SEO value and default to hidden until someone opts in from the panel.
    private const UTILITY_SLUGS = ['signup', 'resetpassword', 'user-levels'];

    // Informational / legal pages, grouped separately from marketing landing pages.
    private const STATIC_SLUGS = ['about', 'contact', 'faq', 'privacy-policy', 'terms-of-service', 'services'];

    /**
     * @return array{path:string,lang:string,pattern_type:string,slug:string,group_key:string,default_hidden:bool}
     */
    public function classify(string $rawUrl): array
    {
        $path = parse_url($rawUrl, PHP_URL_PATH) ?: '/';
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));

        $lang = self::DEFAULT_LANG;
        if (count($segments) > 0 && preg_match('/^[a-z]{2}$/', $segments[0])) {
            $lang = array_shift($segments);
        }

        // "/" or "/ar" -> home page for that locale
        if (count($segments) === 0) {
            return $this->build($path, $lang, 'HOME', '', 'home', false);
        }

        // "/blog" or "/ar/blog" -> blog index
        if (count($segments) === 1 && $segments[0] === 'blog') {
            return $this->build($path, $lang, 'BLOG', '__index__', 'blog:__index__', false);
        }

        // "/blog/<slug>" or "/ar/blog/<slug>" -> blog post
        if (count($segments) === 2 && $segments[0] === 'blog') {
            $slug = $segments[1];

            return $this->build($path, $lang, 'BLOG', $slug, "blog:{$slug}", false);
        }

        // Single-segment routes: static/legal, utility (app-only), or marketing landing pages
        if (count($segments) === 1) {
            $slug = $segments[0];

            if (in_array($slug, self::UTILITY_SLUGS, true)) {
                return $this->build($path, $lang, 'UTILITY', $slug, "utility:{$slug}", true);
            }

            if (in_array($slug, self::STATIC_SLUGS, true)) {
                return $this->build($path, $lang, 'STATIC', $slug, "static:{$slug}", false);
            }

            return $this->build($path, $lang, 'LANDING', $slug, "landing:{$slug}", false);
        }

        // Anything deeper/unexpected: keep it, but flag as OTHER so it surfaces for manual review in the panel.
        $slug = implode('/', $segments);

        return $this->build($path, $lang, 'OTHER', $slug, "other:{$slug}", false);
    }

    private function build(string $path, string $lang, string $patternType, string $slug, string $groupKey, bool $defaultHidden): array
    {
        return [
            'path' => $path,
            'lang' => $lang,
            'pattern_type' => $patternType,
            'slug' => $slug,
            'group_key' => $groupKey,
            'default_hidden' => $defaultHidden,
        ];
    }
}
