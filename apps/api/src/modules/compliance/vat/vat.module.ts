import { Module } from '@nestjs/common';
import { VatService } from './vat.service';
import { VatController } from './vat.controller';

@Module({
  providers: [VatService],
  controllers: [VatController]
})
export class VatModule {}
