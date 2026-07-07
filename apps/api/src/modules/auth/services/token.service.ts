import { Injectable, UnauthorizedException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { JwtService } from '@nestjs/jwt';
import { v4 as uuidv4 } from 'uuid';
import { EnvConfig } from '../../../config/env.validation';
import { RedisService } from '../../../database/redis.service';

export interface JwtPayload {
  sub: string;
  email: string;
}

export interface RefreshTokenPayload {
  sub: string;
  jti: string;
}

@Injectable()
export class TokenService {
  constructor(
    private readonly jwtService: JwtService,
    private readonly configService: ConfigService<EnvConfig, true>,
    private readonly redisService: RedisService,
  ) {}

  generateAccessToken(userId: string, email: string): string {
    const payload: JwtPayload = { sub: userId, email };
    return this.jwtService.sign(payload, {
      secret: this.configService.get('JWT_SECRET', { infer: true }),
      expiresIn: `${this.configService.get('JWT_EXPIRES_IN', { infer: true })}s`,
      jwtid: uuidv4(),
    });
  }

  async generateRefreshToken(userId: string): Promise<string> {
    const jti = uuidv4();
    const payload: RefreshTokenPayload = { sub: userId, jti };
    const expiresInSeconds = parseInt(
      this.configService.get('JWT_REFRESH_EXPIRES_IN', { infer: true }),
      10,
    );

    const refreshToken = this.jwtService.sign(payload, {
      secret: this.configService.get('JWT_REFRESH_SECRET', { infer: true }),
      expiresIn: `${expiresInSeconds}s`,
    });

    // Store in Redis: erp:global:refresh_tokens:{userId}:{jti} -> valid
    const key = this.redisService.buildKey('global', 'refresh_tokens', `${userId}:${jti}`);
    await this.redisService.setWithTTL(key, 'valid', expiresInSeconds);

    return refreshToken;
  }

  async rotateRefreshToken(userId: string, oldToken: string): Promise<string> {
    try {
      const decoded = this.jwtService.verify<RefreshTokenPayload>(oldToken, {
        secret: this.configService.get('JWT_REFRESH_SECRET', { infer: true }),
      });

      if (decoded.sub !== userId) {
        throw new UnauthorizedException('Invalid token owner');
      }

      // Check if old token exists in Redis
      const key = this.redisService.buildKey('global', 'refresh_tokens', `${userId}:${decoded.jti}`);
      const isValid = await this.redisService.exists(key);

      if (!isValid) {
        // Warning: Token was likely revoked or already rotated!
        // For security, revoke all tokens for this user immediately (prevent token theft reuse).
        await this.revokeAllRefreshTokens(userId);
        throw new UnauthorizedException('Token has been revoked or reused');
      }

      // Invalidate old token
      await this.redisService.del(key);

      // Generate new token
      return this.generateRefreshToken(userId);
    } catch {
      throw new UnauthorizedException('Invalid refresh token');
    }
  }

  async revokeRefreshToken(userId: string, jti: string): Promise<void> {
    const key = this.redisService.buildKey('global', 'refresh_tokens', `${userId}:${jti}`);
    await this.redisService.del(key);
  }

  async revokeAllRefreshTokens(userId: string): Promise<void> {
    const pattern = `erp:global:refresh_tokens:${userId}:*`;
    await this.redisService.delByPattern(pattern);
  }

  getAccessTokenExpiresIn(): number {
    return parseInt(this.configService.get('JWT_EXPIRES_IN', { infer: true }), 10);
  }
}
