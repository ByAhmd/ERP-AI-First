import { IsOptional, IsString, IsUUID, Matches } from 'class-validator';

const decimalAmountPattern = /^\d+(\.\d{1,2})?$/;

export class JournalEntryLineAmountDto {
  @IsUUID()
  accountId!: string;

  @IsOptional()
  @IsString()
  description?: string;

  @Matches(decimalAmountPattern)
  debit!: string;

  @Matches(decimalAmountPattern)
  credit!: string;
}
