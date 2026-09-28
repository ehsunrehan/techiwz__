<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

if (! function_exists('settings')) {
    /**
     * Read a platform setting value, or all settings when no key is given.
     */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        $all = Cache::rememberForever('platform_settings', function () {
            return Setting::pluck('value', 'key')->toArray();
        });

        if ($key === null) {
            return $all;
        }

        return $all[$key] ?? $default;
    }
}

if (! function_exists('settings_set')) {
    /**
     * Persist a platform setting and refresh the cached copy.
     */
    function settings_set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => is_array($value) ? json_encode($value) : $value]);
        Cache::forget('platform_settings');
    }
}

if (! function_exists('dashboard_layout')) {
    /**
     * The Blade shell for shared pages (products, markets, farmers, profile,
     * notifications): the role's dashboard layout when signed in, the public
     * marketing layout for guests.
     */
    function dashboard_layout(): string
    {
        return match (auth()->user()?->role) {
            'farmer' => 'layouts.farmer',
            'admin' => 'layouts.admin',
            'customer' => 'layouts.customer',
            default => 'layouts.app',
        };
    }
}
