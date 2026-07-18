<?php

use Botble\Base\Facades\AdminHelper;
use Illuminate\Support\Facades\Route;

Route::group(['namespace' => 'Botble\PluginManagement\Http\Controllers'], function (): void {
    AdminHelper::registerRoutes(function (): void {
        Route::group(['prefix' => 'plugins'], function (): void {

            if (config('packages.plugin-management.general.enable_plugin_manager', true)) {
                Route::redirect('', 'plugins/installed');
                Route::get('installed', [
                    'as' => 'plugins.index',
                    'uses' => 'PluginManagementController@index',
                ]);

                Route::put('status', [
                    'as' => 'plugins.change.status',
                    'uses' => 'PluginManagementController@update',
                    'middleware' => 'preventDemo',
                    'permission' => 'plugins.index',
                ]);

                Route::delete('{plugin}', [
                    'as' => 'plugins.remove',
                    'uses' => 'PluginManagementController@destroy',
                    'middleware' => 'preventDemo',
                    'permission' => 'plugins.index',
                ]);

                Route::post('check-requirement', [
                    'as' => 'plugins.check-requirement',
                    'uses' => 'PluginManagementController@checkRequirement',
                    'permission' => 'plugins.index',
                ]);
            }
        });
    });
});
