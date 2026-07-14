import {
  BadRequestException,
  Injectable,
  UnauthorizedException,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { EnvConfig } from '../../config/env.validation';
import { PrismaService } from '../../database/prisma.service';
import { AuditLogsService } from '../audit-logs/audit-logs.service';
import { AuthResponseDto } from './dto/auth-response.dto';
import { LoginDto } from './dto/login.dto';
import { RegisterDto } from './dto/register.dto';
import { AcceptInviteDto } from './dto/accept-invite.dto';
import { PasswordService } from './services/password.service';
import { TokenService } from './services/token.service';
import { createHash } from 'crypto';

@Injectable()
export class AuthService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly passwordService: PasswordService,
    private readonly tokenService: TokenService,
    private readonly configService: ConfigService<EnvConfig, true>,
    private readonly auditLogsService: AuditLogsService,
  ) {}

  async register(dto: RegisterDto): Promise<AuthResponseDto> {
    if (dto.password !== dto.confirmPassword) {
      throw new BadRequestException('Passwords do not match');
    }

    const strength = this.passwordService.validateStrength(dto.password);
    if (!strength.isValid) {
      throw new BadRequestException(strength.message);
    }

    const existingUser = await this.prisma.user.findUnique({
      where: { email: dto.email.toLowerCase() },
    });

    if (existingUser) {
      throw new BadRequestException('Email already registered');
    }

    const passwordHash = await this.passwordService.hash(dto.password);

    const user = await this.prisma.user.create({
      data: {
        email: dto.email.toLowerCase(),
        fullName: dto.fullName,
        passwordHash,
        passwordChangedAt: new Date(),
        status: 'Active',
      },
    });

    await this.auditLogsService.create({
      actorUserId: user.id,
      action: 'USER_REGISTER',
      entityType: 'User',
      entityId: user.id,
    });

    return this.generateTokensResponse(user);
  }

  async login(dto: LoginDto, ipAddress?: string): Promise<AuthResponseDto> {
    const user = await this.prisma.user.findUnique({
      where: { email: dto.email.toLowerCase() },
    });

    if (!user || !user.passwordHash) {
      // Return generic error to prevent email enumeration
      throw new UnauthorizedException('Invalid email or password');
    }

    if (user.status === 'Disabled') {
      throw new UnauthorizedException('Account is disabled');
    }

    if (user.lockedUntil && user.lockedUntil > new Date()) {
      throw new UnauthorizedException('Account is temporarily locked due to too many failed attempts');
    }

    const isPasswordValid = await this.passwordService.verify(user.passwordHash, dto.password);

    if (!isPasswordValid) {
      await this.handleFailedLogin(user.id, user.failedLoginAttempts);
      throw new UnauthorizedException('Invalid email or password');
    }

    // Reset failed attempts on successful login
    await this.prisma.user.update({
      where: { id: user.id },
      data: {
        failedLoginAttempts: 0,
        lockedUntil: null,
        lastLoginAt: new Date(),
        lastLoginIp: ipAddress,
      },
    });

    await this.auditLogsService.create({
      actorUserId: user.id,
      action: 'USER_LOGIN',
      entityType: 'User',
      entityId: user.id,
      ipAddress,
    });

    return this.generateTokensResponse(user);
  }

  async acceptInvite(dto: AcceptInviteDto): Promise<AuthResponseDto> {
    const inviteTokenHash = createHash('sha256').update(dto.token).digest('hex');

    const user = await this.prisma.user.findFirst({
      where: {
        inviteTokenHash,
      },
    });

    if (!user || user.status !== 'Invited') {
      throw new UnauthorizedException('Invalid or expired invitation token');
    }

    if (user.inviteTokenExpiresAt && user.inviteTokenExpiresAt < new Date()) {
      throw new UnauthorizedException('Invitation token has expired');
    }

    const strength = this.passwordService.validateStrength(dto.password);
    if (!strength.isValid) {
      throw new BadRequestException(strength.message);
    }

    const passwordHash = await this.passwordService.hash(dto.password);

    const updatedUser = await this.prisma.user.update({
      where: { id: user.id },
      data: {
        passwordHash,
        passwordChangedAt: new Date(),
        status: 'Active',
        inviteTokenHash: null,
        inviteTokenExpiresAt: null,
      },
    });

    await this.auditLogsService.create({
      actorUserId: user.id,
      action: 'USER_ACCEPT_INVITE',
      entityType: 'User',
      entityId: user.id,
    });

    return this.generateTokensResponse(updatedUser);
  }

  async refreshTokens(userId: string, refreshToken: string): Promise<AuthResponseDto> {
    const newRefreshToken = await this.tokenService.rotateRefreshToken(userId, refreshToken);
    
    const user = await this.prisma.user.findUnique({
      where: { id: userId },
      select: { id: true, email: true, fullName: true, status: true, lockedUntil: true },
    });

    if (!user || user.status === 'Disabled' || (user.lockedUntil && user.lockedUntil > new Date())) {
       throw new UnauthorizedException('Account is disabled or locked');
    }

    const accessToken = this.tokenService.generateAccessToken(user.id, user.email);

    return {
      accessToken,
      refreshToken: newRefreshToken,
      expiresIn: this.tokenService.getAccessTokenExpiresIn(),
      user: {
        id: user.id,
        email: user.email,
        fullName: user.fullName,
      },
    };
  }

  async logout(userId: string, jti: string): Promise<void> {
    await this.tokenService.revokeRefreshToken(userId, jti);
    await this.auditLogsService.create({
      actorUserId: userId,
      action: 'USER_LOGOUT',
      entityType: 'User',
      entityId: userId,
    });
  }

  async logoutAll(userId: string): Promise<void> {
    await this.tokenService.revokeAllRefreshTokens(userId);
    await this.auditLogsService.create({
      actorUserId: userId,
      action: 'USER_LOGOUT_ALL',
      entityType: 'User',
      entityId: userId,
    });
  }

  private async handleFailedLogin(userId: string, currentAttempts: number): Promise<void> {
    const maxAttempts = this.configService.get('PASSWORD_MAX_FAILED_ATTEMPTS', { infer: true });
    const lockoutDuration = this.configService.get('PASSWORD_LOCKOUT_DURATION_MINUTES', { infer: true });

    const newAttempts = currentAttempts + 1;
    let lockedUntil: Date | null = null;

    if (newAttempts >= maxAttempts) {
      lockedUntil = new Date();
      lockedUntil.setMinutes(lockedUntil.getMinutes() + lockoutDuration);
    }

    await this.prisma.user.update({
      where: { id: userId },
      data: {
        failedLoginAttempts: newAttempts,
        lockedUntil,
      },
    });

    await this.auditLogsService.create({
      actorUserId: userId,
      action: 'USER_LOGIN_FAILED',
      entityType: 'User',
      entityId: userId,
      metadata: { attempts: newAttempts, locked: !!lockedUntil },
    });
  }

  private async generateTokensResponse(user: { id: string; email: string; fullName: string }): Promise<AuthResponseDto> {
    const accessToken = this.tokenService.generateAccessToken(user.id, user.email);
    const refreshToken = await this.tokenService.generateRefreshToken(user.id);

    return {
      accessToken,
      refreshToken,
      expiresIn: this.tokenService.getAccessTokenExpiresIn(),
      user: {
        id: user.id,
        email: user.email,
        fullName: user.fullName,
      },
    };
  }
}
