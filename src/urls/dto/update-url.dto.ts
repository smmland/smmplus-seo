import { IsBoolean, IsIn, IsNumber, IsOptional, IsString, Max, Min } from 'class-validator';
import { UrlPatternType } from '@prisma/client';

const PATTERN_TYPES: UrlPatternType[] = ['HOME', 'BLOG', 'LANDING', 'STATIC', 'UTILITY', 'OTHER'];

export class UpdateUrlDto {
  @IsOptional()
  @IsBoolean()
  isHidden?: boolean;

  @IsOptional()
  @IsIn(PATTERN_TYPES)
  patternType?: UrlPatternType;

  @IsOptional()
  @IsNumber()
  @Min(0)
  @Max(1)
  priority?: number;

  @IsOptional()
  @IsString()
  changefreq?: string;
}
