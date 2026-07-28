<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers an invitation link.
 *
 * Queued: a slow or unavailable mail server must not block the person issuing
 * the invitation, and delivery is retryable.
 *
 * The plaintext token exists only here and in the recipient's inbox. It is not
 * logged and is not returned to the inviter.
 */
final class CompanyInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Company $company,
        private readonly string $token,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('invitations.show', ['token' => $this->token]);
        $companyName = $this->company->displayName();

        return (new MailMessage)
            ->subject(__('identity.invitations.mail.subject', ['company' => $companyName]))
            ->greeting(__('identity.invitations.mail.greeting', ['name' => $notifiable->name]))
            ->line(__('identity.invitations.mail.intro', ['company' => $companyName]))
            ->action(__('identity.invitations.mail.action'), $url)
            ->line(__('identity.invitations.mail.expiry', [
                'days' => (int) config('erp.invitations.expires_after_days', 7),
            ]))
            ->line(__('identity.invitations.mail.ignore'));
    }
}
