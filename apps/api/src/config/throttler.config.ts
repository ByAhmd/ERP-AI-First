import { Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { ThrottlerOptionsFactory, ThrottlerModuleOptions } from '@nestjs/throttler';
import { EnvConfig } from './env.validation';

@Injectable()
export class ThrottlerConfigService implements ThrottlerOptionsFactory {
  constructor(private readonly configService: ConfigService<EnvConfig, true>) {}

  createThrottlerOptions(): ThrottlerModuleOptions {
    return {
      throttlers: [
        {
          name: 'default',
          ttl: this.configService.get('RATE_LIMIT_TTL', { infer: true }),
          limit: this.configService.get('RATE_LIMIT_MAX', { infer: true }),
        },
        {
          name: 'auth',
          ttl: this.configService.get('RATE_LIMIT_AUTH_TTL', { infer: true }),
          limit: this.configService.get('RATE_LIMIT_AUTH_MAX', { infer: true }),
        },
      ],
    };
  }
}
