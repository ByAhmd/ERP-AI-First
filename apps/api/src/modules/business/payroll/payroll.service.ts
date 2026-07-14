import { Injectable, NotFoundException, BadRequestException } from '@nestjs/common';
import { OnEvent } from '@nestjs/event-emitter';
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

  async findAll(tenantId: string) {
    const runs = await this.prisma.payrollRun.findMany({
      where: { tenantId },
      orderBy: { createdAt: 'desc' },
    });
    return runs.map(r => ({
      ...r,
      totalGross: r.totalGross.toString(),
      totalNet: r.totalNet.toString(),
      totalDeductions: r.totalDeductions.toString(),
      totalGosi: r.totalGosi.toString(),
      totalOtherDeductions: r.totalOtherDeductions.toString(),
    }));
  }

  async createPayrollRun(tenantId: string, dto: CreatePayrollRunDto, userId: string) {
    const { periodName, payslips } = dto;

    const existingRun = await this.prisma.payrollRun.findUnique({
      where: { tenantId_periodName: { tenantId, periodName } },
    });

    if (existingRun) {
      if (existingRun.status === 'Approved' || existingRun.status === 'Paid') {
        throw new BadRequestException(`Payroll run for ${periodName} is already ${existingRun.status}.`);
      }
      // If it's Draft, delete it so we can recreate it with new data
      await this.prisma.payrollRun.delete({ where: { id: existingRun.id } });
    }

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
      
      // WPS: Contract Matching Validation
      if (profile.contractSalary && new Decimal(profile.contractSalary).gt(0)) {
        const expectedGross = new Decimal(profile.contractSalary).plus(bonus);
        if (!gross.equals(expectedGross)) {
          throw new BadRequestException(
            `WPS Violation: Payroll gross for employee ${profile.id} (${gross.toString()}) does not match the registered Qiwa contract salary (${expectedGross.toString()}).`
          );
        }
      }

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

    return this.prisma.$transaction(async (tx) => {
      const payrollRun = await tx.payrollRun.create({
        data: {
          tenantId,
          periodName,
          totalGross,
          totalNet,
          status: 'PendingApproval',
          totalDeductions: totalGosi.plus(totalOtherDeductions),
          totalGosi,
          totalOtherDeductions,
          payslips: {
            create: payslipsData,
          },
        },
        include: {
          payslips: true,
        },
      });

      await tx.approvalRequest.create({
        data: {
          tenantId,
          entityType: 'PayrollRun',
          entityId: payrollRun.id,
          requestedById: userId,
          status: 'Pending',
        }
      });

      return payrollRun;
    });
  }

  @OnEvent('approval.approved')
  async handleApprovalApproved(payload: { tenantId: string; entityType: string; entityId: string }) {
    if (payload.entityType === 'PayrollRun') {
      await this.approvePayrollRun(payload.tenantId, payload.entityId);
    }
  }

  @OnEvent('approval.rejected')
  async handleApprovalRejected(payload: { tenantId: string; entityType: string; entityId: string }) {
    if (payload.entityType === 'PayrollRun') {
      await this.prisma.payrollRun.update({
        where: { id: payload.entityId },
        data: { status: 'Draft' }, // Revert back to Draft
      });
    }
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
    
    // For MVP, finding standard accounts by their seeded codes:
    let salaryExpenseAccount = await this.prisma.chartOfAccount.findUnique({
      where: { tenantId_code: { tenantId, code: '5110' } }
    });
    
    let gosiPayableAccount = await this.prisma.chartOfAccount.findUnique({
      where: { tenantId_code: { tenantId, code: '2140' } }
    });

    let otherDeductionsPayableAccount = await this.prisma.chartOfAccount.findUnique({
      where: { tenantId_code: { tenantId, code: '2150' } }
    });

    let salariesPayableAccount = await this.prisma.chartOfAccount.findUnique({
      where: { tenantId_code: { tenantId, code: '2130' } }
    });

    if (!salaryExpenseAccount) {
      const expenseParent = await this.prisma.chartOfAccount.findUnique({ where: { tenantId_code: { tenantId, code: '5100' } } });
      salaryExpenseAccount = await this.prisma.chartOfAccount.create({ 
        data: { tenantId, code: '5110', name: 'Salaries and Wages', type: 'Expense', parentId: expenseParent?.id } 
      });
    }
    
    if (!gosiPayableAccount || !salariesPayableAccount || !otherDeductionsPayableAccount) {
      const liabilityParent = await this.prisma.chartOfAccount.findUnique({ where: { tenantId_code: { tenantId, code: '2100' } } });
      
      if (!gosiPayableAccount) {
        gosiPayableAccount = await this.prisma.chartOfAccount.create({ 
          data: { tenantId, code: '2140', name: 'GOSI Payable', type: 'Liability', parentId: liabilityParent?.id } 
        });
      }
      if (!otherDeductionsPayableAccount) {
        otherDeductionsPayableAccount = await this.prisma.chartOfAccount.create({ 
          data: { tenantId, code: '2150', name: 'Other Deductions Payable', type: 'Liability', parentId: liabilityParent?.id } 
        });
      }
      if (!salariesPayableAccount) {
        salariesPayableAccount = await this.prisma.chartOfAccount.create({ 
          data: { tenantId, code: '2130', name: 'Salaries Payable', type: 'Liability', parentId: liabilityParent?.id } 
        });
      }
    }

    const jeLines: any[] = [];

    // Debit Salary Expense
    jeLines.push({
      accountId: salaryExpenseAccount.id,
      description: `Payroll for ${run.periodName}`,
      debit: run.totalGross.toString(),
      credit: '0',
    });

    // Credit GOSI Payable
    if (new Decimal(run.totalGosi).gt(0)) {
       jeLines.push({
        accountId: gosiPayableAccount.id,
        description: `GOSI Deductions for ${run.periodName}`,
        debit: '0',
        credit: run.totalGosi.toString(),
      });
    }

    // Credit Other Deductions Payable
    if (new Decimal(run.totalOtherDeductions).gt(0)) {
      jeLines.push({
        accountId: otherDeductionsPayableAccount.id,
        description: `Other Deductions for ${run.periodName}`,
        debit: '0',
        credit: run.totalOtherDeductions.toString(),
      });
    }

    // Credit Salaries Payable (or Bank)
    jeLines.push({
      accountId: salariesPayableAccount.id,
      description: `Net Salaries for ${run.periodName}`,
      debit: '0',
      credit: run.totalNet.toString(),
    });

    return this.prisma.$transaction(async (tx) => {
      const je = await this.journalEntriesService.create(tenantId, {
        entryDate: new Date(),
        description: `Payroll Entry: ${run.periodName}`,
        lines: jeLines,
      }, tx);

      // je is already posted upon creation via journalEntriesService in this phase
      return tx.payrollRun.update({
        where: { id: runId },
        data: {
          status: 'Approved',
          journalEntryId: je.id,
        },
      });
    });
  }
}
