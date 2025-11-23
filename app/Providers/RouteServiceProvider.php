<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Model;
use App\Models\Organization;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Implicit route model binding
        Route::model('organization', Organization::class);
    }
}