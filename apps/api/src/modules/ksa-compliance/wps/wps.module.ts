import { Module } from '@nestjs/common';
import { WpsController } from './wps.controller';
import { WpsService } from './wps.service';
import { DatabaseModule } from '../../../database/database.module';

@Module({
  imports: [DatabaseModule],
  controllers: [WpsController],
  providers: [WpsService],
})
export class WpsModule {}
