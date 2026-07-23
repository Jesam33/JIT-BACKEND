<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
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
}