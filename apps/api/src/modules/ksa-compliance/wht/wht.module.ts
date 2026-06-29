import { Module } from '@nestjs/common';
import { WhtController } from './wht.controller';
import { WhtService } from './wht.service';

@Module({
  controllers: [WhtController],
  providers: [WhtService],
})
export class WhtModule {}
