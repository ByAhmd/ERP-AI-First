import { IsString, IsNumber, IsDateString, IsEnum, IsOptional } from 'class-validator';
import { DepreciationMethod } from '@prisma/client';

export class CreateFixedAssetDto {
  @IsString()
  code: string;

  @IsString()
  name: string;

  @IsOptional()
  @IsString()
  description?: string;

  @IsString()
  assetAccountId: string;

  @IsString()
  depreciationAccountId: string;

  @IsString()
  expenseAccountId: string;

  @IsDateString()
  purchaseDate: string;

  @IsNumber()
  purchasePrice: number;

  @IsNumber()
  salvageValue: number;

  @IsNumber()
  usefulLifeMonths: number;

  @IsEnum(DepreciationMethod)
  depreciationMethod: DepreciationMethod;
}
