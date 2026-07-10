import { Type } from 'class-transformer';
import { IsBoolean, IsIn, IsInt, IsOptional, IsString, Max, Min } from 'class-validator';
import { UrlPatternType } from '@prisma/client';

const PATTERN_TYPES: UrlPatternType[] = ['HOME', 'BLOG', 'LANDING', 'STATIC', 'UTILITY', 'OTHER'];

export class QueryUrlsDto {
  @IsOptional()
  @IsIn(PATTERN_TYPES)
  patternType?: UrlPatternType;

  @IsOptional()
  @IsString()
  lang?: string;

  @IsOptional()
  @Type(() => Boolean)
  @IsBoolean()
  hidden?: boolean;

  @IsOptional()
  @Type(() => Boolean)
  @IsBoolean()
  active?: boolean;

  @IsOptional()
  @IsString()
  search?: string;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  page?: number = 1;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  @Max(200)
  pageSize?: number = 50;
}
