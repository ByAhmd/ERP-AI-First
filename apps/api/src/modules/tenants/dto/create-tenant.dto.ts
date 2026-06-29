import { IsOptional, IsString, MinLength } from 'class-validator';

export class CreateTenantDto {
  @IsString()
  @MinLength(2)
  name!: string;

  @IsOptional()
  @IsString()
  commercialRegNo?: string;

  @IsOptional()
  @IsString()
  vatRegistrationNo?: string;
}
