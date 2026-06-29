import { Injectable } from '@nestjs/common';

@Injectable()
export class RolesService {
  listRoles() {
    // TODO: Add tenant-scoped role management and audit logging before production use.
    return { items: [], message: 'Roles module placeholder is ready.' };
  }
}
