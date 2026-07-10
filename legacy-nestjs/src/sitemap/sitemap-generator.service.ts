import { Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { UrlPatternType, Url } from '@prisma/client';
import { XMLBuilder } from 'fast-xml-parser';
import { PrismaService } from '../prisma/prisma.service';

const SITEMAP_XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
const XHTML_XMLNS = 'http://www.w3.org/1999/xhtml';

export type SitemapCategory = 'pages' | 'blog' | 'landing' | 'other';

const CATEGORY_BY_PATTERN: Record<UrlPatternType, SitemapCategory> = {
  HOME: 'pages',
  STATIC: 'pages',
  BLOG: 'blog',
  LANDING: 'landing',
  UTILITY: 'other',
  OTHER: 'other',
};

export const SITEMAP_CATEGORIES: SitemapCategory[] = ['pages', 'blog', 'landing', 'other'];

interface GeneratedSet {
  index: string;
  categories: Record<SitemapCategory, string>;
  generatedAt: Date;
}

@Injectable()
export class SitemapGeneratorService {
  private readonly logger = new Logger(SitemapGeneratorService.name);
  private readonly builder = new XMLBuilder({
    ignoreAttributes: false,
    attributeNamePrefix: '@_',
    format: true,
    suppressEmptyNode: true,
  });

  private cache: GeneratedSet | null = null;

  constructor(
    private readonly prisma: PrismaService,
    private readonly config: ConfigService,
  ) {}

  async generate(): Promise<GeneratedSet> {
    const urls = await this.prisma.url.findMany({
      where: { isActive: true, isHidden: false },
    });

    const byGroupKey = new Map<string, Url[]>();
    for (const url of urls) {
      const bucket = byGroupKey.get(url.groupKey) ?? [];
      bucket.push(url);
      byGroupKey.set(url.groupKey, bucket);
    }

    const byCategory = new Map<SitemapCategory, Url[]>();
    for (const category of SITEMAP_CATEGORIES) byCategory.set(category, []);
    for (const url of urls) {
      byCategory.get(CATEGORY_BY_PATTERN[url.patternType])!.push(url);
    }

    const categories = {} as Record<SitemapCategory, string>;
    const categoryLastmod = {} as Record<SitemapCategory, Date | null>;

    for (const category of SITEMAP_CATEGORIES) {
      const list = byCategory.get(category)!;
      categories[category] = this.buildUrlsetXml(list, byGroupKey);
      categoryLastmod[category] = list.reduce<Date | null>((max, u) => {
        if (!u.sourceLastmod) return max;
        return !max || u.sourceLastmod > max ? u.sourceLastmod : max;
      }, null);
    }

    const index = this.buildIndexXml(categoryLastmod);

    this.cache = { index, categories, generatedAt: new Date() };
    this.logger.log(`Generated sitemap set: ${urls.length} visible URLs across ${SITEMAP_CATEGORIES.length} categories`);
    return this.cache;
  }

  async getIndexXml(): Promise<string> {
    if (!this.cache) await this.generate();
    return this.cache!.index;
  }

  async getCategoryXml(category: SitemapCategory): Promise<string | null> {
    if (!this.cache) await this.generate();
    return this.cache!.categories[category] ?? null;
  }

  getLastGeneratedAt(): Date | null {
    return this.cache?.generatedAt ?? null;
  }

  private buildUrlsetXml(list: Url[], byGroupKey: Map<string, Url[]>): string {
    const urlNodes = list.map((u) => {
      const alternates = (byGroupKey.get(u.groupKey) ?? []).filter((alt) => alt.sourceUrl !== u.sourceUrl || alt.lang !== u.lang);

      const node: Record<string, unknown> = {
        loc: u.sourceUrl,
      };
      if (u.sourceLastmod) node.lastmod = u.sourceLastmod.toISOString();
      if (u.changefreq) node.changefreq = u.changefreq;
      if (u.priority !== null && u.priority !== undefined) node.priority = u.priority;

      const links = [{ '@_rel': 'alternate', '@_hreflang': u.lang, '@_href': u.sourceUrl }];
      for (const alt of alternates) {
        links.push({ '@_rel': 'alternate', '@_hreflang': alt.lang, '@_href': alt.sourceUrl });
      }
      if (links.length > 1) {
        node['xhtml:link'] = links;
      }

      return node;
    });

    const doc = {
      '?xml': { '@_version': '1.0', '@_encoding': 'UTF-8' },
      urlset: {
        '@_xmlns': SITEMAP_XMLNS,
        '@_xmlns:xhtml': XHTML_XMLNS,
        url: urlNodes,
      },
    };

    return this.builder.build(doc);
  }

  private buildIndexXml(categoryLastmod: Record<SitemapCategory, Date | null>): string {
    const base = this.config.get<string>('PUBLIC_BASE_URL', '');
    const sitemapNodes = SITEMAP_CATEGORIES.map((category) => {
      const node: Record<string, unknown> = { loc: `${base}/sitemap-${category}.xml` };
      const lastmod = categoryLastmod[category];
      if (lastmod) node.lastmod = lastmod.toISOString();
      return node;
    });

    const doc = {
      '?xml': { '@_version': '1.0', '@_encoding': 'UTF-8' },
      sitemapindex: {
        '@_xmlns': SITEMAP_XMLNS,
        sitemap: sitemapNodes,
      },
    };

    return this.builder.build(doc);
  }
}
