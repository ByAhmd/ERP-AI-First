import { Controller, Get, Post, Param, Body, UseGuards, Request } from '@nestjs/common';
import { ApprovalsService } from './approvals.service';
import { JwtAuthGuard } from '../auth/guards/jwt-auth.guard';
import { TenantGuard } from '../../common/guards/tenant.guard';

@Controller('business/approvals')
@UseGuards(JwtAuthGuard, TenantGuard)
export class ApprovalsController {
  constructor(private readonly approvalsService: ApprovalsService) {}

  @Get('pending')
  getPendingApprovals(@Request() req: any) {
    return this.approvalsService.getPendingApprovals(req.user.tenantId);
  }

  @Get(':id/details')
  getApprovalDetails(@Request() req: any, @Param('id') id: string) {
    return this.approvalsService.getApprovalDetails(req.user.tenantId, id);
  }

  @Post(':id/approve')
  async approveRequest(
    @Request() req: any,
    @Param('id') id: string,
    @Body('comments') comments?: string,
  ) {
    return this.approvalsService.approveRequest(req.user.tenantId, id, req.user.id, comments);
  }

  @Post(':id/reject')
  async rejectRequest(
    @Request() req: any,
    @Param('id') id: string,
    @Body('comments') comments?: string,
  ) {
    return this.approvalsService.rejectRequest(req.user.tenantId, id, req.user.id, comments);
  }
}
