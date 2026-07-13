import { Injectable, NotFoundException, BadRequestException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { Decimal } from 'decimal.js';
import { CreateFixedAssetDto } from './dto/fixed-assets.dto';
import { JournalEntriesService } from '../journal-entries/journal-entries.service';

@Injectable()
export class FixedAssetsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly journalEntriesService: JournalEntriesService,
  ) {}

  async create(tenantId: string, dto: CreateFixedAssetDto) {
    return this.prisma.$transaction(async (tx: any) => {
      const asset = await tx.fixedAsset.create({
        data: {
          tenantId,
          code: dto.code,
          name: dto.name,
          description: dto.description,
          assetAccountId: dto.assetAccountId,
          depreciationAccountId: dto.depreciationAccountId,
          expenseAccountId: dto.expenseAccountId,
          purchaseDate: new Date(dto.purchaseDate),
          purchasePrice: new Decimal(dto.purchasePrice),
          salvageValue: new Decimal(dto.salvageValue),
          usefulLifeMonths: dto.usefulLifeMonths,
          depreciationMethod: dto.depreciationMethod,
          status: 'Active',
        },
      });

      // Automatically generate the depreciation schedule
      await this.generateSchedule(tx, asset);

      return asset;
    });
  }

  async getAssets(tenantId: string) {
    return this.prisma.fixedAsset.findMany({
      where: { tenantId },
      orderBy: { purchaseDate: 'desc' },
    });
  }

  private async generateSchedule(tx: any, asset: any) {
    if (asset.depreciationMethod !== 'StraightLine') {
      // Future scope: Implement DecliningBalance and UnitsOfProduction
      throw new BadRequestException('Only StraightLine is fully implemented for automated scheduling currently.');
    }

    const depreciableAmount = new Decimal(asset.purchasePrice).minus(asset.salvageValue);
    if (depreciableAmount.lte(0)) return;

    const monthlyAmount = depreciableAmount.dividedBy(asset.usefulLifeMonths).toDecimalPlaces(2);
    let accumulated = new Decimal(0);
    const schedules = [];

    // Simple straight line over N months starting from month after purchase
    let currentMonth = new Date(asset.purchaseDate);
    
    for (let i = 1; i <= asset.usefulLifeMonths; i++) {
      currentMonth.setMonth(currentMonth.getMonth() + 1);
      
      let amount = monthlyAmount;
      // Handle rounding error on last month
      if (i === asset.usefulLifeMonths) {
        amount = depreciableAmount.minus(accumulated);
      }
      
      accumulated = accumulated.plus(amount);

      schedules.push({
        fixedAssetId: asset.id,
        date: new Date(currentMonth),
        amount,
        accumulated,
      });
    }

    await tx.depreciationSchedule.createMany({
      data: schedules,
    });
  }

  async getAssetSchedules(tenantId: string, assetId: string) {
    const asset = await this.prisma.fixedAsset.findUnique({
      where: { id: assetId, tenantId },
      include: { schedules: { orderBy: { date: 'asc' } } },
    });

    if (!asset) throw new NotFoundException('Fixed Asset not found');
    return asset;
  }

  async runMonthlyDepreciation(tenantId: string, asOfDate: Date) {
    // 1. Find all scheduled depreciations <= asOfDate that are not posted
    const pendingSchedules = await this.prisma.depreciationSchedule.findMany({
      where: {
        fixedAsset: { tenantId, status: 'Active' },
        date: { lte: asOfDate },
        posted: false,
      },
      include: { fixedAsset: true },
    });

    let postedCount = 0;

    for (const schedule of pendingSchedules) {
      await this.prisma.$transaction(async (tx: any) => {
        // Create Journal Entry via JournalEntriesService? 
        // We can do it manually here inside the transaction to ensure atomicity.
        const je = await tx.journalEntry.create({
          data: {
            tenantId,
            entryNumber: `DEP-${schedule.fixedAsset.code}-${schedule.date.toISOString().slice(0,7)}`,
            entryDate: schedule.date,
            description: `Monthly Depreciation: ${schedule.fixedAsset.name}`,
            status: 'Posted',
            lines: {
              create: [
                {
                  tenantId,
                  accountId: schedule.fixedAsset.expenseAccountId,
                  debit: schedule.amount,
                  credit: 0,
                  description: `Depreciation Expense`,
                },
                {
                  tenantId,
                  accountId: schedule.fixedAsset.depreciationAccountId,
                  debit: 0,
                  credit: schedule.amount,
                  description: `Accumulated Depreciation`,
                },
              ],
            },
          },
        });

        await tx.depreciationSchedule.update({
          where: { id: schedule.id },
          data: {
            posted: true,
            journalEntryId: je.id,
          },
        });
        
        postedCount++;
      });
    }

    return { postedCount };
  }
}
