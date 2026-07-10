import { IsInt, IsOptional, IsPositive, IsUrl } from 'class-validator';

export class UpdateSettingsDto {
  @IsOptional()
  @IsInt()
  @IsPositive()
  syncIntervalHours?: number;

  @IsOptional()
  @IsUrl({ require_tld: false })
  sourceSitemapUrl?: string;
}
