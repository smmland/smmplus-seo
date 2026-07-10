import { Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { PrismaService } from '../prisma/prisma.service';

export const SETTINGS_KEYS = {
  SYNC_INTERVAL_HOURS: 'sync_interval_hours',
  SOURCE_SITEMAP_URL: 'source_sitemap_url',
} as const;

@Injectable()
export class SettingsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly config: ConfigService,
  ) {}

  async getSyncIntervalHours(): Promise<number> {
    const stored = await this.get(SETTINGS_KEYS.SYNC_INTERVAL_HOURS);
    if (stored) return Number(stored);
    return Number(this.config.get('DEFAULT_SYNC_INTERVAL_HOURS', 6));
  }

  async setSyncIntervalHours(hours: number): Promise<void> {
    if (!Number.isFinite(hours) || hours <= 0) {
      throw new Error('Sync interval must be a positive number of hours');
    }
    await this.set(SETTINGS_KEYS.SYNC_INTERVAL_HOURS, String(hours));
  }

  async getSourceSitemapUrl(): Promise<string> {
    const stored = await this.get(SETTINGS_KEYS.SOURCE_SITEMAP_URL);
    return stored ?? this.config.getOrThrow<string>('SOURCE_SITEMAP_URL');
  }

  async setSourceSitemapUrl(url: string): Promise<void> {
    await this.set(SETTINGS_KEYS.SOURCE_SITEMAP_URL, url);
  }

  private async get(key: string): Promise<string | null> {
    const row = await this.prisma.setting.findUnique({ where: { key } });
    return row?.value ?? null;
  }

  private async set(key: string, value: string): Promise<void> {
    await this.prisma.setting.upsert({
      where: { key },
      create: { key, value },
      update: { value },
    });
  }
}
