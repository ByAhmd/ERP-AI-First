import { Injectable, UnauthorizedException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { PassportStrategy } from '@nestjs/passport';
import { Request } from 'express';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { EnvConfig } from '../../../config/env.validation';
import { RefreshTokenPayload } from '../services/token.service';

@Injectable()
export class JwtRefreshStrategy extends PassportStrategy(Strategy, 'jwt-refresh') {
  constructor(configService: ConfigService<EnvConfig, true>) {
    super({
      jwtFromRequest: ExtractJwt.fromExtractors([
        (request: Request) => {
          // Allow from cookies or body for Q1 flexibility
          let token = request?.cookies?.refreshToken;
          if (!token && request.body) {
            token = request.body.refreshToken;
          }
          return token;
        },
      ]),
      ignoreExpiration: false,
      secretOrKey: configService.get('JWT_REFRESH_SECRET', { infer: true }),
      passReqToCallback: true,
    });
  }

  async validate(request: Request, payload: RefreshTokenPayload) {
    const refreshToken = request?.cookies?.refreshToken || request.body.refreshToken;

    if (!refreshToken) {
      throw new UnauthorizedException('Refresh token malformed');
    }

    return {
      id: payload.sub,
      jti: payload.jti,
      refreshToken,
    };
  }
}
