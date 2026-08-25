<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class OwnerSetupInvitation extends Notification
{
    use Queueable;

    protected $invitation;
    protected $tenant;

    public function __construct($invitation, $tenant = null)
    {
        $this->invitation = $invitation;
        $this->tenant = $tenant;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $token = urlencode($this->invitation->token);
        $slug = $this->tenant->slug ?? null;
        $appDomain = env('APP_DOMAIN');

        // With APP_DOMAIN configured, the owner's front door is their subdomain.
        // Otherwise (local dev / before wildcard DNS) fall back to the apex plus
        // ?tenant={slug} so the login/setup pages can still resolve the org.
        if ($appDomain && $slug) {
            $frontend = 'https://' . $slug . '.' . $appDomain;
            $setupUrl = $frontend . '/lms/admin/setup?token=' . $token;
        } else {
            $frontend = config('saas.frontend_url');
            $setupUrl = $frontend . '/lms/admin/setup?token=' . $token
                . ($slug ? '&tenant=' . urlencode($slug) : '');
        }

        config(['app.url' => $frontend]);
        URL::forceRootUrl($frontend);

        return (new MailMessage)
            ->subject('Set up your institute owner account')
            ->greeting('Hello ' . ($notifiable->name ?? $notifiable->email))
            ->line('You are listed as the owner for "' . ($this->tenant->name ?? 'your institute') . '".')
            ->action('Set up your account', $setupUrl)
            ->line('This link expires in 7 days and can be used once.')
            ->line('If you did not expect this, contact support.');
    }
}
