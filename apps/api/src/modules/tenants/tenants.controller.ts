import { Body, Controller, Get, Post } from '@nestjs/common';
import { CreateTenantDto } from './dto/create-tenant.dto';
import { TenantsService } from './tenants.service';

@Controller('tenants')
export class TenantsController {
  constructor(private readonly tenantsService: TenantsService) {}

  @Post()
  createTenant(@Body() dto: CreateTenantDto) {
    return this.tenantsService.createTenant(dto);
  }

  @Get()
  listTenants() {
    return this.tenantsService.listTenants();
  }
}
