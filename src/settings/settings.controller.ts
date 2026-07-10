import { Body, Controller, Get, Put, UseGuards } from '@nestjs/common';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { UpdateSettingsDto } from './dto/update-settings.dto';
import { SettingsService } from './settings.service';

@UseGuards(JwtAuthGuard)
@Controller('settings')
export class SettingsController {
  constructor(private readonly settings: SettingsService) {}

  @Get()
  async get() {
    return {
      syncIntervalHours: await this.settings.getSyncIntervalHours(),
      sourceSitemapUrl: await this.settings.getSourceSitemapUrl(),
    };
  }

  @Put()
  async update(@Body() dto: UpdateSettingsDto) {
    if (dto.syncIntervalHours !== undefined) {
      await this.settings.setSyncIntervalHours(dto.syncIntervalHours);
    }
    if (dto.sourceSitemapUrl !== undefined) {
      await this.settings.setSourceSitemapUrl(dto.sourceSitemapUrl);
    }
    return this.get();
  }
}
