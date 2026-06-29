import { Injectable } from '@nestjs/common';

@Injectable()
export class ZakatService {
  getStatus() {
    // TODO: Implement Zakat workflows only after Saudi compliance specialist review.
    return { implemented: false, message: 'Zakat placeholder only.' };
  }
}
