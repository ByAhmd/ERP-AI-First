import { Module } from '@nestjs/common';
import { WpsController } from './wps.controller';
import { WpsService } from './wps.service';

@Module({
  controllers: [WpsController],
  providers: [WpsService],
})
export class WpsModule {}
