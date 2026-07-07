import { Module } from '@nestjs/common';
import { InvoicesService } from './invoices.service';
import { InvoicesController } from './invoices.controller';
import { SequencesModule } from '../sequences/sequences.module';
import { JournalEntriesModule } from '../../accounting/journal-entries/journal-entries.module';

@Module({
  imports: [SequencesModule, JournalEntriesModule],
  controllers: [InvoicesController],
  providers: [InvoicesService],
  exports: [InvoicesService],
})
export class InvoicesModule {}
