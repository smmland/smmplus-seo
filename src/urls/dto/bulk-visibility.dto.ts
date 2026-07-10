import { IsArray, IsBoolean, IsIn, IsOptional, IsString } from 'class-validator';
import { UrlPatternType } from '@prisma/client';

const PATTERN_TYPES: UrlPatternType[] = ['HOME', 'BLOG', 'LANDING', 'STATIC', 'UTILITY', 'OTHER'];

/**
 * Exactly one filter (ids / patternType / lang) should be provided to select the target set;
 * `hidden` is then applied to all matching rows in one go.
 */
export class BulkVisibilityDto {
  @IsBoolean()
  hidden!: boolean;

  @IsOptional()
  @IsArray()
  @IsString({ each: true })
  ids?: string[];

  @IsOptional()
  @IsIn(PATTERN_TYPES)
  patternType?: UrlPatternType;

  @IsOptional()
  @IsString()
  lang?: string;
}
