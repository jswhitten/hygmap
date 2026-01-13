<?php
declare(strict_types=1);

/**
 * Configuration management for user preferences
 *
 * Handles loading and saving user preferences stored in session.
 * Provides default values for all configuration options.
 */
final class Config
{
    /** Default configuration values */
    private const DEFAULTS = [
        'unit'          => 'ly',
        'grid'          => 20,
        'fic_names'     => 0,       // 0=none, other values are world IDs from fic_worlds table
        'image_type'    => 'normal',
        'image_size'    => 600,
        'max_line'      => 0,
        'm_limit'       => 20.0,
        'm_limit_label' => 8.0,
        'show_signals'  => true,
        'profiling'     => false,   // Enable timing output for debugging
    ];

    /**
     * Load configuration from session, with defaults
     *
     * @return array<string, mixed> Configuration values
     */
    public static function load(): array
    {
        return $_SESSION['cfg'] ?? self::DEFAULTS;
    }

    /**
     * Save configuration to session
     *
     * Merges new values with existing configuration.
     *
     * @param array<string, mixed> $values New values to save
     */
    public static function save(array $values): void
    {
        $_SESSION['cfg'] = array_merge(self::load(), $values);
    }

    /**
     * Get a single configuration value
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $cfg = self::load();
        return $cfg[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    /**
     * Get default configuration values
     *
     * @return array<string, mixed> Default configuration
     */
    public static function getDefaults(): array
    {
        return self::DEFAULTS;
    }
}
