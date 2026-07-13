import { Injectable, NotFoundException, BadRequestException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { Decimal } from 'decimal.js';
import { CreateItemDto, CreateWarehouseDto, TransferInventoryDto } from './dto/inventory.dto';

@Injectable()
export class InventoryService {
  constructor(private readonly prisma: PrismaService) {}

  async createItem(tenantId: string, dto: CreateItemDto) {
    return this.prisma.item.create({
      data: {
        ...dto,
        tenantId,
      },
    });
  }

  async createWarehouse(tenantId: string, dto: CreateWarehouseDto) {
    return this.prisma.warehouse.create({
      data: {
        ...dto,
        tenantId,
      },
    });
  }

  async getItems(tenantId: string) {
    return this.prisma.item.findMany({
      where: { tenantId },
      include: {
        inventoryBalances: {
          include: { warehouse: true },
        },
      },
    });
  }

  async getWarehouses(tenantId: string) {
    return this.prisma.warehouse.findMany({
      where: { tenantId },
    });
  }

  async getTransactions(tenantId: string) {
    return this.prisma.inventoryTransaction.findMany({
      where: { tenantId },
      include: {
        item: true,
        warehouse: true, // Assuming relation exists, wait, let me verify schema for transaction
      },
      orderBy: { date: 'desc' },
    });
  }

  // Called during Purchase Invoice approval
  async processInwardMovement(tx: any, tenantId: string, itemId: string, warehouseId: string, quantity: number, totalCost: number, date: Date, reference: string) {
    const item = await tx.item.findUnique({ where: { id: itemId } });
    if (!item) throw new NotFoundException('Item not found');

    const qtyDec = new Decimal(quantity);
    const costDec = new Decimal(totalCost);
    const unitCost = costDec.dividedBy(qtyDec);

    // Update Weighted Average Cost
    // New WAC = (CurrentTotalValue + NewTotalValue) / (CurrentTotalQty + NewQty)
    const currentBalances = await tx.inventoryBalance.findMany({ where: { itemId } });
    const currentTotalQty = currentBalances.reduce((sum: Decimal, b: any) => sum.plus(b.quantity), new Decimal(0));
    const currentTotalValue = currentTotalQty.times(item.weightedAverageCost);
    
    const newTotalQty = currentTotalQty.plus(qtyDec);
    const newWac = currentTotalValue.plus(costDec).dividedBy(newTotalQty);

    await tx.item.update({
      where: { id: itemId },
      data: { weightedAverageCost: newWac },
    });

    // Update Balance
    await this.upsertBalance(tx, itemId, warehouseId, qtyDec);

    // Record Transaction
    await tx.inventoryTransaction.create({
      data: {
        tenantId,
        type: 'Purchase',
        itemId,
        warehouseId,
        quantity: qtyDec,
        unitCost,
        date,
        reference,
      },
    });

    // We also support future FIFO by logging the lot:
    await tx.inventoryLot.create({
      data: {
        itemId,
        warehouseId,
        lotNumber: `LOT-${Date.now()}`,
        quantity: qtyDec,
        unitCost,
        receivedAt: date,
      },
    });

    return { unitCost, newWac };
  }

  // Called during Sales Invoice approval
  async processOutwardMovement(tx: any, tenantId: string, itemId: string, warehouseId: string, quantity: number, date: Date, reference: string) {
    const item = await tx.item.findUnique({ where: { id: itemId } });
    if (!item) throw new NotFoundException('Item not found');

    const qtyDec = new Decimal(quantity);
    
    // Check balance
    const balance = await tx.inventoryBalance.findUnique({
      where: { itemId_warehouseId: { itemId, warehouseId } },
    });

    if (!balance || new Decimal(balance.quantity).lt(qtyDec)) {
      throw new BadRequestException(`Insufficient stock for item ${item.code} in warehouse`);
    }

    // Cost of Goods Sold uses Weighted Average Cost
    const unitCost = new Decimal(item.weightedAverageCost);

    // Update Balance (Decrease)
    await this.upsertBalance(tx, itemId, warehouseId, qtyDec.negated());

    // Record Transaction
    await tx.inventoryTransaction.create({
      data: {
        tenantId,
        type: 'Sale',
        itemId,
        warehouseId,
        quantity: qtyDec.negated(),
        unitCost,
        date,
        reference,
      },
    });

    return { cogsAmount: unitCost.times(qtyDec), cogsAccountId: item.cogsAccountId, inventoryAccountId: item.inventoryAccountId };
  }

  async transferInventory(tenantId: string, dto: TransferInventoryDto) {
    return this.prisma.$transaction(async (tx: any) => {
      const qtyDec = new Decimal(dto.quantity);

      // Check balance in source warehouse
      const sourceBalance = await tx.inventoryBalance.findUnique({
        where: { itemId_warehouseId: { itemId: dto.itemId, warehouseId: dto.fromWarehouseId } },
      });

      if (!sourceBalance || new Decimal(sourceBalance.quantity).lt(qtyDec)) {
        throw new BadRequestException(`Insufficient stock in source warehouse`);
      }

      const item = await tx.item.findUnique({ where: { id: dto.itemId } });

      // Outward from Source
      await this.upsertBalance(tx, dto.itemId, dto.fromWarehouseId, qtyDec.negated());
      await tx.inventoryTransaction.create({
        data: {
          tenantId,
          type: 'Transfer',
          itemId: dto.itemId,
          warehouseId: dto.fromWarehouseId,
          quantity: qtyDec.negated(),
          unitCost: item.weightedAverageCost,
          date: new Date(dto.date),
          reference: `Transfer to ${dto.toWarehouseId}`,
        },
      });

      // Inward to Destination
      await this.upsertBalance(tx, dto.itemId, dto.toWarehouseId, qtyDec);
      await tx.inventoryTransaction.create({
        data: {
          tenantId,
          type: 'Transfer',
          itemId: dto.itemId,
          warehouseId: dto.toWarehouseId,
          quantity: qtyDec,
          unitCost: item.weightedAverageCost,
          date: new Date(dto.date),
          reference: `Transfer from ${dto.fromWarehouseId}`,
        },
      });

      return { success: true };
    });
  }

  private async upsertBalance(tx: any, itemId: string, warehouseId: string, quantityChange: Decimal) {
    const existing = await tx.inventoryBalance.findUnique({
      where: { itemId_warehouseId: { itemId, warehouseId } },
    });

    if (existing) {
      await tx.inventoryBalance.update({
        where: { id: existing.id },
        data: { quantity: new Decimal(existing.quantity).plus(quantityChange) },
      });
    } else {
      await tx.inventoryBalance.create({
        data: {
          itemId,
          warehouseId,
          quantity: quantityChange,
        },
      });
    }
  }
}
