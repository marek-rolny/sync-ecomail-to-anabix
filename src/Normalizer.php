<?php

/**
 * Shared normalization utilities for sync integrations.
 *
 * Stateless — all methods are static, no constructor needed.
 * Designed for reuse across Anabix→Ecomail, Ecomail→Anabix, Sheets→Anabix, etc.
 */
class DataNormalizer
{
    /**
     * Normalize phone number to E.164 format (no spaces): +420777123456
     *
     * Handles common Czech/Slovak inputs:
     *   "+420 777 123 456" → "+420777123456"
     *   "777 123 456"      → "+420777123456"
     *   "00420777123456"   → "+420777123456"
     *   "+420777123456"    → "+420777123456" (no-op)
     *   "608 231 891"      → "+420608231891"
     *   ""                 → null
     *   "N/A"              → null
     *
     * @param string $phone  Raw phone input
     * @param string $defaultCountryCode  Country code without + (default "420" for CZ)
     * @return string|null  E.164 phone or null if not a valid phone
     */
    public static function phoneToE164(string $phone, string $defaultCountryCode = '420'): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        // Preserve leading +, strip everything except digits
        $hasPlus = str_starts_with($phone, '+');
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if ($digits === '' || $digits === null) {
            return null;
        }

        // 00 international prefix → treat as country code
        if (!$hasPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $hasPlus = true; // now it has a country code
        }

        // Single leading 0 (Czech local format: 0777... → 777...)
        if (!$hasPlus && str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = substr($digits, 1);
        }

        // No country code and 9 digits → assume Czech
        if (!$hasPlus && strlen($digits) === 9) {
            $digits = $defaultCountryCode . $digits;
            $hasPlus = true;
        }

        // At this point we should have country code + number
        if (!$hasPlus) {
            // Already has country code in digits (e.g. "420777123456" without +)
            // Heuristic: if starts with 420/421 and total is 12 digits, it's CZ/SK
            if (preg_match('/^(420|421)\d{9}$/', $digits)) {
                $hasPlus = true;
            } else {
                return null; // Can't determine format
            }
        }

        $result = '+' . $digits;

        // E.164 validation: + followed by 7-15 digits
        if (!preg_match('/^\+\d{7,15}$/', $result)) {
            return null;
        }

        return $result;
    }

    /**
     * Format phone to human-readable form with spaces: +420 777 123 456
     *
     * For Czech/Slovak numbers (12 digits with +420/+421), formats as:
     *   +420 XXX XXX XXX
     *
     * For other numbers, groups digits in blocks of 3 after country code.
     *
     * @param string $phone  Raw phone input
     * @param string $defaultCountryCode  Country code without + (default "420")
     * @return string|null  Formatted phone or null if invalid
     */
    public static function phoneToReadable(string $phone, string $defaultCountryCode = '420'): ?string
    {
        $e164 = self::phoneToE164($phone, $defaultCountryCode);
        if ($e164 === null) {
            return null;
        }

        // Czech/Slovak: +420XXXXXXXXX → +420 XXX XXX XXX
        if (preg_match('/^\+(420|421)(\d{3})(\d{3})(\d{3})$/', $e164, $m)) {
            return "+{$m[1]} {$m[2]} {$m[3]} {$m[4]}";
        }

        // Generic: split after country code (assume 1-3 digit code), group rest by 3
        // Try common code lengths: 1 (US +1), 2 (+44), 3 (+420)
        $digits = substr($e164, 1); // remove +
        foreach ([3, 2, 1] as $codeLen) {
            if (strlen($digits) > $codeLen + 4) {
                $code = substr($digits, 0, $codeLen);
                $rest = substr($digits, $codeLen);
                $groups = str_split($rest, 3);
                return '+' . $code . ' ' . implode(' ', $groups);
            }
        }

        return $e164; // fallback: return as-is
    }

    /**
     * Normalize email: lowercase, trim, validate.
     *
     * @return string|null  Normalized email or null if invalid
     */
    public static function normalizeEmail(string $email): ?string
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return null;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    /**
     * Normalize a date string to YYYY-MM-DD format.
     *
     * Handles: YYYY-MM-DD, d.m.Y, d/m/Y, with or without time component.
     * Rejects: null-dates (0000-00-00), dates before 1900.
     *
     * @return string  Normalized date or empty string if invalid
     */
    public static function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return '';
        }

        $formats = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'Y-m-d H:i:s', 'd.m.Y H:i:s'];
        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt !== false) {
                if ((int) $dt->format('Y') < 1900) {
                    return '';
                }
                return $dt->format('Y-m-d');
            }
        }

        try {
            $dt = new \DateTimeImmutable($value);
            if ((int) $dt->format('Y') < 1900) {
                return '';
            }
            return $dt->format('Y-m-d');
        } catch (\Exception $e) {
            return '';
        }
    }
}
