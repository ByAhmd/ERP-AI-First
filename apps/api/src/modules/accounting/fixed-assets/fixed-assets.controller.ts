import { Controller, Post, Get, Body, Param, ParseUUIDPipe, UseGuards } from '@nestjs/common';
import { JwtAuthGuard } from '../../auth/guards/jwt-auth.guard';
import { FixedAssetsService } from './fixed-assets.service';
import { CurrentUser } from '../../../common/decorators/current-user.decorator';
import { CreateFixedAssetDto } from './dto/fixed-assets.dto';

@UseGuards(JwtAuthGuard)
@Controller('accounting/fixed-assets')
export class FixedAssetsController {
  constructor(private readonly fixedAssetsService: FixedAssetsService) {}

  @Post()
  async createAsset(
    @CurrentUser() user: any,
    @Body() dto: CreateFixedAssetDto,
  ) {
    return this.fixedAssetsService.create(user.tenantId, dto);
  }

  @Get()
  async getAssets(@CurrentUser() user: any) {
    return this.fixedAssetsService.getAssets(user.tenantId);
  }

  @Get(':id/schedules')
  async getSchedules(
    @CurrentUser() user: any,
    @Param('id', ParseUUIDPipe) assetId: string,
  ) {
    return this.fixedAssetsService.getAssetSchedules(user.tenantId, assetId);
  }

  @Post('run-depreciation')
  async runDepreciation(
    @CurrentUser() user: any,
    @Body() body: { asOfDate: string },
  ) {
    return this.fixedAssetsService.runMonthlyDepreciation(user.tenantId, new Date(body.asOfDate));
  }
}
