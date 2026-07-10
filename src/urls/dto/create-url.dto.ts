import { IsIn, IsOptional, IsString, IsUrl } from 'class-validator';
import { UrlPatternType } from '@prisma/client';

const PATTERN_TYPES: UrlPatternType[] = ['HOME', 'BLOG', 'LANDING', 'STATIC', 'UTILITY', 'OTHER'];

/** For URLs that exist on smm.plus but aren't in its sitemap yet (or won't ever be) and need to be added by hand. */
export class CreateUrlDto {
  @IsUrl({ require_tld: false })
  sourceUrl!: string;

  @IsIn(PATTERN_TYPES)
  patternType!: UrlPatternType;

  @IsOptional()
  @IsString()
  lang?: string;
}
