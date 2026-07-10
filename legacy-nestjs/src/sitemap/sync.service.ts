import { Injectable, Logger } from '@nestjs/common';
import { PrismaService } from '../prisma/prisma.service';
import { SettingsService } from '../settings/settings.service';
import { SitemapFetcherService } from './sitemap-fetcher.service';
import { UrlClassifierService } from './url-classifier.service';
import { SyncStatus } from '@prisma/client';

export interface SyncResult {
  syncRunId: string;
  totalFetched: number;
  added: number;
  updated: number;
  removed: number;
}

@Injectable()
export class SyncService {
  private readonly logger = new Logger(SyncService.name);
  private running = false;

  constructor(
    private readonly prisma: PrismaService,
    private readonly fetcher: SitemapFetcherService,
    private readonly classifier: UrlClassifierService,
    private readonly settings: SettingsService,
  ) {}

  isRunning(): boolean {
    return this.running;
  }

  async runSync(): Promise<SyncResult> {
    if (this.running) {
      throw new Error('A sync is already in progress');
    }
    this.running = true;

    const syncRun = await this.prisma.syncRun.create({ data: { status: SyncStatus.RUNNING } });
    this.logger.log(`Sync run ${syncRun.id} started`);

    try {
      const sourceUrl = await this.settings.getSourceSitemapUrl();
      const fetched = await this.fetcher.fetchAll(sourceUrl);
      const seenSourceUrls = new Set<string>();
      let added = 0;
      let updated = 0;

      for (const entry of fetched) {
        seenSourceUrls.add(entry.loc);
        const classified = this.classifier.classify(entry.loc);
        const lastmod = entry.lastmod ? new Date(entry.lastmod) : null;

        const existing = await this.prisma.url.findUnique({ where: { sourceUrl: entry.loc } });

        if (!existing) {
          await this.prisma.url.create({
            data: {
              sourceUrl: entry.loc,
              path: classified.path,
              lang: classified.lang,
              patternType: classified.patternType,
              slug: classified.slug,
              groupKey: classified.groupKey,
              sourceLastmod: lastmod,
              isHidden: classified.defaultHidden,
              isActive: true,
            },
          });
          added++;
        } else {
          await this.prisma.url.update({
            where: { sourceUrl: entry.loc },
            data: {
              path: classified.path,
              lang: classified.lang,
              // Manually-recategorized URLs keep the admin's choice; only re-classify untouched ones.
              patternType: existing.isManual ? existing.patternType : classified.patternType,
              slug: classified.slug,
              groupKey: existing.isManual ? existing.groupKey : classified.groupKey,
              sourceLastmod: lastmod,
              isActive: true,
              lastSeenAt: new Date(),
            },
          });
          updated++;
        }
      }

      // Anything previously fetched but absent from this run has disappeared from the source sitemap.
      // We don't delete it (an admin may have opinions about it); we just flag it inactive.
      const removed = await this.prisma.url.updateMany({
        where: { isActive: true, sourceUrl: { notIn: Array.from(seenSourceUrls) } },
        data: { isActive: false },
      });

      await this.prisma.syncRun.update({
        where: { id: syncRun.id },
        data: {
          status: SyncStatus.SUCCESS,
          finishedAt: new Date(),
          totalFetched: fetched.length,
          added,
          updated,
          removed: removed.count,
        },
      });

      this.logger.log(
        `Sync run ${syncRun.id} finished: fetched=${fetched.length} added=${added} updated=${updated} removed=${removed.count}`,
      );

      return { syncRunId: syncRun.id, totalFetched: fetched.length, added, updated, removed: removed.count };
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      await this.prisma.syncRun.update({
        where: { id: syncRun.id },
        data: { status: SyncStatus.FAILED, finishedAt: new Date(), errorMessage: message },
      });
      this.logger.error(`Sync run ${syncRun.id} failed: ${message}`);
      throw error;
    } finally {
      this.running = false;
    }
  }
}
