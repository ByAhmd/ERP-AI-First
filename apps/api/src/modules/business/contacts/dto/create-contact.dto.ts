import { IsEmail, IsEnum, IsOptional, IsString, IsUUID } from 'class-validator';
import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';

// We must manually define this enum since we can't import Prisma types directly into DTOs sometimes if they cause circular deps,
// but actually, we can import from Prisma.
import { ContactType } from '@prisma/client';

export class CreateContactDto {
  @ApiProperty({ enum: ContactType })
  @IsEnum(ContactType)
  type: ContactType;

  @ApiProperty()
  @IsString()
  name: string;

  @ApiPropertyOptional()
  @IsEmail()
  @IsOptional()
  email?: string;

  @ApiPropertyOptional()
  @IsString()
  @IsOptional()
  phone?: string;

  @ApiPropertyOptional()
  @IsString()
  @IsOptional()
  commercialRegNo?: string;

  @ApiPropertyOptional()
  @IsString()
  @IsOptional()
  vatRegistrationNo?: string;

  @ApiPropertyOptional()
  @IsString()
  @IsOptional()
  billingAddress?: string;

  @ApiPropertyOptional()
  @IsString()
  @IsOptional()
  shippingAddress?: string;

  @ApiPropertyOptional()
  @IsUUID()
  @IsOptional()
  receivableAccountId?: string;

  @ApiPropertyOptional()
  @IsUUID()
  @IsOptional()
  payableAccountId?: string;

  // Employee Specific Fields
  @ApiPropertyOptional()
  @IsString()
  @IsOptional()
  basicSalary?: string;

  @ApiPropertyOptional()
  @IsString()
  @IsOptional()
  housingAllowance?: string;

  @ApiPropertyOptional()
  @IsString()
  @IsOptional()
  transportAllowance?: string;

  @ApiPropertyOptional()
  @IsString()
  @IsOptional()
  gosiNumber?: string;
}
