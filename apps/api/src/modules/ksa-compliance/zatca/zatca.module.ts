import { Module } from '@nestjs/common';
import { ZatcaController } from './zatca.controller';
import { ZatcaService } from './zatca.service';
import { DatabaseModule } from '../../../database/database.module';

@Module({
  imports: [DatabaseModule],
  controllers: [ZatcaController],
  providers: [ZatcaService],
})
export class ZatcaModule {}
