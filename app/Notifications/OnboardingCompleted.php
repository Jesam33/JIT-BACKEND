<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class OnboardingCompleted extends Notification
{
    use Queueable;

    protected $tenant;

    public function __construct($tenant)
    {
        $this->tenant = $tenant;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        // Prefer the frontend/base URL used by the Next.js app when generating links in emails.
        $frontend = config('saas.frontend_url');
        // What this org calls itself in customer-facing copy — "Institute" for the
        // primary (Jorsas), "Online Academy" (or the owner's override) otherwise.
        $label = $this->tenant ? $this->tenant->entityLabelArray()['singular'] : 'Online Academy';

        // Build a friendly frontend onboarding status URL that the Next.js app understands.
        $tenantId = $this->tenant->id ?? null;
        $tenantName = $this->tenant->name ?? null;
        $query = $tenantId ? ('?tenant=' . urlencode($tenantId) . ($tenantName ? '&tenant_name=' . urlencode($tenantName) : '')) : '';
        // For owners, link to the frontend onboarding status page (owner-friendly)
        $frontendOnboarding = rtrim($frontend, '/') . '/onboarding' . ($tenantId ? ('?tenant=' . urlencode($tenantId) . ($tenantName ? '&tenant_name=' . urlencode($tenantName) : '')) : '');

        // Ensure the email templates reference the frontend host for links and branding.
        config(['app.url' => $frontend]);
        URL::forceRootUrl($frontend);

        // Also provide an owner admin frontend link where the tenant owner can sign in.
        $frontendAdmin = rtrim($frontend, '/') . '/lms/admin' . ($tenantId ? ('?tenant=' . urlencode($tenantId)) : '');

        return (new MailMessage)
            ->subject('Your ' . $label . ' onboarding is complete')
            ->greeting('Hello ' . ($notifiable->name ?? $notifiable->email))
            ->line('Your ' . $label . ' "' . ($this->tenant->name ?? ('your ' . $label)) . '" is ready.')
            ->action('View onboarding status', $frontendOnboarding)
            ->line('When ready, sign in to your owner console.')
            ->action('Go to dashboard', $frontendAdmin)
            ->line('If you did not expect this, contact support.');
    }
}
