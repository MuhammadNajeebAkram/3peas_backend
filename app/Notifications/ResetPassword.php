<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPassword extends BaseResetPassword
{
    /**
     * Create a new notification instance.
     *
     * @param  string  $token
     */
    public function __construct($token)
    {
        parent::__construct($token);
    }

    /**
     * Build the reset password email notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        //$frontendUrl = 'http://localhost:3000/reset-password';
        $frontendUrl = 'https://lms.al-faraabi.com/reset-password';
        return (new MailMessage)
            ->subject('Reset Your Al-Faraabi Password')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', "{$frontendUrl}?token={$this->token}&email=" . urlencode($notifiable->getEmailForPasswordReset()))
            ->line('If you did not request a password reset, no further action is required.');
    }
}
