import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { CreateEmployeeProfileDto } from './dto/create-employee-profile.dto';
import { ProcessPayrollDto } from './dto/process-payroll.dto';
import { JournalEntriesService } from '../../accounting/journal-entries/journal-entries.service';
import Decimal from 'decimal.js';

@Injectable()
export class EmployeeProfilesService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly journalEntriesService: JournalEntriesService,
  ) {}

  async create(tenantId: string, dto: CreateEmployeeProfileDto) {
    return this.prisma.employeeProfile.create({
      data: {
        tenantId,
        contactId: dto.contactId,
        gosiNumber: dto.gosiNumber,
        basicSalary: dto.basicSalary.toString(),
        housingAllowance: dto.housingAllowance?.toString() || '0',
        transportAllowance: dto.transportAllowance?.toString() || '0',
      },
    });
  }

  async findAll(tenantId: string) {
    const profiles = await this.prisma.employeeProfile.findMany({
      where: { tenantId },
      include: { contact: true },
    });
    return profiles.map(p => ({
      ...p,
      basicSalary: p.basicSalary.toString(),
      housingAllowance: p.housingAllowance.toString(),
      transportAllowance: p.transportAllowance.toString(),
    }));
  }

  /**
   * Processes a monthly payroll, generating a single Journal Entry 
   * that records Salary Expenses, Salary Payables, and GOSI liabilities.
   * 
   * KSA GOSI Rates (Saudis):
   * Employee contribution: 9.75%
   * Employer contribution: 11.5%
   * 
   * Applicable to: Basic Salary + Housing Allowance
   */
  async processPayroll(tenantId: string, dto: ProcessPayrollDto) {
    const employees = await this.findAll(tenantId);
    
    if (!employees || employees.length === 0) {
      throw new NotFoundException('No employee profiles found.');
    }

    const jeLines = [];
    
    let totalGrossSalary = new Decimal(0);
    let totalEmployerGosi = new Decimal(0);
    let totalEmployeeGosi = new Decimal(0);
    let totalNetSalary = new Decimal(0);

    for (const emp of employees) {
      const basic = new Decimal(emp.basicSalary);
      const housing = new Decimal(emp.housingAllowance);
      const transport = new Decimal(emp.transportAllowance);

      const grossSalary = basic.plus(housing).plus(transport);
      const gosiBase = basic.plus(housing);

      // Simplified KSA GOSI calculation (assuming all are Saudi citizens for this Phase)
      const employeeGosi = gosiBase.mul(0.0975);
      const employerGosi = gosiBase.mul(0.115);

      const netSalary = grossSalary.minus(employeeGosi);

      totalGrossSalary = totalGrossSalary.plus(grossSalary);
      totalEmployerGosi = totalEmployerGosi.plus(employerGosi);
      totalEmployeeGosi = totalEmployeeGosi.plus(employeeGosi);
      totalNetSalary = totalNetSalary.plus(netSalary);
    }

    // 1. Gross Salary Expense (Debit)
    jeLines.push({
      accountId: dto.salaryExpenseAccountId,
      description: `Gross Salaries - ${dto.periodName}`,
      debit: totalGrossSalary.toString(),
      credit: 0,
    });

    // 2. Net Salary Payable (Credit)
    jeLines.push({
      accountId: dto.salaryPayableAccountId,
      description: `Salaries Payable - ${dto.periodName}`,
      debit: 0,
      credit: totalNetSalary.toString(),
    });

    // 3. Employee GOSI Deduction Liability (Credit)
    // 4. Employer GOSI Liability (Credit)
    // We combine these into the GOSI Payable Account
    const totalGosiLiability = totalEmployeeGosi.plus(totalEmployerGosi);
    jeLines.push({
      accountId: dto.gosiPayableAccountId,
      description: `GOSI Payable (Employer + Employee) - ${dto.periodName}`,
      debit: 0,
      credit: totalGosiLiability.toString(),
    });

    // 5. Employer GOSI Expense (Debit)
    jeLines.push({
      accountId: dto.gosiExpenseAccountId,
      description: `Employer GOSI Expense - ${dto.periodName}`,
      debit: totalEmployerGosi.toString(),
      credit: 0,
    });

    return this.journalEntriesService.create(tenantId, {
      entryDate: dto.postDate,
      description: `Payroll Run for ${dto.periodName}`,
      lines: jeLines,
    });
  }
}
