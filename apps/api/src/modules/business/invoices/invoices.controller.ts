import { Controller, Get, Post, Body, Param, UseGuards, Patch } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { InvoicesService } from './invoices.service';
import { CreateInvoiceDto } from './dto/create-invoice.dto';
import { CurrentUser, RequestUser } from '../../../common/decorators/current-user.decorator';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { TenantGuard } from '../../../common/guards/tenant.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { PERMISSIONS } from '@erp-ai/shared';
import { Auditable } from '../../../common/decorators/auditable.decorator';

@ApiTags('Business - Invoices')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, TenantGuard, PermissionsGuard)
@Controller('business/invoices')
export class InvoicesController {
  constructor(private readonly invoicesService: InvoicesService) {}

  @Post()
  @ApiOperation({ summary: 'Create a new draft invoice' })
  @RequirePermissions(PERMISSIONS.BUSINESS_INVOICES_MANAGE)
  @Auditable({ action: 'CREATE', entityType: 'Invoice' })
  create(@CurrentUser() user: RequestUser, @Body() createInvoiceDto: CreateInvoiceDto) {
    return this.invoicesService.create(user.tenantId!, createInvoiceDto);
  }

  @Get()
  @ApiOperation({ summary: 'List all invoices' })
  @RequirePermissions(PERMISSIONS.BUSINESS_INVOICES_READ)
  findAll(@CurrentUser() user: RequestUser) {
    return this.invoicesService.findAll(user.tenantId!);
  }

  @Get(':id')
  @ApiOperation({ summary: 'Get an invoice by ID' })
  @RequirePermissions(PERMISSIONS.BUSINESS_INVOICES_READ)
  findOne(@CurrentUser() user: RequestUser, @Param('id') id: string) {
    return this.invoicesService.findOne(user.tenantId!, id);
  }

  @Patch(':id/approve')
  @ApiOperation({ summary: 'Approve an invoice and post it to the ledger' })
  @RequirePermissions(PERMISSIONS.BUSINESS_INVOICES_APPROVE)
  @Auditable({ action: 'APPROVE', entityType: 'Invoice' })
  approve(@CurrentUser() user: RequestUser, @Param('id') id: string) {
    return this.invoicesService.approve(user.tenantId!, id);
  }
}
