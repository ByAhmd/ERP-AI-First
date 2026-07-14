import { Controller, Get, Post, Body, Param, UseGuards, Patch } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { PaymentsService } from './payments.service';
import { CreatePaymentDto } from './dto/create-payment.dto';
import { CurrentUser, RequestUser } from '../../../common/decorators/current-user.decorator';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { TenantGuard } from '../../../common/guards/tenant.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { PERMISSIONS } from '@erp-ai/shared';
import { Auditable } from '../../../common/decorators/auditable.decorator';

@ApiTags('Business - Payments')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, TenantGuard, PermissionsGuard)
@Controller('business/payments')
export class PaymentsController {
  constructor(private readonly paymentsService: PaymentsService) {}

  @Post()
  @ApiOperation({ summary: 'Create a new draft payment' })
  @RequirePermissions(PERMISSIONS.BUSINESS_PAYMENTS_MANAGE)
  @Auditable({ action: 'CREATE', entityType: 'Payment' })
  create(@CurrentUser() user: RequestUser, @Body() createPaymentDto: CreatePaymentDto) {
    return this.paymentsService.create(user.tenantId!, createPaymentDto, user.id);
  }

  @Get()
  @ApiOperation({ summary: 'List all payments' })
  @RequirePermissions(PERMISSIONS.BUSINESS_PAYMENTS_READ)
  findAll(@CurrentUser() user: RequestUser) {
    return this.paymentsService.findAll(user.tenantId!);
  }

  @Get(':id')
  @ApiOperation({ summary: 'Get a payment by ID' })
  @RequirePermissions(PERMISSIONS.BUSINESS_PAYMENTS_READ)
  findOne(@CurrentUser() user: RequestUser, @Param('id') id: string) {
    return this.paymentsService.findOne(user.tenantId!, id);
  }

  @Patch(':id/approve')
  @ApiOperation({ summary: 'Approve a payment and post to ledger' })
  @RequirePermissions(PERMISSIONS.BUSINESS_PAYMENTS_APPROVE)
  @Auditable({ action: 'APPROVE', entityType: 'Payment' })
  approve(@CurrentUser() user: RequestUser, @Param('id') id: string) {
    return this.paymentsService.approve(user.tenantId!, id);
  }
}
