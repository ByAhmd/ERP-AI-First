import { IsOptional, IsString } from 'class-validator';

export class UpdateTenantDto {
  @IsOptional()
  @IsString()
  name?: string;

  @IsOptional()
  @IsString()
  commercialRegNo?: string;

  @IsOptional()
  @IsString()
  vatRegistrationNo?: string;

  @IsOptional()
  @IsString()
  currency?: string;
}
