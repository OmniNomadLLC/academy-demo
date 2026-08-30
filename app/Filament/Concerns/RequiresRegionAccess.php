<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Facades\Auth;

trait RequiresRegionAccess
{
    protected static function requiredRegion(): ?string
    {
        return property_exists(static::class, 'requiredRegion') ? static::$requiredRegion : null;
    }

    protected static function userHasRequiredRegion(): bool
    {
        $region = static::requiredRegion();
        if (! $region) {
            return true;
        }

        $user = Auth::user();
        if (! $user) {
            return false;
        }

        if (! method_exists($user, 'restrictsByRegion') || ! method_exists($user, 'hasRegionAccess')) {
            return true;
        }

        if (! $user->restrictsByRegion()) {
            return true;
        }

        return $user->hasRegionAccess($region);
    }

    protected static function ukManagerAllowed(): bool
    {
        $user = Auth::user();
        if (! $user || ! method_exists($user, 'isUkManager')) {
            return true;
        }

        if (! $user->isUkManager()) {
            return true;
        }

        if (property_exists(static::class, 'allowsUkManagerAccess')) {
            return (bool) static::$allowsUkManagerAccess;
        }

        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (! static::userHasRequiredRegion()) {
            return false;
        }

        if (! static::ukManagerAllowed()) {
            return false;
        }

        return parent::shouldRegisterNavigation();
    }

    public static function canAccess(): bool
    {
        if (! static::userHasRequiredRegion()) {
            return false;
        }

        if (! static::ukManagerAllowed()) {
            return false;
        }

        return parent::canAccess();
    }
}
