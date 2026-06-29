import { Type } from 'class-transformer';
import {
  ArrayMinSize,
  IsArray,
  IsDateString,
  IsOptional,
  IsString,
  IsUUID,
  MinLength,
  ValidateNested,
} from 'class-validator';
import { JournalEntryLineAmountDto } from './journal-entry-line-amount.dto';

export class CreateDraftJournalEntryDto {
  @IsUUID()
  tenantId!: string;

  @IsString()
  @MinLength(1)
  entryNumber!: string;

  @IsDateString()
  entryDate!: string;

  @IsOptional()
  @IsString()
  description?: string;

  @IsArray()
  @ArrayMinSize(2)
  @ValidateNested({ each: true })
  @Type(() => JournalEntryLineAmountDto)
  lines!: JournalEntryLineAmountDto[];
}
