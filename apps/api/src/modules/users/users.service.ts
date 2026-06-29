import { Injectable } from '@nestjs/common';

@Injectable()
export class UsersService {
  listUsers() {
    // TODO: Add tenant-scoped user list, invitations, profile updates, and audit logging.
    return { items: [], message: 'Users module placeholder is ready.' };
  }
}
