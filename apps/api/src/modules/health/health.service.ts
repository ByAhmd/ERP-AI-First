import { Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { HealthResponseDto } from './dto/health-response.dto';

@Injectable()
export class HealthService {
  constructor(private readonly configService: ConfigService) {}

  getHealth(): HealthResponseDto {
    return {
      status: 'ok',
      timestamp: new Date().toISOString(),
      environment: this.configService.get<string>('NODE_ENV', 'development'),
      project: 'ERP AI',
    };
  }
}
