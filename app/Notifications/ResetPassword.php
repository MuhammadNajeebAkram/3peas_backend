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
        $frontendDomain = env('LMS_FRONTEND_URL', env('FRONTEND_URL', 'https://thestudentstimes.com'));
        $frontendUrl = rtrim($frontendDomain, '/') . '/lms/reset-password';

        return (new MailMessage)
            ->subject('Reset Your Password — The Students Times (TST)')
            ->greeting('Hello ' . ($notifiable->name ?? 'User') . ',')
            ->line('You are receiving this email because we received a password reset request for your The Students Times account.')
            ->action('Reset Password', "{$frontendUrl}?token={$this->token}&email=" . urlencode($notifiable->getEmailForPasswordReset()))
            ->line('This password reset link will expire in 60 minutes.')
            ->line('If you did not request a password reset, no further action is required and your account remains safe.');
    }
}
