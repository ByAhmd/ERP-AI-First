import { Controller, Post, Body, Query, Param, Put, Get, UseGuards, ParseUUIDPipe } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { PurchaseOrdersService } from './purchase-orders.service';
import { CurrentUser, RequestUser } from '../../../common/decorators/current-user.decorator';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { TenantGuard } from '../../../common/guards/tenant.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { PERMISSIONS } from '@erp-ai/shared';

@ApiTags('Business - Procurement')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, TenantGuard, PermissionsGuard)
@Controller('business/procurement/purchase-orders')
export class PurchaseOrdersController {
  constructor(private readonly purchaseOrdersService: PurchaseOrdersService) {}

  @Get()
  @RequirePermissions(PERMISSIONS.BUSINESS_INVOICES_READ)
  getPOs(@CurrentUser() user: RequestUser) {
    return this.purchaseOrdersService.getPOs(user.tenantId!);
  }

  @Post()
  @RequirePermissions(PERMISSIONS.BUSINESS_INVOICES_MANAGE)
  createPO(@CurrentUser() user: RequestUser, @Body() data: any) {
    return this.purchaseOrdersService.createPO(user.tenantId!, data, user.id);
  }

  @Post(':id/approve')
  @RequirePermissions(PERMISSIONS.BUSINESS_INVOICES_MANAGE)
  approvePurchaseOrder(
    @Param('id', ParseUUIDPipe) id: string,
    @CurrentUser() user: RequestUser,
  ) {
    return this.purchaseOrdersService.approvePurchaseOrder(user.tenantId!, id);
  }

  @Post(':id/receive')
  @RequirePermissions(PERMISSIONS.BUSINESS_INVOICES_MANAGE)
  receiveGoods(
    @CurrentUser() user: RequestUser,
    @Param('id') poId: string,
    @Body('warehouseId') warehouseId: string
  ) {
    return this.purchaseOrdersService.receiveGoods(user.tenantId!, poId, warehouseId);
  }

  @Post(':id/bill')
  @RequirePermissions(PERMISSIONS.BUSINESS_INVOICES_MANAGE)
  convertToBill(
    @CurrentUser() user: RequestUser,
    @Param('id') poId: string
  ) {
    return this.purchaseOrdersService.convertToBill(user.tenantId!, poId);
  }
}
