<?php

declare(strict_types=1);

namespace App\Enums;

enum SupportedLanguage: string
{
    case EN = 'en';
    case DE = 'de';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function default(): self
    {
        return self::EN;
    }

    /**
     * @return array<string, string>
     */
    public static function toArray(): array
    {
        return array_reduce(self::cases(), function ($carry, $item) {
            $carry[$item->value] = $item->label();

            return $carry;
        }, []);
    }

    public static function isSupported(?string $locale): bool
    {
        if ($locale === null) {
            return false;
        }

        return in_array($locale, self::values(), true);
    }

    public static function cookieName(): string
    {
        return 'webguard_locale';
    }

    public static function cookieDurationMinutes(): int
    {
        return 60 * 24 * 365;
    }

    public function label(): string
    {
        return match ($this) {
            self::EN => 'English',
            self::DE => 'Deutsch',
        };
    }
}
