import { IsArray, IsDateString, IsNumber, IsString, IsOptional, ValidateNested } from 'class-validator';
import { Type } from 'class-transformer';

export class StatementTransactionDto {
  @IsDateString()
  date: string;

  @IsString()
  description: string;

  @IsNumber()
  amount: number;

  @IsOptional()
  @IsString()
  reference?: string;
}

export class UploadStatementDto {
  @IsString()
  accountId: string;

  @IsDateString()
  statementDate: string;

  @IsNumber()
  openingBalance: number;

  @IsNumber()
  closingBalance: number;

  @IsArray()
  @ValidateNested({ each: true })
  @Type(() => StatementTransactionDto)
  transactions: StatementTransactionDto[];
}

export class AutoMatchDto {
  @IsOptional()
  @IsNumber()
  toleranceDays?: number;
}
