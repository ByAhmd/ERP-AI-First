import { Type } from 'class-transformer';
import { ArrayMinSize, IsArray, ValidateNested } from 'class-validator';
import { JournalEntryLineAmountDto } from './journal-entry-line-amount.dto';

export class ValidateJournalEntryBalanceDto {
  @IsArray()
  @ArrayMinSize(2)
  @ValidateNested({ each: true })
  @Type(() => JournalEntryLineAmountDto)
  lines!: JournalEntryLineAmountDto[];
}
