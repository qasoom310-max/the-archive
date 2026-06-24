<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The 6-digit verification code emailed to a regular admin before a sensitive
 * action (user/database delete or edit). Sent synchronously so it lands while
 * the admin is at the prompt. See {@see \App\Erp\Security\TwoFactorGate}.
 */
final class AdminActionOtp extends Notification
{
    public function __construct(
        public readonly string $code,
        public readonly string $action,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('Your verification code'))
            ->line(__('Use this code to confirm: :action', ['action' => $this->actionLabel()]))
            ->line('**' . $this->code . '**')
            ->line(__('The code expires in 10 minutes. If you did not request it, you can ignore this email.'));
    }

    private function actionLabel(): string
    {
        return match ($this->action) {
            'user.delete' => __('deleting a user'),
            'user.update' => __('editing a user'),
            'workspace.delete' => __('deleting a database'),
            'workspace.rename' => __('renaming a database'),
            default => __('a sensitive action'),
        };
    }
}
