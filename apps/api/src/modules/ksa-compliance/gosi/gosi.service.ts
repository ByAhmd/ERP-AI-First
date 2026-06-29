import { Injectable } from '@nestjs/common';

@Injectable()
export class GosiService {
  getStatus() {
    // TODO: Implement GOSI after payroll rules and Saudi specialist review.
    return { implemented: false, message: 'GOSI placeholder only.' };
  }
}
