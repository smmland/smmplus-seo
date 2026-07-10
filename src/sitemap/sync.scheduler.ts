import { Injectable, Logger, OnApplicationBootstrap, OnModuleDestroy } from '@nestjs/common';
import { SchedulerRegistry } from '@nestjs/schedule';
import { SettingsService } from '../settings/settings.service';
import { SitemapGeneratorService } from './sitemap-generator.service';
import { SyncService } from './sync.service';

const TIMEOUT_NAME = 'sitemap-sync-cycle';
const STARTUP_DELAY_MS = 10_000;

/**
 * Self-rescheduling loop instead of a fixed @Cron: the interval lives in the Setting table
 * and can be changed from the panel at runtime without restarting the process. Each cycle
 * reads the current interval right before scheduling the next one, so changes apply from
 * the next cycle onward.
 */
@Injectable()
export class SyncScheduler implements OnApplicationBootstrap, OnModuleDestroy {
  private readonly logger = new Logger(SyncScheduler.name);

  constructor(
    private readonly sync: SyncService,
    private readonly generator: SitemapGeneratorService,
    private readonly settings: SettingsService,
    private readonly registry: SchedulerRegistry,
  ) {}

  onApplicationBootstrap() {
    this.scheduleNext(STARTUP_DELAY_MS);
  }

  onModuleDestroy() {
    if (this.registry.doesExist('timeout', TIMEOUT_NAME)) {
      this.registry.deleteTimeout(TIMEOUT_NAME);
    }
  }

  private scheduleNext(delayMs: number) {
    if (this.registry.doesExist('timeout', TIMEOUT_NAME)) {
      this.registry.deleteTimeout(TIMEOUT_NAME);
    }
    const timeout = setTimeout(() => this.runCycle(), delayMs);
    this.registry.addTimeout(TIMEOUT_NAME, timeout);
  }

  private async runCycle() {
    try {
      if (!this.sync.isRunning()) {
        await this.sync.runSync();
        await this.generator.generate();
      } else {
        this.logger.warn('Skipped scheduled sync: a sync is already running (likely triggered manually)');
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      this.logger.error(`Scheduled sync cycle failed: ${message}`);
    } finally {
      const hours = await this.settings.getSyncIntervalHours();
      this.scheduleNext(hours * 60 * 60 * 1000);
    }
  }
}
