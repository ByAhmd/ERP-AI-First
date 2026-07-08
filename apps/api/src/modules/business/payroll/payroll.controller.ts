import { Controller, Post, Body, Param } from '@nestjs/common';
import { PayrollService } from './payroll.service';
import { CurrentUser } from '../../../common/decorators/current-user.decorator';
import { CreatePayrollRunDto } from './dto/payroll.dto';

@Controller('business/payroll')
export class PayrollController {
  constructor(private readonly payrollService: PayrollService) {}

  @Post()
  async createPayrollRun(
    @CurrentUser() user: any,
    @Body() dto: CreatePayrollRunDto,
  ) {
    return this.payrollService.createPayrollRun(user.tenantId, dto);
  }

  @Post(':id/approve')
  async approvePayrollRun(
    @CurrentUser() user: any,
    @Param('id') id: string,
  ) {
    return this.payrollService.approvePayrollRun(user.tenantId, id);
  }
}
