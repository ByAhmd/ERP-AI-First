import { Injectable, ConflictException, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import Decimal from 'decimal.js';

export interface CreateCurrencyDto {
  code: string;
  name: string;
  symbol?: string;
  isBase?: boolean;
}

export interface SetExchangeRateDto {
  date: Date;
  rate: number | string;
}

@Injectable()
export class CurrenciesService {
  constructor(private readonly prisma: PrismaService) {}

  async create(tenantId: string, dto: CreateCurrencyDto) {
    const existing = await this.prisma.currency.findUnique({
      where: { tenantId_code: { tenantId, code: dto.code } }
    });

    if (existing) {
      throw new ConflictException(`Currency ${dto.code} already exists.`);
    }

    return this.prisma.$transaction(async (tx) => {
      // If setting as base, unset any existing base currency for this tenant
      if (dto.isBase) {
        await tx.currency.updateMany({
          where: { tenantId, isBase: true },
          data: { isBase: false }
        });
      }

      return tx.currency.create({
        data: {
          tenantId,
          code: dto.code,
          name: dto.name,
          symbol: dto.symbol,
          isBase: dto.isBase || false,
        }
      });
    });
  }

  async findAll(tenantId: string) {
    return this.prisma.currency.findMany({
      where: { tenantId },
      orderBy: { code: 'asc' }
    });
  }

  async setExchangeRate(tenantId: string, currencyId: string, dto: SetExchangeRateDto) {
    const currency = await this.prisma.currency.findUnique({
      where: { id: currencyId }
    });

    if (!currency || currency.tenantId !== tenantId) {
      throw new NotFoundException('Currency not found');
    }

    if (currency.isBase) {
      throw new ConflictException('Cannot set exchange rate for base currency (it is always 1.0)');
    }

    const date = new Date(dto.date);
    // Normalize date to midnight UTC for daily rates
    date.setUTCHours(0, 0, 0, 0);

    return this.prisma.exchangeRate.upsert({
      where: {
        tenantId_currencyId_date: {
          tenantId,
          currencyId,
          date
        }
      },
      update: {
        rate: new Decimal(dto.rate).toString()
      },
      create: {
        tenantId,
        currencyId,
        date,
        rate: new Decimal(dto.rate).toString()
      }
    });
  }
}
