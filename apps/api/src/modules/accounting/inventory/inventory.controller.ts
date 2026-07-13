import { Controller, Post, Get, Body, UseGuards } from '@nestjs/common';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { InventoryService } from './inventory.service';
import { CurrentUser } from '../../../common/decorators/current-user.decorator';
import { CreateItemDto, CreateWarehouseDto, TransferInventoryDto } from './dto/inventory.dto';

@UseGuards(JwtAuthGuard)
@Controller('accounting/inventory')
export class InventoryController {
  constructor(private readonly inventoryService: InventoryService) {}

  @Get('items')
  async getItems(@CurrentUser() user: any) {
    return this.inventoryService.getItems(user.tenantId);
  }

  @Get('warehouses')
  async getWarehouses(@CurrentUser() user: any) {
    return this.inventoryService.getWarehouses(user.tenantId);
  }

  @Get('transactions')
  async getTransactions(@CurrentUser() user: any) {
    return this.inventoryService.getTransactions(user.tenantId);
  }

  @Post('items')
  async createItem(
    @CurrentUser() user: any,
    @Body() dto: CreateItemDto,
  ) {
    return this.inventoryService.createItem(user.tenantId, dto);
  }

  @Post('warehouses')
  async createWarehouse(
    @CurrentUser() user: any,
    @Body() dto: CreateWarehouseDto,
  ) {
    return this.inventoryService.createWarehouse(user.tenantId, dto);
  }

  @Post('transfer')
  async transferInventory(
    @CurrentUser() user: any,
    @Body() dto: TransferInventoryDto,
  ) {
    return this.inventoryService.transferInventory(user.tenantId, dto);
  }
}
