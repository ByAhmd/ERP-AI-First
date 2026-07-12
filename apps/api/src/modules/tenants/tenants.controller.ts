import { Body, Controller, Get, Patch, Param, Post, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/guards/jwt-auth.guard';
import { CurrentUser, RequestUser } from '../../common/decorators/current-user.decorator';
import { CreateTenantDto } from './dto/create-tenant.dto';
import { UpdateTenantDto } from './dto/update-tenant.dto';
import { TenantsService } from './tenants.service';

@ApiTags('Tenants')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('tenants')
export class TenantsController {
  constructor(private readonly tenantsService: TenantsService) {}

  @Post()
  @ApiOperation({ summary: 'Create a new tenant (company)' })
  createTenant(@CurrentUser() user: RequestUser, @Body() dto: CreateTenantDto) {
    return this.tenantsService.createTenant(user.id, dto);
  }

  @Get()
  @ApiOperation({ summary: 'List tenants for the current user' })
  listTenants(@CurrentUser() user: RequestUser) {
    return this.tenantsService.listTenants(user.id);
  }

  @Patch(':id')
  @ApiOperation({ summary: 'Update a tenant (company)' })
  updateTenant(@Param('id') id: string, @Body() dto: UpdateTenantDto) {
    return this.tenantsService.updateTenant(id, dto);
  }
}
