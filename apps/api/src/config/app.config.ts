import { registerAs } from '@nestjs/config';
import { EnvConfig } from './env.validation';

export const appConfig = registerAs('app', (): EnvConfig => {
  // At this point, the env variables have been validated by validateEnv
  // so we can safely cast process.env to EnvConfig
  return process.env as unknown as EnvConfig;
});
