import { Controller, Get, Header, NotFoundException, Param } from '@nestjs/common';
import { SITEMAP_CATEGORIES, SitemapCategory, SitemapGeneratorService } from './sitemap-generator.service';

function isValidCategory(value: string): value is SitemapCategory {
  return (SITEMAP_CATEGORIES as string[]).includes(value);
}

@Controller()
export class SitemapController {
  constructor(private readonly generator: SitemapGeneratorService) {}

  @Get('sitemap_index.xml')
  @Header('Content-Type', 'application/xml')
  async index() {
    return this.generator.getIndexXml();
  }

  @Get('sitemap-:category.xml')
  @Header('Content-Type', 'application/xml')
  async category(@Param('category') category: string) {
    if (!isValidCategory(category)) {
      throw new NotFoundException(`Unknown sitemap category "${category}"`);
    }
    const xml = await this.generator.getCategoryXml(category);
    if (!xml) throw new NotFoundException();
    return xml;
  }
}
