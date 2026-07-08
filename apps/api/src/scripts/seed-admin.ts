import 'reflect-metadata';
import { NestFactory } from '@nestjs/core';
import { ConfigService } from '@nestjs/config';
import { Logger } from '@nestjs/common';
import { AppModule } from '../app.module';
import { PrismaService } from '../database/prisma.service';
import { PasswordService } from '../modules/auth/services/password.service';
import { EnvConfig } from '../config/env.validation';

async function bootstrap() {
  const logger = new Logger('SeedAdmin');
  logger.log('Starting Platform Admin Seeding...');

  const app = await NestFactory.createApplicationContext(AppModule);
  
  const prisma = app.get(PrismaService);
  const passwordService = app.get(PasswordService);
  const configService = app.get(ConfigService<EnvConfig, true>);

  const email = configService.get('SEED_ADMIN_EMAIL', { infer: true });
  const password = configService.get('SEED_ADMIN_PASSWORD', { infer: true });
  const fullName = configService.get('SEED_ADMIN_FULL_NAME', { infer: true }) || 'Platform Administrator';

  if (!email || !password) {
    logger.error('SEED_ADMIN_EMAIL and SEED_ADMIN_PASSWORD must be provided in the environment variables.');
    await app.close();
    process.exit(1);
  }

  try {
    const existingAdmin = await prisma.user.findUnique({
      where: { email: email.toLowerCase() },
    });

    if (existingAdmin) {
      logger.warn(`Admin user with email ${email} already exists. Skipping seed.`);
    } else {
      const passwordHash = await passwordService.hash(password);
      
      const admin = await prisma.user.create({
        data: {
          email: email.toLowerCase(),
          fullName,
          passwordHash,
          passwordChangedAt: new Date(),
          status: 'Active',
        },
      });

      logger.log(`Successfully created platform admin user: ${admin.email}`);
    }

    // Since this is the initial seed, we also need a default platform tenant to hold global settings if needed, 
    // or just let the user be a global super-admin. 
    // For Phase 1, the user is just created. Tenant assignments happen later.

  } catch (error) {
    logger.error('Error seeding admin user', error);
  } finally {
    await app.close();
  }
}

void bootstrap();
