import { Injectable } from '@nestjs/common';

@Injectable()
export class WpsService {
  getStatus() {
    // TODO: Implement WPS/Mudad salary file workflows in the payroll phase.
    return { implemented: false, message: 'WPS/Mudad placeholder only.' };
  }
}
