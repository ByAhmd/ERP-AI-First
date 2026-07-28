<?php

declare(strict_types=1);

return [

    'navigation_group' => 'Identity',

    'members' => [
        'label' => 'Member',
        'plural_label' => 'Members',

        'columns' => [
            'name' => 'Name',
            'email' => 'Email',
            'status' => 'Status',
            'role' => 'Role',
            'joined_at' => 'Joined',
            'invitation_expires_at' => 'Invitation expires',
            'invited_by' => 'Invited by',
        ],

        'sections' => [
            'person' => 'Person',
            'person_hint' => 'Managed by the account holder and not editable here.',
            'access' => 'Access',
        ],

        'hints' => [
            'status' => 'Suspended members keep their history but cannot sign in to this company.',
            'role' => 'Roles apply to this company only.',
        ],

        'actions' => [
            'invite' => 'Invite member',
            'resend' => 'Resend invitation',
            'revoke' => 'Revoke invitation',
            'suspend' => 'Suspend',
            'reinstate' => 'Reinstate',
        ],

        'notifications' => [
            'invitation_sent' => 'Invitation sent.',
            'invitation_resent' => 'A new invitation has been sent. Any earlier link no longer works.',
            'invitation_revoked' => 'Invitation revoked.',
            'suspended' => 'Member suspended.',
            'reinstated' => 'Member reinstated.',
        ],
    ],

    'invitations' => [
        'already_a_member' => 'The user :email is already an active member of this company.',
        'invalid_token' => 'This invitation link is invalid or has expired.',
        'not_pending' => 'This invitation can no longer be withdrawn because it is not pending.',

        'mail' => [
            'subject' => 'Invitation to join :company',
            'greeting' => 'Hello :name,',
            'intro' => 'You have been invited to join :company on the accounting platform.',
            'action' => 'Accept invitation',
            'expiry' => 'This invitation expires in :days days.',
            'ignore' => 'If you were not expecting this invitation, you can ignore this message.',
        ],

        'accept' => [
            'title' => 'Accept invitation',
            'heading' => 'Join :company',
            'password' => 'Password',
            'password_confirmation' => 'Confirm password',
            'submit' => 'Activate account',
            'invalid_heading' => 'Invalid link',
            'invalid_body' => 'This invitation link is invalid or has expired. Ask a company administrator for a new one.',
            'success' => 'Your account is active. You can now sign in.',
        ],
    ],

];
