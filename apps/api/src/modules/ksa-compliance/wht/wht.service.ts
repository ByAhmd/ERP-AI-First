import { Injectable } from '@nestjs/common';

@Injectable()
export class WhtService {
  getStatus() {
    // TODO: Implement withholding tax only after Saudi tax review.
    return { implemented: false, message: 'Withholding Tax placeholder only.' };
  }
}
