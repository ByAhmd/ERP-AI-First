import { IsString, IsNumber, IsEnum, IsOptional, IsDateString } from 'class-validator';
import { ItemType } from '@prisma/client';

export class CreateItemDto {
  @IsEnum(ItemType)
  type: ItemType;

  @IsString()
  code: string;

  @IsString()
  name: string;

  @IsOptional()
  @IsString()
  description?: string;

  @IsOptional()
  @IsString()
  inventoryAccountId?: string;

  @IsOptional()
  @IsString()
  cogsAccountId?: string;

  @IsOptional()
  @IsString()
  revenueAccountId?: string;
}

export class CreateWarehouseDto {
  @IsString()
  code: string;

  @IsString()
  name: string;

  @IsOptional()
  @IsString()
  location?: string;
}

export class TransferInventoryDto {
  @IsString()
  itemId: string;

  @IsString()
  fromWarehouseId: string;

  @IsString()
  toWarehouseId: string;

  @IsNumber()
  quantity: number;

  @IsDateString()
  date: string;
}
