import { z } from 'zod';

export const envSchema = z.object({
  // ─── Database ────────────────────────────────────────────────────────────────
  DATABASE_URL: z.string().url(),

  // ─── Server ──────────────────────────────────────────────────────────────────
  NODE_ENV: z.enum(['development', 'production', 'test']).default('development'),
  PORT: z.coerce.number().int().positive().default(3000),
  API_PREFIX: z.string().default('api/v1'),
  CORS_ORIGIN: z.string().default('*'),

  // ─── Authentication ──────────────────────────────────────────────────────────
  JWT_SECRET: z.string().min(32),
  JWT_EXPIRES_IN: z.string().default('900'), // 15 minutes default
  JWT_REFRESH_SECRET: z.string().min(32),
  JWT_REFRESH_EXPIRES_IN: z.string().default('604800'), // 7 days default

  // ─── Redis ───────────────────────────────────────────────────────────────────
  REDIS_HOST: z.string().default('localhost'),
  REDIS_PORT: z.coerce.number().int().positive().default(6379),
  REDIS_PASSWORD: z.string().optional(),

  // ─── Rate Limiting ───────────────────────────────────────────────────────────
  RATE_LIMIT_TTL: z.coerce.number().int().positive().default(60),
  RATE_LIMIT_MAX: z.coerce.number().int().positive().default(100),
  RATE_LIMIT_AUTH_TTL: z.coerce.number().int().positive().default(60),
  RATE_LIMIT_AUTH_MAX: z.coerce.number().int().positive().default(5),

  // ─── Password Policy ─────────────────────────────────────────────────────────
  PASSWORD_MIN_LENGTH: z.coerce.number().int().positive().default(12),
  PASSWORD_REQUIRE_UPPERCASE: z.enum(['true', 'false']).transform((v) => v === 'true').default('true'),
  PASSWORD_REQUIRE_LOWERCASE: z.enum(['true', 'false']).transform((v) => v === 'true').default('true'),
  PASSWORD_REQUIRE_DIGIT: z.enum(['true', 'false']).transform((v) => v === 'true').default('true'),
  PASSWORD_REQUIRE_SPECIAL: z.enum(['true', 'false']).transform((v) => v === 'true').default('true'),
  PASSWORD_MAX_FAILED_ATTEMPTS: z.coerce.number().int().positive().default(5),
  PASSWORD_LOCKOUT_DURATION_MINUTES: z.coerce.number().int().positive().default(30),

  // ─── Argon2 ──────────────────────────────────────────────────────────────────
  ARGON2_MEMORY_COST: z.coerce.number().int().positive().default(65536),
  ARGON2_TIME_COST: z.coerce.number().int().positive().default(3),
  ARGON2_PARALLELISM: z.coerce.number().int().positive().default(4),

  // ─── Admin Seed ──────────────────────────────────────────────────────────────
  SEED_ADMIN_EMAIL: z.string().email().optional(),
  SEED_ADMIN_PASSWORD: z.string().optional(),
  SEED_ADMIN_FULL_NAME: z.string().optional(),
});

export type EnvConfig = z.infer<typeof envSchema>;

export function validateEnv(config: Record<string, unknown>): EnvConfig {
  const parsed = envSchema.safeParse(config);

  if (!parsed.success) {
    console.error('❌ Invalid environment variables:', parsed.error.format());
    throw new Error('Invalid environment variables');
  }

  return parsed.data;
}
