import { Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import axios from 'axios';
import { XMLParser } from 'fast-xml-parser';

export interface FetchedUrl {
  loc: string;
  lastmod?: string;
}

@Injectable()
export class SitemapFetcherService {
  private readonly logger = new Logger(SitemapFetcherService.name);
  private readonly parser = new XMLParser({ ignoreAttributes: false });

  constructor(private readonly config: ConfigService) {}

  /**
   * Fetches the source sitemap. Transparently follows a <sitemapindex> one level deep
   * so this keeps working if smm.plus later splits its sitemap into an index of files.
   */
  async fetchAll(sourceUrl?: string): Promise<FetchedUrl[]> {
    const target = sourceUrl ?? this.config.getOrThrow<string>('SOURCE_SITEMAP_URL');
    const xml = await this.download(target);
    const parsed = this.parser.parse(xml);

    if (parsed.sitemapindex) {
      const entries = this.toArray(parsed.sitemapindex.sitemap);
      const results: FetchedUrl[] = [];
      for (const entry of entries) {
        const childUrls = await this.fetchAll(entry.loc);
        results.push(...childUrls);
      }
      return results;
    }

    if (parsed.urlset) {
      const entries = this.toArray(parsed.urlset.url);
      return entries
        .filter((e) => !!e.loc)
        .map((e) => ({ loc: String(e.loc), lastmod: e.lastmod ? String(e.lastmod) : undefined }));
    }

    this.logger.warn(`Unexpected sitemap shape fetched from ${target}`);
    return [];
  }

  private async download(url: string): Promise<string> {
    const response = await axios.get<string>(url, {
      responseType: 'text',
      timeout: 30_000,
      headers: {
        'User-Agent': 'smmplus-seo-sync/1.0 (+https://seo.smm.plus)',
        Accept: 'application/xml,text/xml',
      },
    });
    return response.data;
  }

  private toArray<T>(value: T | T[] | undefined): T[] {
    if (!value) return [];
    return Array.isArray(value) ? value : [value];
  }
}
