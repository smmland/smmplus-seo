import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { SettingsModule } from '../settings/settings.module';
import { SitemapController } from './sitemap.controller';
import { SitemapFetcherService } from './sitemap-fetcher.service';
import { SitemapGeneratorService } from './sitemap-generator.service';
import { SyncController } from './sync.controller';
import { SyncScheduler } from './sync.scheduler';
import { SyncService } from './sync.service';
import { UrlClassifierService } from './url-classifier.service';

@Module({
  imports: [AuthModule, SettingsModule],
  controllers: [SitemapController, SyncController],
  providers: [SitemapFetcherService, UrlClassifierService, SyncService, SitemapGeneratorService, SyncScheduler],
  exports: [SitemapGeneratorService, SyncService, UrlClassifierService],
})
export class SitemapModule {}
