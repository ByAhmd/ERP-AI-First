import { Controller, Post, Body, Query, Param, Put } from '@nestjs/common';
import { CrmService } from './crm.service';
import { OpportunityStage } from '@prisma/client';

@Controller('business/crm')
export class CrmController {
  constructor(private readonly crmService: CrmService) {}

  @Post('opportunities')
  createOpportunity(@Query('tenantId') tenantId: string, @Body() data: any) {
    if (!tenantId) throw new Error('tenantId is required');
    return this.crmService.createOpportunity(tenantId, data);
  }

  @Put('opportunities/:id/stage')
  updateOpportunityStage(
    @Query('tenantId') tenantId: string,
    @Param('id') id: string,
    @Body('stage') stage: OpportunityStage
  ) {
    if (!tenantId) throw new Error('tenantId is required');
    return this.crmService.updateOpportunityStage(tenantId, id, stage);
  }

  @Post('quotes')
  createQuote(@Query('tenantId') tenantId: string, @Body() data: any) {
    if (!tenantId) throw new Error('tenantId is required');
    return this.crmService.createQuote(tenantId, data);
  }

  @Post('quotes/:id/accept')
  acceptQuote(@Query('tenantId') tenantId: string, @Param('id') id: string) {
    if (!tenantId) throw new Error('tenantId is required');
    return this.crmService.acceptQuote(tenantId, id);
  }

  @Post('quotes/:id/convert')
  convertQuoteToInvoice(@Query('tenantId') tenantId: string, @Param('id') id: string) {
    if (!tenantId) throw new Error('tenantId is required');
    return this.crmService.convertQuoteToInvoice(tenantId, id);
  }
}
