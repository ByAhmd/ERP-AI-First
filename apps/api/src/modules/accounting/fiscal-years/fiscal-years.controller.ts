import { Controller, Post, Get, Param, Body, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiHeader, ApiOperation, ApiTags } from '@nestjs/swagger';
import { PERMISSIONS } from '@erp-ai/shared';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { CurrentUser, RequestUser } from '../../../common/decorators/current-user.decorator';
import { Auditable } from '../../../common/decorators/auditable.decorator';
import { FiscalYearsService, CreateFiscalYearDto } from './fiscal-years.service';

@ApiTags('Fiscal Years')
@ApiBearerAuth()
@ApiHeader({ name: 'x-tenant-id', required: true })
@UseGuards(JwtAuthGuard, PermissionsGuard)
@Controller('accounting/fiscal-years')
export class FiscalYearsController {
  constructor(private readonly fiscalYearsService: FiscalYearsService) {}

  @Post()
  @ApiOperation({ summary: 'Create a fiscal year and generate 12 monthly periods' })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_PERIOD_MANAGE)
  @Auditable({ action: 'CREATE', entityType: 'FiscalYear' })
  create(@CurrentUser() user: RequestUser, @Body() dto: CreateFiscalYearDto) {
    return this.fiscalYearsService.create(user.tenantId!, {
      name: dto.name,
      startDate: new Date(dto.startDate),
      endDate: new Date(dto.endDate),
    });
  }

  @Get()
  @ApiOperation({ summary: 'List all fiscal years and their periods' })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_PERIOD_READ)
  findAll(@CurrentUser() user: RequestUser) {
    return this.fiscalYearsService.findAll(user.tenantId!);
  }

  @Post(':id/close')
  @ApiOperation({ summary: 'Close a fiscal year' })
  @RequirePermissions(PERMISSIONS.ACCOUNTING_PERIOD_MANAGE)
  @Auditable({ action: 'CLOSE', entityType: 'FiscalYear' })
  close(@CurrentUser() user: RequestUser, @Param('id') id: string) {
    return this.fiscalYearsService.close(user.tenantId!, id);
  }
}
