<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiters();

        if (app()->runningInConsole()) {
            @ini_set('max_execution_time', '0');
            @ini_set('max_input_time', '-1');
            @set_time_limit(0);

            if (function_exists('register_tick_function')) {
                @register_tick_function(function () {
                    @set_time_limit(0);
                });
            }
        }
    }

    /**
     * Named limiters for the credential endpoints.
     *
     * These replace bare `throttle:10,1`, which keys on IP ALONE and is therefore
     * wrong in both directions: a distributed attempt against one account is
     * unlimited (every request looks like a new client), while a whole office
     * behind one NAT shares ten attempts between them.
     *
     * Every credential route gets TWO limits — one keyed on the account being
     * attacked, one on the source address:
     *
     *   - the per-account key is what actually protects a person, since it counts
     *     attempts regardless of where they come from;
     *   - the per-IP key is what stops one host spraying many different accounts.
     *
     * The account key is lower than the IP key on purpose. Five wrong passwords in
     * a minute is a person mistyping; twenty from one address is a script.
     *
     * Laravel applies both and the STRICTER one wins, so a legitimate user is only
     * ever limited by their own mistakes.
     */
    private function configureRateLimiters(): void
    {
        RateLimiter::for('lms-login', function (Request $request) {
            return [
                Limit::perMinute(5)->by($this->credentialKey($request)),
                Limit::perMinute(20)->by('ip:' . $request->ip()),
            ];
        });

        // Password-reset requests are an email-sending endpoint: the thing being
        // protected is the recipient's inbox as much as the account, so this is
        // capped per-account far tighter than login and does not get a generous
        // per-IP allowance (one academy's students may share an address).
        RateLimiter::for('lms-password-reset', function (Request $request) {
            return [
                Limit::perMinute(3)->by($this->credentialKey($request)),
                Limit::perMinute(10)->by('ip:' . $request->ip()),
            ];
        });
    }

    /**
     * Rate-limit bucket for an account: the submitted identifier plus the source
     * address, lowercased and length-capped.
     *
     * The identifier is whatever the caller typed — an email here, a username
     * there — so the key is read from the fields the credential endpoints actually
     * accept. Normalising to lowercase stops `Ada@x.com` and `ada@x.com` from
     * being two free attempts at the same account.
     *
     * The IP stays in the key so that one shared address (a campus lab, a family)
     * cannot have its attempts against DIFFERENT accounts pooled into a single
     * bucket and lock each other out.
     */
    private function credentialKey(Request $request): string
    {
        $identifier = (string) (
            $request->input('email')
            ?: $request->input('username')
            ?: $request->input('identifier')
            ?: ''
        );

        return 'acct:' . mb_substr(mb_strtolower(trim($identifier)), 0, 120) . '|' . $request->ip();
    }
}