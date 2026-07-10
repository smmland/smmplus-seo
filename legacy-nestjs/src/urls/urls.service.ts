import { BadRequestException, Injectable, NotFoundException } from '@nestjs/common';
import { Prisma } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { SitemapGeneratorService } from '../sitemap/sitemap-generator.service';
import { UrlClassifierService } from '../sitemap/url-classifier.service';
import { BulkVisibilityDto } from './dto/bulk-visibility.dto';
import { CreateUrlDto } from './dto/create-url.dto';
import { QueryUrlsDto } from './dto/query-urls.dto';
import { UpdateUrlDto } from './dto/update-url.dto';

@Injectable()
export class UrlsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly generator: SitemapGeneratorService,
    private readonly classifier: UrlClassifierService,
  ) {}

  async list(query: QueryUrlsDto) {
    const page = query.page ?? 1;
    const pageSize = query.pageSize ?? 50;

    const where: Prisma.UrlWhereInput = {
      patternType: query.patternType,
      lang: query.lang,
      isHidden: query.hidden,
      isActive: query.active,
      sourceUrl: query.search ? { contains: query.search, mode: 'insensitive' } : undefined,
    };

    const [items, total] = await Promise.all([
      this.prisma.url.findMany({
        where,
        orderBy: { sourceUrl: 'asc' },
        skip: (page - 1) * pageSize,
        take: pageSize,
      }),
      this.prisma.url.count({ where }),
    ]);

    return { items, total, page, pageSize };
  }

  async update(id: string, dto: UpdateUrlDto) {
    const existing = await this.prisma.url.findUnique({ where: { id } });
    if (!existing) throw new NotFoundException('URL not found');

    const url = await this.prisma.url.update({
      where: { id },
      data: {
        ...(dto.isHidden !== undefined ? { isHidden: dto.isHidden } : {}),
        ...(dto.patternType !== undefined ? { patternType: dto.patternType, isManual: true } : {}),
        ...(dto.priority !== undefined ? { priority: dto.priority } : {}),
        ...(dto.changefreq !== undefined ? { changefreq: dto.changefreq } : {}),
      },
    });

    await this.generator.generate();
    return url;
  }

  async bulkVisibility(dto: BulkVisibilityDto) {
    const where: Prisma.UrlWhereInput = {};
    if (dto.ids?.length) where.id = { in: dto.ids };
    if (dto.patternType) where.patternType = dto.patternType;
    if (dto.lang) where.lang = dto.lang;

    if (!dto.ids?.length && !dto.patternType && !dto.lang) {
      throw new BadRequestException('Provide ids, patternType, or lang to select which URLs to update');
    }

    const result = await this.prisma.url.updateMany({ where, data: { isHidden: dto.hidden } });
    await this.generator.generate();
    return { updated: result.count };
  }

  async create(dto: CreateUrlDto) {
    const classified = this.classifier.classify(dto.sourceUrl);
    const url = await this.prisma.url.create({
      data: {
        sourceUrl: dto.sourceUrl,
        path: classified.path,
        lang: dto.lang ?? classified.lang,
        patternType: dto.patternType,
        slug: classified.slug,
        groupKey: classified.groupKey,
        isManual: true,
        isActive: true,
      },
    });
    await this.generator.generate();
    return url;
  }

  async remove(id: string) {
    const existing = await this.prisma.url.findUnique({ where: { id } });
    if (!existing) throw new NotFoundException('URL not found');
    if (!existing.isManual && existing.isActive) {
      throw new BadRequestException('Only manually-added or inactive URLs can be deleted; hide it instead');
    }
    await this.prisma.url.delete({ where: { id } });
    await this.generator.generate();
    return { deleted: true };
  }
}
