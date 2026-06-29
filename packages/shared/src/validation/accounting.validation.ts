import { z } from 'zod';
import { AccountType } from '../types/accounting.types';

export const createChartOfAccountSchema = z.object({
  code: z.string().min(1),
  name: z.string().min(1),
  type: z.nativeEnum(AccountType),
  parentId: z.string().uuid().optional(),
});
