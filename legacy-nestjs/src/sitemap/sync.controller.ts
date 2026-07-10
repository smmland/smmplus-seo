import { Controller, Get, Post, Query, UseGuards } from '@nestjs/common';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { PrismaService } from '../prisma/prisma.service';
import { SitemapGeneratorService } from './sitemap-generator.service';
import { SyncService } from './sync.service';

@UseGuards(JwtAuthGuard)
@Controller('sync')
export class SyncController {
  constructor(
    private readonly sync: SyncService,
    private readonly generator: SitemapGeneratorService,
    private readonly prisma: PrismaService,
  ) {}

  @Post('run')
  async run() {
    const result = await this.sync.runSync();
    await this.generator.generate();
    return result;
  }

  @Get('runs')
  async history(@Query('limit') limit?: string) {
    const take = Math.min(Number(limit) || 20, 100);
    return this.prisma.syncRun.findMany({ orderBy: { startedAt: 'desc' }, take });
  }

  @Get('status')
  status() {
    return {
      running: this.sync.isRunning(),
      lastGeneratedAt: this.generator.getLastGeneratedAt(),
    };
  }
}
