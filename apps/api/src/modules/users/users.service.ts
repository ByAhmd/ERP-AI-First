import { Injectable, NotFoundException, Logger } from '@nestjs/common';
import { PrismaService } from '../../database/prisma.service';
import { randomBytes, createHash } from 'crypto';

@Injectable()
export class UsersService {
  private readonly logger = new Logger(UsersService.name);

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

  async inviteUser(tenantId: string, email: string, fullName: string, roleId: string) {
    // Check if user exists
    let user = await this.prisma.user.findUnique({
      where: { email }
    });

    const inviteToken = randomBytes(32).toString('hex');
    const inviteTokenHash = createHash('sha256').update(inviteToken).digest('hex');
    const inviteTokenExpiresAt = new Date();
    inviteTokenExpiresAt.setDate(inviteTokenExpiresAt.getDate() + 7); // 7 days expiry

    if (!user) {
      user = await this.prisma.user.create({
        data: {
          email,
          fullName,
          status: 'Invited',
          inviteTokenHash,
          inviteTokenExpiresAt,
        }
      });
    } else {
      user = await this.prisma.user.update({
        where: { id: user.id },
        data: {
          inviteTokenHash,
          inviteTokenExpiresAt,
        }
      });
    }

    // Simulate sending email (in a real app, integrate SendGrid, AWS SES, etc.)
    const inviteLink = `${process.env.FRONTEND_URL || 'http://localhost:3000'}/auth/accept-invite?token=${inviteToken}`;
    this.logger.log(`[Email Simulation] Sent invite to ${email}. Password-set link: ${inviteLink}`);

    // Upsert tenant user
    const tenantUser = await this.prisma.tenantUser.upsert({
      where: {
        tenantId_userId: { tenantId, userId: user.id }
      },
      update: {
        roleId,
        status: 'Invited'
      },
      create: {
        tenantId,
        userId: user.id,
        roleId,
        status: 'Invited'
      }
    });

    return tenantUser;
  }
}
