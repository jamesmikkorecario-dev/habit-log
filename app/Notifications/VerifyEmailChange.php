<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailChange extends Notification
{
    public function __construct(#[\SensitiveParameter] public string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Confirm your habit.log email change')
            ->line('An email change was requested for a habit.log account. The current email stays active until confirmation.')
            ->action('Review email change', $this->url)
            ->line('This link expires in 60 minutes. Sign in to the requesting account and confirm with your current password. If you did not request this, you can ignore this email.');
    }
}
