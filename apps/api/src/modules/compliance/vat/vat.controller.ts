import { Controller, Get, Query } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { CurrentUser } from '../../../common/decorators/current-user.decorator';
import { RequirePermissions } from '../../../common/decorators/require-permissions.decorator';
import { PERMISSIONS } from '@erp-ai/shared';
import { VatService } from './vat.service';
import { GetVatReturnDto } from './dto/get-vat-return.dto';

@ApiTags('Compliance - VAT')
@ApiBearerAuth()
@Controller('compliance/vat')
export class VatController {
  constructor(private readonly vatService: VatService) {}

  @Get('return')
  @ApiOperation({ summary: 'Calculate VAT Return for a given period' })
  @RequirePermissions(PERMISSIONS.COMPLIANCE_VAT_READ)
  async getVatReturn(
    @CurrentUser() user: any,
    @Query() dto: GetVatReturnDto,
  ) {
    return this.vatService.calculateVatReturn(user.tenantId, dto);
  }
}
