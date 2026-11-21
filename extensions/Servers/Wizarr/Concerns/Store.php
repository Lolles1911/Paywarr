<?php

namespace Paymenter\Extensions\Servers\Wizarr\Concerns;

use Throwable;

/**
 * A small lasting store for Paywarr's own things (status messages, monthly
 * reports, the clean-up log): rows in Paymenter's properties table under
 * model "paywarr", so clearing the cache keeps them. Part of Wizarr (Paywarr).
 */
trait Store
{
    public static function storeGet(string $key, mixed $default = null): mixed
    {
        try {
            $value = \App\Models\Property::where('model_type', 'paywarr')->where('model_id', 0)->where('key', $key)->value('value');
        } catch (Throwable $e) {
            return $default;
        }
        if ($value === null) {
            return $default;
        }
        $decoded = json_decode((string) $value, true);

        return $decoded ?? $default;
    }

    public static function storePut(string $key, mixed $value): void
    {
        \App\Models\Property::updateOrCreate(['model_type' => 'paywarr', 'model_id' => 0, 'key' => $key], ['name' => 'Paywarr: ' . $key, 'value' => json_encode($value)]);
    }
}
