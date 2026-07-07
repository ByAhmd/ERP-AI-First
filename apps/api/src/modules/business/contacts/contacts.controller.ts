import { Controller, Get, Post, Body, Patch, Param, Delete, Query, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiQuery, ApiTags } from '@nestjs/swagger';
import { ContactsService } from './contacts.service';
import { CreateContactDto } from './dto/create-contact.dto';
import { UpdateContactDto } from './dto/update-contact.dto';
import { CurrentUser, RequestUser } from '../../../common/decorators/current-user.decorator';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { TenantGuard } from '../../../common/guards/tenant.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { PERMISSIONS } from '@erp-ai/shared';
import { Auditable } from '../../../common/decorators/auditable.decorator';

@ApiTags('Business - Contacts')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, TenantGuard, PermissionsGuard)
@Controller('business/contacts')
export class ContactsController {
  constructor(private readonly contactsService: ContactsService) {}

  @Post()
  @ApiOperation({ summary: 'Create a new contact (Customer, Supplier, etc)' })
  @RequirePermissions(PERMISSIONS.BUSINESS_CONTACTS_MANAGE)
  @Auditable({ action: 'CREATE', entityType: 'Contact' })
  create(@CurrentUser() user: RequestUser, @Body() createContactDto: CreateContactDto) {
    return this.contactsService.create(user.tenantId!, createContactDto);
  }

  @Get()
  @ApiOperation({ summary: 'List all contacts' })
  @ApiQuery({ name: 'type', required: false, enum: ['Customer', 'Supplier', 'Employee'] })
  @RequirePermissions(PERMISSIONS.BUSINESS_CONTACTS_READ)
  findAll(@CurrentUser() user: RequestUser, @Query('type') type?: string) {
    return this.contactsService.findAll(user.tenantId!, type);
  }

  @Get(':id')
  @ApiOperation({ summary: 'Get a contact by ID' })
  @RequirePermissions(PERMISSIONS.BUSINESS_CONTACTS_READ)
  findOne(@CurrentUser() user: RequestUser, @Param('id') id: string) {
    return this.contactsService.findOne(user.tenantId!, id);
  }

  @Patch(':id')
  @ApiOperation({ summary: 'Update a contact' })
  @RequirePermissions(PERMISSIONS.BUSINESS_CONTACTS_MANAGE)
  @Auditable({ action: 'UPDATE', entityType: 'Contact' })
  update(
    @CurrentUser() user: RequestUser,
    @Param('id') id: string,
    @Body() updateContactDto: UpdateContactDto,
  ) {
    return this.contactsService.update(user.tenantId!, id, updateContactDto);
  }

  @Delete(':id')
  @ApiOperation({ summary: 'Delete a contact' })
  @RequirePermissions(PERMISSIONS.BUSINESS_CONTACTS_MANAGE)
  @Auditable({ action: 'DELETE', entityType: 'Contact' })
  remove(@CurrentUser() user: RequestUser, @Param('id') id: string) {
    return this.contactsService.remove(user.tenantId!, id);
  }
}
