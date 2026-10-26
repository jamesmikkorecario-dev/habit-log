<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailChanged extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your habit.log login email changed')
            ->line('Your login email was changed after mailbox verification and password confirmation. Other sessions and remembered logins were signed out.')
            ->line('If you did not make this change, contact the service operator. This message cannot reverse the change or grant account access.');
    }
}
