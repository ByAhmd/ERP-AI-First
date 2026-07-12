import { Controller, Get, Post, Body, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { CurrentUser } from '../../../common/decorators/current-user.decorator';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { PERMISSIONS } from '@erp-ai/shared';
import { EmployeeProfilesService } from './employee-profiles.service';
import { CreateEmployeeProfileDto } from './dto/create-employee-profile.dto';
import { ProcessPayrollDto } from './dto/process-payroll.dto';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { TenantGuard } from '../../../common/guards/tenant.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';

@ApiTags('Business - Employee Profiles')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, TenantGuard, PermissionsGuard)
@Controller('business/employee-profiles')
export class EmployeeProfilesController {
  constructor(private readonly profilesService: EmployeeProfilesService) {}

  @Post()
  @ApiOperation({ summary: 'Create an employee profile' })
  @RequirePermissions(PERMISSIONS.BUSINESS_CONTACTS_MANAGE)
  async create(
    @CurrentUser() user: any,
    @Body() dto: CreateEmployeeProfileDto,
  ) {
    return this.profilesService.create(user.tenantId, dto);
  }

  @Get()
  @ApiOperation({ summary: 'Get all employee profiles' })
  @RequirePermissions(PERMISSIONS.BUSINESS_CONTACTS_READ)
  async findAll(@CurrentUser() user: any) {
    return this.profilesService.findAll(user.tenantId);
  }

  @Post('process-payroll')
  @ApiOperation({ summary: 'Process monthly payroll and generate journal entry' })
  @RequirePermissions(PERMISSIONS.BUSINESS_PAYMENTS_APPROVE)
  async processPayroll(
    @CurrentUser() user: any,
    @Body() dto: ProcessPayrollDto,
  ) {
    return this.profilesService.processPayroll(user.tenantId, dto);
  }
}
