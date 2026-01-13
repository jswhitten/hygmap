<?php
declare(strict_types=1);

/**
 * Unit conversion utilities for astronomical distances
 *
 * Handles conversions between parsecs (pc) and light-years (ly).
 * Parsecs are used internally for database storage and calculations.
 * Light-years are an alternative display unit for user preference.
 */
final class Units
{
    /** Light-years per parsec conversion factor */
    public const LY_PER_PC = 3.26156;

    /**
     * Convert from UI units to parsecs (for queries and calculations)
     *
     * @param float $value Value in UI units
     * @param string $unit UI unit ('pc' or 'ly')
     * @return float Value in parsecs
     */
    public static function toParsecs(float $value, string $unit): float
    {
        return $unit === 'ly' ? $value / self::LY_PER_PC : $value;
    }

    /**
     * Convert from parsecs to UI units (for display)
     *
     * @param float $value Value in parsecs
     * @param string $unit Target UI unit ('pc' or 'ly')
     * @return float Value in UI units
     */
    public static function fromParsecs(float $value, string $unit): float
    {
        return $unit === 'ly' ? $value * self::LY_PER_PC : $value;
    }

    /**
     * Convert from UI units to light-years (for external services like ISDB)
     *
     * @param float $value Value in UI units
     * @param string $unit UI unit ('pc' or 'ly')
     * @return float Value in light-years
     */
    public static function toLightYears(float $value, string $unit): float
    {
        return $unit === 'pc' ? $value * self::LY_PER_PC : $value;
    }
}
