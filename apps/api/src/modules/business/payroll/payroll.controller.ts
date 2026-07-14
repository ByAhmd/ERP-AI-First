import { Controller, Post, Get, Body, Param, UseGuards } from '@nestjs/common';
import { PayrollService } from './payroll.service';
import { CurrentUser } from '../../../common/decorators/current-user.decorator';
import { CreatePayrollRunDto } from './dto/payroll.dto';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { TenantGuard } from '../../../common/guards/tenant.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { PERMISSIONS } from '@erp-ai/shared';

@UseGuards(JwtAuthGuard, TenantGuard, PermissionsGuard)
@Controller('business/payroll')
export class PayrollController {
  constructor(private readonly payrollService: PayrollService) {}

  @Get()
  async findAll(@CurrentUser() user: any) {
    return this.payrollService.findAll(user.tenantId);
  }

  @Post()
  async createPayrollRun(
    @CurrentUser() user: any,
    @Body() dto: CreatePayrollRunDto,
  ) {
    return this.payrollService.createPayrollRun(user.tenantId, dto, user.id);
  }

  @Post(':id/approve')
  async approvePayrollRun(
    @CurrentUser() user: any,
    @Param('id') id: string,
  ) {
    return this.payrollService.approvePayrollRun(user.tenantId, id);
  }
}
