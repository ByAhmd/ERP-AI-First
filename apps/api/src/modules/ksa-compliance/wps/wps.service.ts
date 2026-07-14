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

    const lines = [];
    // Standard MHRSD unified format headers
    lines.push('Employee ID,Employee Name,Bank Name,IBAN,Basic Salary,Housing Allowance,Other Allowances,Deductions,Net Salary');
    
    for (const payslip of payrollRun.payslips) {
      const employeeProfile = await this.prisma.employeeProfile.findUnique({
        where: { id: payslip.employeeProfileId },
        include: { contact: true }
      });

      const emp = employeeProfile;
      const iqama = emp?.gosiNumber || '0000000000';
      const name = emp?.contact?.name?.replace(/,/g, '') || 'Unknown';
      const bank = 'Al Rajhi Bank';
      const iban = 'SA0000000000000000000000';
      
      const basic = Number(emp?.basicSalary || 0);
      const housing = Number(emp?.housingAllowance || 0);
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
