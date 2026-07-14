import { Controller, Post, Body, Query, Param, Put } from '@nestjs/common';
import { PurchaseOrdersService } from './purchase-orders.service';

@Controller('business/procurement/purchase-orders')
export class PurchaseOrdersController {
  constructor(private readonly poService: PurchaseOrdersService) {}

  @Post()
  createPO(@Query('tenantId') tenantId: string, @Body() data: any) {
    if (!tenantId) throw new Error('tenantId is required');
    return this.poService.createPO(tenantId, data);
  }

  @Post(':id/receive')
  receiveGoods(
    @Query('tenantId') tenantId: string,
    @Param('id') poId: string,
    @Body('warehouseId') warehouseId: string
  ) {
    if (!tenantId) throw new Error('tenantId is required');
    return this.poService.receiveGoods(tenantId, poId, warehouseId);
  }

  @Post(':id/bill')
  convertToBill(
    @Query('tenantId') tenantId: string,
    @Param('id') poId: string
  ) {
    if (!tenantId) throw new Error('tenantId is required');
    return this.poService.convertToBill(tenantId, poId);
  }
}
