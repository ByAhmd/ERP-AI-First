import { AccountType } from '@prisma/client';
import { IsEnum, IsOptional, IsString, IsUUID, MinLength } from 'class-validator';

export class CreateChartOfAccountDto {
  @IsUUID()
  tenantId!: string;

  @IsString()
  @MinLength(1)
  code!: string;

  @IsString()
  @MinLength(2)
  name!: string;

  @IsEnum(AccountType)
  type!: AccountType;

  @IsOptional()
  @IsUUID()
  parentId?: string;
}
