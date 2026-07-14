import { Logger, ValidationPipe } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { NestFactory } from '@nestjs/core';
import { DocumentBuilder, SwaggerModule } from '@nestjs/swagger';
import compression from 'compression';
import cookieParser from 'cookie-parser';
import helmet from 'helmet';
import { AppModule } from './app.module';
import { GlobalExceptionFilter } from './common/filters/global-exception.filter';
import { RequestLoggingInterceptor } from './common/interceptors/request-logging.interceptor';
import { TenantInterceptor } from './common/interceptors/tenant.interceptor';
import { EnvConfig } from './config/env.validation';

async function bootstrap(): Promise<void> {
  const app = await NestFactory.create(AppModule);
  const configService = app.get(ConfigService<EnvConfig, true>);
  const logger = new Logger('Bootstrap');

  const apiPrefix = configService.get('API_PREFIX', { infer: true });
  const corsOrigin = configService.get('CORS_ORIGIN', { infer: true });
  const port = configService.get('PORT', { infer: true });

  app.setGlobalPrefix(apiPrefix);

  // ─── Security & Middlewares ────────────────────────────────────────────────
  app.use(helmet());
  app.use(compression());
  app.use(cookieParser());
  
  app.enableCors({
    origin: corsOrigin === '*' ? true : corsOrigin.split(','),
    credentials: true,
  });

  app.useGlobalPipes(
    new ValidationPipe({
      whitelist: true,
      transform: true,
      forbidNonWhitelisted: true,
    }),
  );
  app.useGlobalFilters(new GlobalExceptionFilter());
  app.useGlobalInterceptors(new RequestLoggingInterceptor(), new TenantInterceptor());

  // ─── Swagger / OpenAPI ─────────────────────────────────────────────────────
  const swaggerConfig = new DocumentBuilder()
    .setTitle('ERP AI API')
    .setDescription('Saudi-first AI-assisted ERP and accounting platform backend API.')
    .setVersion('0.1.0')
    .addBearerAuth()
    .addCookieAuth('refreshToken')
    .build();
  
  const document = SwaggerModule.createDocument(app, swaggerConfig);
  SwaggerModule.setup('api/docs', app, document, {
    swaggerOptions: { persistAuthorization: true },
  });

  await app.listen(port);
  logger.log(`ERP AI API listening on http://localhost:${port}/${apiPrefix}`);
  logger.log(`Swagger documentation available at http://localhost:${port}/api/docs`);
}

void bootstrap();
