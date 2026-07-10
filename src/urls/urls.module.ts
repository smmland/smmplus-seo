import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { SitemapModule } from '../sitemap/sitemap.module';
import { UrlsController } from './urls.controller';
import { UrlsService } from './urls.service';

@Module({
  imports: [AuthModule, SitemapModule],
  controllers: [UrlsController],
  providers: [UrlsService],
})
export class UrlsModule {}
