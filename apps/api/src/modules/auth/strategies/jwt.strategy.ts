import { Injectable, UnauthorizedException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { EnvConfig } from '../../../config/env.validation';
import { PrismaService } from '../../../database/prisma.service';
import { JwtPayload } from '../services/token.service';

@Injectable()
export class JwtStrategy extends PassportStrategy(Strategy) {
  constructor(
    configService: ConfigService<EnvConfig, true>,
    private readonly prisma: PrismaService,
  ) {
    super({
      jwtFromRequest: ExtractJwt.fromExtractors([
        (request: any) => {
          let token = null;
          if (request && request.cookies) {
            token = request.cookies['accessToken'];
          }
          if (!token && request.headers.authorization) {
             token = ExtractJwt.fromAuthHeaderAsBearerToken()(request);
          }
          return token;
        },
      ]),
      ignoreExpiration: false,
      secretOrKey: configService.get('JWT_SECRET', { infer: true }),
      // Pass the full request to validate() so we can read headers
      passReqToCallback: true,
    });
  }

  async validate(request: any, payload: JwtPayload) {
    const user = await this.prisma.user.findUnique({
      where: { id: payload.sub },
      select: { id: true, email: true, fullName: true, status: true, lockedUntil: true },
    });

    if (!user) {
      throw new UnauthorizedException('User not found');
    }

    if (user.status === 'Disabled') {
      throw new UnauthorizedException('User account is disabled');
    }

    if (user.lockedUntil && user.lockedUntil > new Date()) {
      throw new UnauthorizedException('Account is temporarily locked');
    }

    // Attach the tenantId from the request header if present
    const rawTenantId = request.headers['x-tenant-id'];
    const tenantId = Array.isArray(rawTenantId) ? rawTenantId[0] : rawTenantId;

    return { id: user.id, email: user.email, fullName: user.fullName, tenantId };
  }
}
