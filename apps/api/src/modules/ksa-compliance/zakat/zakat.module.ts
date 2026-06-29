import { Module } from '@nestjs/common';
import { ZakatController } from './zakat.controller';
import { ZakatService } from './zakat.service';

@Module({
  controllers: [ZakatController],
  providers: [ZakatService],
})
export class ZakatModule {}
