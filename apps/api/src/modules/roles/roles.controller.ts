import { Controller, Get, Param, UseGuards, Post, Put, Body } from '@nestjs/common';
import { ApiBearerAuth, ApiHeader, ApiOperation, ApiTags } from '@nestjs/swagger';
import { PERMISSIONS } from '@erp-ai/shared';
import { JwtAuthGuard } from '../auth/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../common/guards/permissions.guard';
import { RequirePermissions } from '../../common/decorators/require-permissions.decorator';
import { CurrentUser, RequestUser } from '../../common/decorators/current-user.decorator';
import { RolesService } from './roles.service';

@ApiTags('Roles')
@ApiBearerAuth()
@ApiHeader({ name: 'x-tenant-id', required: true })
@UseGuards(JwtAuthGuard, PermissionsGuard)
@Controller('roles')
export class RolesController {
  constructor(private readonly rolesService: RolesService) {}

  @Get()
  @ApiOperation({ summary: 'List all roles in the current tenant' })
  @RequirePermissions(PERMISSIONS.AUTH_ROLES_READ)
  findAll(@CurrentUser() user: RequestUser) {
    return this.rolesService.findAllByTenant(user.tenantId!);
  }

  @Get(':id')
  @ApiOperation({ summary: 'Get role details by ID' })
  @RequirePermissions(PERMISSIONS.AUTH_ROLES_READ)
  findOne(@Param('id') id: string, @CurrentUser() user: RequestUser) {
    return this.rolesService.findOneByTenant(user.tenantId!, id);
  }

  @Post()
  @ApiOperation({ summary: 'Create a new custom role' })
  @RequirePermissions(PERMISSIONS.AUTH_ROLES_MANAGE)
  create(@Body() dto: import('./dto/role.dto').CreateRoleDto, @CurrentUser() user: RequestUser) {
    return this.rolesService.createRole(user.tenantId!, dto);
  }

  @Put(':id')
  @ApiOperation({ summary: 'Update a custom role' })
  @RequirePermissions(PERMISSIONS.AUTH_ROLES_MANAGE)
  update(@Param('id') id: string, @Body() dto: import('./dto/role.dto').UpdateRoleDto, @CurrentUser() user: RequestUser) {
    return this.rolesService.updateRole(user.tenantId!, id, dto);
  }
}
