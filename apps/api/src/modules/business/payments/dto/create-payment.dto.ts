import { IsDateString, IsEnum, IsNumber, IsOptional, IsString, IsUUID, Min, ValidateNested, IsArray } from 'class-validator';
import { Type } from 'class-transformer';
import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { PaymentType } from '@prisma/client';

export class PaymentAllocationDto {
  @ApiProperty()
  @IsUUID()
  invoiceId: string;

  @ApiProperty()
  @IsNumber()
  @Min(0.01)
  amount: number;
}

export class CreatePaymentDto {
  @ApiProperty({ enum: PaymentType })
  @IsEnum(PaymentType)
  type: PaymentType;

  @ApiProperty()
  @IsUUID()
  contactId: string;

  @ApiProperty()
  @IsDateString()
  paymentDate: string;

  @ApiProperty()
  @IsNumber()
  @Min(0.01)
  amount: number;

  @ApiProperty()
  @IsUUID()
  accountId: string; // The bank/cash account receiving or sending the money

  @ApiPropertyOptional()
  @IsUUID()
  @IsOptional()
  currencyId?: string;

  @ApiPropertyOptional()
  @IsNumber()
  @Min(0)
  @IsOptional()
  exchangeRate?: number;

  @ApiPropertyOptional()
  @IsString()
  @IsOptional()
  reference?: string;

  @ApiPropertyOptional()
  @IsString()
  @IsOptional()
  notes?: string;

  @ApiPropertyOptional({ type: [PaymentAllocationDto] })
  @IsArray()
  @ValidateNested({ each: true })
  @Type(() => PaymentAllocationDto)
  @IsOptional()
  allocations?: PaymentAllocationDto[];

  @ApiPropertyOptional()
  @IsNumber()
  @Min(0)
  @IsOptional()
  whtAmount?: number;

  @ApiPropertyOptional()
  @IsUUID()
  @IsOptional()
  whtAccountId?: string;
}
