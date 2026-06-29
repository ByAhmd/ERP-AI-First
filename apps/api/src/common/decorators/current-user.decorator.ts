import { createParamDecorator, ExecutionContext } from '@nestjs/common';

export interface RequestUser {
  id: string;
  email: string;
  tenantId?: string;
}

export const CurrentUser = createParamDecorator(
  (_data: unknown, _context: ExecutionContext): RequestUser | undefined => {
    // TODO: Return authenticated user once AuthModule is implemented.
    return undefined;
  },
);
