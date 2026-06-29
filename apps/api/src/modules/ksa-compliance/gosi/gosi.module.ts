import { Module } from '@nestjs/common';
import { GosiController } from './gosi.controller';
import { GosiService } from './gosi.service';

@Module({
  controllers: [GosiController],
  providers: [GosiService],
})
export class GosiModule {}
