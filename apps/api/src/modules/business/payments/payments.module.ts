import { Module } from '@nestjs/common';
import { PaymentsService } from './payments.service';
import { PaymentsController } from './payments.controller';
import { SequencesModule } from '../sequences/sequences.module';
import { JournalEntriesModule } from '../../accounting/journal-entries/journal-entries.module';

@Module({
  imports: [SequencesModule, JournalEntriesModule],
  controllers: [PaymentsController],
  providers: [PaymentsService],
  exports: [PaymentsService],
})
export class PaymentsModule {}
