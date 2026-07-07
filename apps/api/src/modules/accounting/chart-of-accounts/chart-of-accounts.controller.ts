import { Controller, Get, Post, Body, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiHeader, ApiOperation, ApiTags } from '@nestjs/swagger';
import { PERMISSIONS } from '@erp-ai/shared';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { CurrentUser, RequestUser } from '../../../common/decorators/current-user.decorator';
import { Auditable } from '../../../common/decorators/auditable.decorator';
import { ChartOfAccountsService, CreateAccountDto } from './chart-of-accounts.service';

@ApiTags('Chart of Accounts')
@ApiBearerAuth()
@ApiHeader({ name: 'x-tenant-id', required: true })
@UseGuards(JwtAuthGuard, PermissionsGuard)
@Controller('accounting/chart-of-accounts')
export class ChartOfAccountsController {
  constructor(private readonly chartOfAccountsService: ChartOfAccountsService) {}

  @Post()
  @ApiOperation({ summary: 'Create a new account in the Chart of Accounts' })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_COA_WRITE)
  @Auditable({ action: 'CREATE', entityType: 'ChartOfAccount' })
  createAccount(@CurrentUser() user: RequestUser, @Body() dto: CreateAccountDto) {
    return this.chartOfAccountsService.createAccount(user.tenantId!, dto);
  }

  @Get()
  @ApiOperation({ summary: 'List all accounts (Flat array)' })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_COA_READ)
  listAccounts(@CurrentUser() user: RequestUser) {
    return this.chartOfAccountsService.listAccounts(user.tenantId!);
  }

  @Get('tree')
  @ApiOperation({ summary: 'List all accounts (Hierarchical Tree)' })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_COA_READ)
  getAccountTree(@CurrentUser() user: RequestUser) {
    return this.chartOfAccountsService.getAccountTree(user.tenantId!);
  }

  @Post('seed-template')
  @ApiOperation({ summary: 'Seed the standard SME Chart of Accounts template' })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_COA_WRITE)
  @Auditable({ action: 'SEED_TEMPLATE', entityType: 'ChartOfAccount' })
  seedTemplate(@CurrentUser() user: RequestUser) {
    return this.chartOfAccountsService.seedSmeTemplate(user.tenantId!);
  }
}
