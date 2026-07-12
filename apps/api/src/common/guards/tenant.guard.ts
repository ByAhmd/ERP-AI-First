import { CanActivate, ExecutionContext, Injectable, ForbiddenException, UnauthorizedException } from '@nestjs/common';
import { PrismaService } from '../../database/prisma.service';
import { RequestUser } from '../decorators/current-user.decorator';

@Injectable()
export class TenantGuard implements CanActivate {
  constructor(private readonly prisma: PrismaService) {}

  async canActivate(context: ExecutionContext): Promise<boolean> {
    const request = context.switchToHttp().getRequest();
    const rawTenantId = request.headers['x-tenant-id'];
    const tenantId = Array.isArray(rawTenantId) ? rawTenantId[0] : rawTenantId;
    const user = request.user as RequestUser | undefined;

    if (!tenantId) {
      // If no tenant is requested, we don't enforce tenant membership here.
      // Endpoints that require a tenant should ensure it's provided.
      return true;
    }

    if (!user) {
      throw new UnauthorizedException('Authentication required');
    }

    // Verify the user is actually a member of this tenant
    const membership = await this.prisma.tenantUser.findUnique({
      where: {
        tenantId_userId: {
          tenantId: tenantId,
          userId: user.id,
        },
      },
    });

    if (!membership || membership.status !== 'Active') {
      throw new ForbiddenException('You do not have access to this tenant or your access is inactive.');
    }

    return true;
  }
}
