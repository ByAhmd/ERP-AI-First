import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';

@Injectable()
export class WpsService {
  constructor(private readonly prisma: PrismaService) {}

  async generateSif(tenantId: string, payrollRunId: string) {
    const payrollRun = await this.prisma.payrollRun.findFirst({
      where: { id: payrollRunId, tenantId },
      include: {
        payslips: true
      }
    });

    if (!payrollRun) {
      throw new NotFoundException('Payroll run not found');
    }

    // BUG-013 FIX: Fetch all employee profiles in one query (prevent N+1)
    const profileIds = payrollRun.payslips.map(p => p.employeeProfileId);
    const profiles = await this.prisma.employeeProfile.findMany({
      where: { id: { in: profileIds } },
      include: { contact: true }
    });
    
    const profileMap = new Map(profiles.map(p => [p.id, p]));

    const lines = [];
    // Standard MHRSD unified format headers
    lines.push('Employee ID,Employee Name,Bank Name,IBAN,Basic Salary,Housing Allowance,Other Allowances,Deductions,Net Salary');
    
    for (const payslip of payrollRun.payslips) {
      const emp = profileMap.get(payslip.employeeProfileId);
      if (!emp) continue;

      const iqama = emp.gosiNumber || '0000000000';
      // BUG-027 FIX: Prevent CSV injection via =+-@
      const name = emp.contact?.name?.replace(/[,=+\-@]/g, '_') || 'Unknown';
      
      // BUG-012 FIX: Use database fields instead of hardcoded strings
      const bank = emp.bankName || 'Unknown Bank';
      const iban = emp.iban || 'SA0000000000000000000000';
      
      const basic = Number(emp.basicSalary || 0);
      const housing = Number(emp.housingAllowance || 0);
      const gross = Number(payslip.grossSalary);
      const other = gross - basic - housing;
      
      const deductions = Number(payslip.gosiDeduction) + Number(payslip.otherDeductions);
      const net = Number(payslip.netSalary);

      lines.push(`${iqama},${name},${bank},${iban},${basic.toFixed(2)},${housing.toFixed(2)},${other.toFixed(2)},${deductions.toFixed(2)},${net.toFixed(2)}`);
    }

    return lines.join('\n');
  }

  getStatus() {
    return { implemented: true, message: 'WPS SIF generator is active.' };
  }
}
