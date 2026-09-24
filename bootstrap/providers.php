<?php

/*
|--------------------------------------------------------------------------
| Application Service Providers
|--------------------------------------------------------------------------
|
| This file did not exist, so no App\Providers class was ever booted — which
| meant AppServiceProvider::boot() never ran. That was invisible until the
| named credential rate limiters were added to it: `throttle:lms-login` on the
| login routes then threw "Rate limiter [lms-login] is not defined", which is a
| hard 500 on every sign-in.
|
| App\Providers\EventServiceProvider and RouteServiceProvider are deliberately
| NOT listed. Both are empty stubs, and routes are registered through
| withRouting() in bootstrap/app.php, so listing RouteServiceProvider would add
| a second route-registration path for no benefit.
|
*/

return [
    App\Providers\AppServiceProvider::class,
];
