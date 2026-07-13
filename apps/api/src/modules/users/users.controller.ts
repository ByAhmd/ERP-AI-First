import { Controller, Get, Param, UseGuards, Post, Body } from '@nestjs/common';
import { ApiBearerAuth, ApiHeader, ApiOperation, ApiTags } from '@nestjs/swagger';
import { PERMISSIONS } from '@erp-ai/shared';
import { JwtAuthGuard } from '../auth/guards/jwt-auth.guard';
import { PermissionsGuard } from '../../common/guards/permissions.guard';
import { RequirePermissions } from '../../common/decorators/require-permissions.decorator';
import { CurrentUser, RequestUser } from '../../common/decorators/current-user.decorator';
import { UsersService } from './users.service';

@ApiTags('Users')
@ApiBearerAuth()
@ApiHeader({ name: 'x-tenant-id', required: true })
@UseGuards(JwtAuthGuard, PermissionsGuard)
@Controller('users')
export class UsersController {
  constructor(private readonly usersService: UsersService) {}

  @Get()
  @ApiOperation({ summary: 'List all users in the current tenant' })
  @RequirePermissions(PERMISSIONS.AUTH_USERS_READ)
  findAll(@CurrentUser() user: RequestUser) {
    return this.usersService.findAllByTenant(user.tenantId!);
  }

  @Get(':id')
  @ApiOperation({ summary: 'Get user details by ID within the current tenant' })
  @RequirePermissions(PERMISSIONS.AUTH_USERS_READ)
  findOne(@Param('id') id: string, @CurrentUser() user: RequestUser) {
    return this.usersService.findOneByTenant(user.tenantId!, id);
  }

  @Post('invite')
  @ApiOperation({ summary: 'Invite a user to the tenant' })
  @RequirePermissions(PERMISSIONS.AUTH_USERS_MANAGE)
  async inviteUser(
    @CurrentUser() user: RequestUser,
    @Body('email') email: string,
    @Body('fullName') fullName: string,
    @Body('roleId') roleId: string,
  ) {
    return this.usersService.inviteUser(user.tenantId!, email, fullName, roleId);
  }
}
