import { Module } from '@nestjs/common';
import { ZakatService } from './zakat.service';
import { ZakatController } from './zakat.controller';

@Module({
  providers: [ZakatService],
  controllers: [ZakatController]
})
export class ZakatModule {}
