import { Injectable } from '@nestjs/common';

@Injectable()
export class AccountingPeriodsService {
  listPeriods(tenantId?: string) {
    // TODO: Implement period creation, close controls, reopening workflow, and audit logging.
    return {
      tenantId,
      items: [],
      message: 'Accounting period foundation is ready. Open/closed workflow comes next.',
    };
  }
}
