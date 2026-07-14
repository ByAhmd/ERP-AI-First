import { Injectable, BadRequestException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { LeaveType, LeaveStatus, Gender, Religion } from '@prisma/client';
import { Decimal } from 'decimal.js';

@Injectable()
export class LeaveService {
  constructor(private readonly prisma: PrismaService) {}

  async calculateLeaveBalances(tenantId: string, employeeProfileId: string) {
    const profile = await this.prisma.employeeProfile.findUnique({
      where: { id: employeeProfileId, tenantId },
      include: { leaveRequests: { where: { status: 'Approved' } } },
    });

    if (!profile) throw new BadRequestException('Employee Profile not found');

    const now = new Date();
    const hireDate = profile.hireDate || now;
    const yearsOfService = (now.getTime() - hireDate.getTime()) / (1000 * 60 * 60 * 24 * 365.25);

    // 1. Annual Leave Tiers
    // 21 days for first 5 years, 30 days after 5 years
    let accruedAnnualDays = 0;
    if (yearsOfService <= 5) {
      accruedAnnualDays = yearsOfService * 21;
    } else {
      accruedAnnualDays = (5 * 21) + ((yearsOfService - 5) * 30);
    }

    const usedAnnual = profile.leaveRequests
      .filter((r) => r.type === 'Annual')
      .reduce((acc, r) => acc + this.getDaysDifference(r.startDate, r.endDate), 0);
    const availableAnnual = Math.max(0, accruedAnnualDays - usedAnnual);

    // 2. Sick Leave (120-day rolling year)
    // First 30: 100%, Next 60: 75%, Last 30: Unpaid
    const oneYearAgo = new Date(now);
    oneYearAgo.setFullYear(oneYearAgo.getFullYear() - 1);
    
    const usedSickInLastYear = profile.leaveRequests
      .filter((r) => r.type === 'Sick' && r.startDate >= oneYearAgo)
      .reduce((acc, r) => acc + this.getDaysDifference(r.startDate, r.endDate), 0);
    
    // 3. Hajj Leave
    // 10-15 days once in a lifetime after 2 years
    const eligibleForHajj = yearsOfService >= 2 && !profile.hajjLeaveUsed;

    // 4. Iddah Leave
    // Muslim Female: 130 days (4 months 10 days). Non-Muslim Female: 15 days.
    let eligibleForIddah = false;
    let iddahDays = 0;
    if (profile.gender === 'Female') {
      eligibleForIddah = true;
      iddahDays = profile.religion === 'Muslim' ? 130 : 15;
    }

    return {
      yearsOfService: yearsOfService.toFixed(2),
      annual: {
        accruedTotal: accruedAnnualDays.toFixed(2),
        used: usedAnnual,
        available: availableAnnual.toFixed(2),
      },
      sickLast12Months: {
        usedTotal: usedSickInLastYear,
        remainingFullyPaid: Math.max(0, 30 - usedSickInLastYear),
        remainingPartialPaid: Math.max(0, 60 - Math.max(0, usedSickInLastYear - 30)),
        remainingUnpaid: Math.max(0, 30 - Math.max(0, usedSickInLastYear - 90))
      },
      hajj: {
        eligible: eligibleForHajj,
        used: profile.hajjLeaveUsed,
      },
      iddah: {
        eligible: eligibleForIddah,
        days: iddahDays
      }
    };
  }

  private getDaysDifference(start: Date, end: Date) {
    const diffTime = Math.abs(end.getTime() - start.getTime());
    return Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1; // +1 to include both start and end days
  }
}
