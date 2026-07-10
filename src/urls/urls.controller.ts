import { Body, Controller, Delete, Get, Param, Patch, Post, Query, UseGuards } from '@nestjs/common';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { BulkVisibilityDto } from './dto/bulk-visibility.dto';
import { CreateUrlDto } from './dto/create-url.dto';
import { QueryUrlsDto } from './dto/query-urls.dto';
import { UpdateUrlDto } from './dto/update-url.dto';
import { UrlsService } from './urls.service';

@UseGuards(JwtAuthGuard)
@Controller('urls')
export class UrlsController {
  constructor(private readonly urls: UrlsService) {}

  @Get()
  list(@Query() query: QueryUrlsDto) {
    return this.urls.list(query);
  }

  @Post()
  create(@Body() dto: CreateUrlDto) {
    return this.urls.create(dto);
  }

  @Patch('bulk-visibility')
  bulkVisibility(@Body() dto: BulkVisibilityDto) {
    return this.urls.bulkVisibility(dto);
  }

  @Patch(':id')
  update(@Param('id') id: string, @Body() dto: UpdateUrlDto) {
    return this.urls.update(id, dto);
  }

  @Delete(':id')
  remove(@Param('id') id: string) {
    return this.urls.remove(id);
  }
}
