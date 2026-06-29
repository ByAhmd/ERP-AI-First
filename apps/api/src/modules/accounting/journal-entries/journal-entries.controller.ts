import { Body, Controller, Post } from '@nestjs/common';
import { CreateDraftJournalEntryDto } from './dto/create-draft-journal-entry.dto';
import { ValidateJournalEntryBalanceDto } from './dto/validate-journal-entry-balance.dto';
import { JournalEntriesService } from './journal-entries.service';

@Controller('accounting/journal-entries')
export class JournalEntriesController {
  constructor(private readonly journalEntriesService: JournalEntriesService) {}

  @Post('drafts')
  createDraft(@Body() dto: CreateDraftJournalEntryDto) {
    return this.journalEntriesService.createDraft(dto);
  }

  @Post('validate-balance')
  validateBalance(@Body() dto: ValidateJournalEntryBalanceDto) {
    return this.journalEntriesService.validateBalancedLines(dto.lines);
  }
}
