import { Controller, Post, Body, Query, Param, Put, Get, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { CrmService } from './crm.service';
import { OpportunityStage } from '@prisma/client';
import { CurrentUser, RequestUser } from '../../../common/decorators/current-user.decorator';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { TenantGuard } from '../../../common/guards/tenant.guard';
import { PermissionsGuard } from '../../../common/guards/permissions.guard';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { PERMISSIONS } from '@erp-ai/shared';

@ApiTags('Business - CRM')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, TenantGuard, PermissionsGuard)
@Controller('business/crm')
export class CrmController {
  constructor(private readonly crmService: CrmService) {}

  @Get('opportunities')
  @RequirePermissions(PERMISSIONS.BUSINESS_CONTACTS_READ)
  getOpportunities(@CurrentUser() user: RequestUser) {
    return this.crmService.getOpportunities(user.tenantId!);
  }

  @Post('opportunities')
  @RequirePermissions(PERMISSIONS.BUSINESS_CONTACTS_MANAGE)
  createOpportunity(@CurrentUser() user: RequestUser, @Body() data: any) {
    return this.crmService.createOpportunity(user.tenantId!, data);
  }

  @Put('opportunities/:id/stage')
  @RequirePermissions(PERMISSIONS.BUSINESS_CONTACTS_MANAGE)
  updateOpportunityStage(
    @CurrentUser() user: RequestUser,
    @Param('id') id: string,
    @Body('stage') stage: OpportunityStage
  ) {
    return this.crmService.updateOpportunityStage(user.tenantId!, id, stage);
  }

  @Get('quotes')
  @RequirePermissions(PERMISSIONS.BUSINESS_CONTACTS_READ)
  getQuotes(@CurrentUser() user: RequestUser) {
    return this.crmService.getQuotes(user.tenantId!);
  }

  @Post('quotes')
  @RequirePermissions(PERMISSIONS.BUSINESS_CONTACTS_MANAGE)
  createQuote(@CurrentUser() user: RequestUser, @Body() data: any) {
    return this.crmService.createQuote(user.tenantId!, data);
  }

  @Post('quotes/:id/accept')
  @RequirePermissions(PERMISSIONS.BUSINESS_CONTACTS_MANAGE)
  acceptQuote(@CurrentUser() user: RequestUser, @Param('id') id: string) {
    return this.crmService.acceptQuote(user.tenantId!, id);
  }

  @Post('quotes/:id/convert')
  @RequirePermissions(PERMISSIONS.BUSINESS_CONTACTS_MANAGE)
  convertQuoteToInvoice(@CurrentUser() user: RequestUser, @Param('id') id: string) {
    return this.crmService.convertQuoteToInvoice(user.tenantId!, id);
  }
}
