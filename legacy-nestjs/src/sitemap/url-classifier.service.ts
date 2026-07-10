import { Injectable } from '@nestjs/common';
import { UrlPatternType } from '@prisma/client';

export interface ClassifiedUrl {
  path: string;
  lang: string;
  patternType: UrlPatternType;
  slug: string;
  groupKey: string;
  defaultHidden: boolean;
}

const LANG_SEGMENT = /^[a-z]{2}$/;
const DEFAULT_LANG = 'en';

// Slugs that carry no SEO value and default to hidden until someone opts in from the panel.
const UTILITY_SLUGS = new Set(['signup', 'resetpassword', 'user-levels']);

// Informational / legal pages, grouped separately from marketing landing pages.
const STATIC_SLUGS = new Set(['about', 'contact', 'faq', 'privacy-policy', 'terms-of-service', 'services']);

/**
 * Turns a raw sitemap <loc> into a categorized record, purely from its URL shape.
 * We have no CMS/API access to smm.plus, so the URL path is the only signal available.
 */
@Injectable()
export class UrlClassifierService {
  classify(rawUrl: string): ClassifiedUrl {
    const url = new URL(rawUrl);
    const segments = url.pathname.split('/').filter(Boolean);

    let lang = DEFAULT_LANG;
    if (segments.length > 0 && LANG_SEGMENT.test(segments[0])) {
      lang = segments.shift()!;
    }

    // "/" or "/ar" -> home page for that locale
    if (segments.length === 0) {
      return this.build(url.pathname, lang, UrlPatternType.HOME, '', 'home', false);
    }

    // "/blog" or "/ar/blog" -> blog index
    if (segments.length === 1 && segments[0] === 'blog') {
      return this.build(url.pathname, lang, UrlPatternType.BLOG, '__index__', 'blog:__index__', false);
    }

    // "/blog/<slug>" or "/ar/blog/<slug>" -> blog post
    if (segments.length === 2 && segments[0] === 'blog') {
      const slug = segments[1];
      return this.build(url.pathname, lang, UrlPatternType.BLOG, slug, `blog:${slug}`, false);
    }

    // Single-segment routes: static/legal, utility (app-only), or marketing landing pages
    if (segments.length === 1) {
      const slug = segments[0];

      if (UTILITY_SLUGS.has(slug)) {
        return this.build(url.pathname, lang, UrlPatternType.UTILITY, slug, `utility:${slug}`, true);
      }

      if (STATIC_SLUGS.has(slug)) {
        return this.build(url.pathname, lang, UrlPatternType.STATIC, slug, `static:${slug}`, false);
      }

      return this.build(url.pathname, lang, UrlPatternType.LANDING, slug, `landing:${slug}`, false);
    }

    // Anything deeper/unexpected: keep it, but flag as OTHER so it surfaces for manual review in the panel.
    const slug = segments.join('/');
    return this.build(url.pathname, lang, UrlPatternType.OTHER, slug, `other:${slug}`, false);
  }

  private build(
    path: string,
    lang: string,
    patternType: UrlPatternType,
    slug: string,
    groupKey: string,
    defaultHidden: boolean,
  ): ClassifiedUrl {
    return { path, lang, patternType, slug, groupKey, defaultHidden };
  }
}
