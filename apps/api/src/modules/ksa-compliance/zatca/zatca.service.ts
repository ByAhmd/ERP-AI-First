import { Injectable } from '@nestjs/common';

@Injectable()
export class ZatcaService {
  getStatus() {
    // TODO: Implement Fatoora/ZATCA e-invoicing in a later certified compliance phase.
    return { implemented: false, message: 'ZATCA placeholder only. No real e-invoicing logic.' };
  }
}
