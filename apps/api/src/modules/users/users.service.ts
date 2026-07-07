import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../database/prisma.service';

@Injectable()
export class UsersService {
  constructor(private readonly prisma: PrismaService) {}

  /**
   * Retrieves all users that belong to a specific tenant.
   * Scopes the query to ensure cross-tenant data leakage cannot occur.
   */
  async findAllByTenant(tenantId: string) {
    const tenantUsers = await this.prisma.tenantUser.findMany({
      where: { tenantId },
      include: {
        user: {
          select: {
            id: true,
            email: true,
            fullName: true,
            status: true,
            lastLoginAt: true,
            createdAt: true,
          },
        },
        role: {
          select: {
            id: true,
            name: true,
          },
        },
      },
      orderBy: { createdAt: 'desc' },
    });

    return tenantUsers.map((tu) => ({
      id: tu.user.id,
      email: tu.user.email,
      fullName: tu.user.fullName,
      globalStatus: tu.user.status,
      tenantStatus: tu.status,
      role: tu.role,
      lastLoginAt: tu.user.lastLoginAt,
      joinedAt: tu.createdAt,
    }));
  }

  async findOneByTenant(tenantId: string, userId: string) {
    const tenantUser = await this.prisma.tenantUser.findUnique({
      where: {
        tenantId_userId: {
          tenantId,
          userId,
        },
      },
      include: {
        user: {
          select: {
            id: true,
            email: true,
            fullName: true,
            status: true,
            lastLoginAt: true,
            createdAt: true,
          },
        },
        role: {
          select: {
            id: true,
            name: true,
          },
        },
      },
    });

    if (!tenantUser) {
      throw new NotFoundException('User not found in this tenant');
    }

    return {
      id: tenantUser.user.id,
      email: tenantUser.user.email,
      fullName: tenantUser.user.fullName,
      globalStatus: tenantUser.user.status,
      tenantStatus: tenantUser.status,
      role: tenantUser.role,
      lastLoginAt: tenantUser.user.lastLoginAt,
      joinedAt: tenantUser.createdAt,
    };
  }
}
