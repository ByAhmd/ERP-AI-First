import { Controller, Post, Get, Body, Param, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiHeader, ApiOperation, ApiTags } from '@nestjs/swagger';
import { PERMISSIONS } from '@erp-ai/shared';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { CurrentUser, RequestUser } from '../../../common/decorators/current-user.decorator';
import { Auditable } from '../../../common/decorators/auditable.decorator';
import { JournalEntriesService, CreateJournalEntryDto } from './journal-entries.service';

@ApiTags('Journal Entries')
@ApiBearerAuth()
@ApiHeader({ name: 'x-tenant-id', required: true })
@UseGuards(JwtAuthGuard, PermissionsGuard)
@Controller('accounting/journal-entries')
export class JournalEntriesController {
  constructor(private readonly journalEntriesService: JournalEntriesService) {}

  @Post()
  @ApiOperation({ summary: 'Create and post a new journal entry' })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_JOURNAL_POST)
  @Auditable({ action: 'CREATE', entityType: 'JournalEntry' })
  create(@CurrentUser() user: RequestUser, @Body() dto: CreateJournalEntryDto) {
    // Ensuring date comes through as Date object
    dto.entryDate = new Date(dto.entryDate);
    return this.journalEntriesService.create(user.tenantId!, dto);
  }

  @Post(':id/reverse')
  @ApiOperation({ summary: 'Reverse a posted journal entry' })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_JOURNAL_POST)
  @Auditable({ action: 'REVERSE', entityType: 'JournalEntry' })
  createReversal(
    @CurrentUser() user: RequestUser, 
    @Param('id') id: string,
    @Body('reversalDate') reversalDate: string
  ) {
    return this.journalEntriesService.createReversal(user.tenantId!, id, new Date(reversalDate));
  }

  @Get()
  @ApiOperation({ summary: 'List all journal entries' })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_JOURNAL_READ)
  findAll(@CurrentUser() user: RequestUser) {
    return this.journalEntriesService.findAll(user.tenantId!);
  }
}
