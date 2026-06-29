import { Injectable } from '@nestjs/common';
import { SAUDI_ARABIA } from '@erp-ai/shared';

@Injectable()
export class VatService {
  getStatus() {
    // TODO: Implement Saudi VAT logic only after tax advisor review.
    return { implemented: false, defaultVatRate: SAUDI_ARABIA.defaultVatRate };
  }
}
