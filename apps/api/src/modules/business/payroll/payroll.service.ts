import { Injectable, NotFoundException, BadRequestException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { JournalEntriesService } from '../../accounting/journal-entries/journal-entries.service';
import { CreatePayrollRunDto } from './dto/payroll.dto';
import { Decimal } from 'decimal.js';
import { Prisma } from '@prisma/client';

@Injectable()
export class PayrollService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly journalEntriesService: JournalEntriesService,
  ) {}

  async createPayrollRun(tenantId: string, dto: CreatePayrollRunDto) {
    const { periodName, payslips } = dto;

    // 1. Validate employee profiles
    const profileIds = payslips.map((p) => p.employeeProfileId);
    const profiles = await this.prisma.employeeProfile.findMany({
      where: {
        tenantId,
        id: { in: profileIds },
      },
    });

    if (profiles.length !== payslips.length) {
      throw new BadRequestException('One or more Employee Profiles not found for this tenant.');
    }

    const profileMap = new Map(profiles.map((p) => [p.id, p]));

    let totalGross = new Decimal(0);
    let totalGosi = new Decimal(0);
    let totalOtherDeductions = new Decimal(0);
    let totalNet = new Decimal(0);

    const payslipsData: Prisma.PayslipCreateWithoutPayrollRunInput[] = [];

    // Calculate per-employee logic
    for (const p of payslips) {
      const profile = profileMap.get(p.employeeProfileId);
      if (!profile) continue;

      const basicSalary = new Decimal(profile.basicSalary);
      const housing = new Decimal(profile.housingAllowance || 0);
      const transport = new Decimal(profile.transportAllowance || 0);
      const bonus = new Decimal(p.bonus || 0);

      const gross = basicSalary.plus(housing).plus(transport).plus(bonus);
      
      // Calculate GOSI (Simplistic 10% on basic + housing up to 45,000 max)
      const gosiApplicableSalary = Decimal.min(basicSalary.plus(housing), new Decimal(45000));
      const gosi = gosiApplicableSalary.mul(0.10);
      
      const otherDed = new Decimal(p.otherDeductions || 0);
      const net = gross.minus(gosi).minus(otherDed);

      totalGross = totalGross.plus(gross);
      totalGosi = totalGosi.plus(gosi);
      totalOtherDeductions = totalOtherDeductions.plus(otherDed);
      totalNet = totalNet.plus(net);

      payslipsData.push({
        employeeProfileId: profile.id,
        grossSalary: gross,
        gosiDeduction: gosi,
        otherDeductions: otherDed,
        netSalary: net,
      });
    }

    return this.prisma.payrollRun.create({
      data: {
        tenantId,
        periodName,
        totalGross,
        totalNet,
        totalDeductions: totalGosi.plus(totalOtherDeductions),
        payslips: {
          create: payslipsData,
        },
      },
      include: {
        payslips: true,
      },
    });
  }

  async approvePayrollRun(tenantId: string, runId: string) {
    const run = await this.prisma.payrollRun.findUnique({
      where: { id: runId, tenantId },
      include: { payslips: true },
    });

    if (!run) {
      throw new NotFoundException('Payroll Run not found');
    }

    if (run.status === 'Approved') {
      throw new BadRequestException('Payroll run is already approved');
    }

    // Generate Journal Entry
    // Debit: Salary Expense (Total Gross)
    // Credit: GOSI Payable (Total GOSI)
    // Credit: Net Salaries Payable / Bank (Total Net)
    
    // For MVP, finding standard accounts:
    const salaryExpenseAccount = await this.prisma.chartOfAccount.findFirst({
      where: { tenantId, name: { contains: 'Salary' }, type: 'Expense' }
    });
    
    const gosiPayableAccount = await this.prisma.chartOfAccount.findFirst({
      where: { tenantId, name: { contains: 'GOSI' }, type: 'Liability' }
    });

    const salariesPayableAccount = await this.prisma.chartOfAccount.findFirst({
      where: { tenantId, name: { contains: 'Salaries Payable' }, type: 'Liability' }
    });

    if (!salaryExpenseAccount || !gosiPayableAccount || !salariesPayableAccount) {
      throw new BadRequestException('Standard payroll accounts not found in Chart of Accounts.');
    }

    const jeLines = [];

    // Debit Salary Expense
    jeLines.push({
      accountId: salaryExpenseAccount.id,
      description: `Payroll for ${run.periodName}`,
      debit: run.totalGross.toString(),
      credit: '0',
    });

    // Credit GOSI Payable
    if (new Decimal(run.totalDeductions).gt(0)) {
       jeLines.push({
        accountId: gosiPayableAccount.id,
        description: `GOSI Deductions for ${run.periodName}`,
        debit: '0',
        credit: run.totalDeductions.toString(),
      });
    }

    // Credit Salaries Payable (or Bank)
    jeLines.push({
      accountId: salariesPayableAccount.id,
      description: `Net Salaries for ${run.periodName}`,
      debit: '0',
      credit: run.totalNet.toString(),
    });

    const je = await this.journalEntriesService.create(tenantId, {
      entryDate: new Date(),
      description: `Payroll Entry: ${run.periodName}`,
      lines: jeLines,
    });

    // je is already posted upon creation via journalEntriesService in this phase
    return this.prisma.payrollRun.update({
      where: { id: runId },
      data: {
        status: 'Approved',
        journalEntryId: je.id,
      },
    });
  }
}
