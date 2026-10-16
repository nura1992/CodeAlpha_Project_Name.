<?php

namespace MedCore\Core;

/**
 * Tiny config repository. Loads PHP arrays from the config/ directory
 * and exposes dot access: Config::get('app.name')
 */
final class Config
{
    private static array $items = [];

    public static function load(string $dir): void
    {
        foreach (glob(rtrim($dir, '/') . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            self::$items[$key] = require $file;
        }
    }

    public static function get(string $key, $default = null)
    {
        $segments = explode('.', $key);
        $value = self::$items;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function set(string $key, $value): void
    {
        self::$items[$key] = $value;
    }
}
