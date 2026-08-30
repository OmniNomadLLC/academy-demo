<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use Illuminate\Support\Facades\Gate;

class HorizonServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Restrict Horizon dashboard access to admins only
        Horizon::auth(function ($request) {
            $user = $request->user();
            if (!$user) return false;
            return Gate::forUser($user)->allows('viewHorizon');
        });
    }
}
