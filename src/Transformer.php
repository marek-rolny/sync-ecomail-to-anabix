<?php

/**
 * Transforms subscriber/form data to Anabix contact format.
 * Supports both Ecomail subscribers and WLB form submissions.
 */
class Transformer
{
    /**
     * Transform an Ecomail subscriber into Anabix contact data.
     *
     * Ecomail fields: email, name (first name), surname (last name), ...
     * Anabix fields: email, name (jmeno), surname (prijmeni), phone, ...
     *
     * @param array $ecomailSubscriber Subscriber data from Ecomail API
     * @return array Contact data for Anabix API
     */
    public static function toAnabixContact(array $ecomailSubscriber): array
    {
        return array_filter([
            'email' => $ecomailSubscriber['email'] ?? '',
            'name' => $ecomailSubscriber['name'] ?? '',
            'surname' => $ecomailSubscriber['surname'] ?? '',
            'phone' => $ecomailSubscriber['phone'] ?? '',
            'company' => $ecomailSubscriber['company'] ?? '',
            'city' => $ecomailSubscriber['city'] ?? '',
            'street' => $ecomailSubscriber['street'] ?? '',
            'zip' => $ecomailSubscriber['zip'] ?? '',
            'country' => $ecomailSubscriber['country'] ?? '',
        ], fn($v) => $v !== '');
    }

    /**
     * Extract the email from an Ecomail subscriber record.
     */
    public static function getEmail(array $ecomailSubscriber): string
    {
        return strtolower(trim($ecomailSubscriber['email'] ?? ''));
    }

    /**
     * Check if the subscriber has a valid email.
     */
    public static function isValid(array $ecomailSubscriber): bool
    {
        $email = self::getEmail($ecomailSubscriber);
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Extract email from a WLB form submission.
     *
     * WLB forms use 'e-mail' as the field name.
     */
    public static function getWlbEmail(array $submission): string
    {
        return strtolower(trim($submission['e-mail'] ?? ''));
    }

    /**
     * Check if a WLB form submission has a valid email.
     */
    public static function isValidWlb(array $submission): bool
    {
        $email = self::getWlbEmail($submission);
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Transform a WLB form submission into Anabix contact data.
     *
     * WLB form fields vary, but common ones:
     * - jmeno (name), e-mail, text, telefon, etc.
     * - meta-data-ip-adresa, meta-data-datum, meta-data-url (system fields)
     *
     * @param array $submission Form submission data from WLB API
     * @return array Contact data for Anabix API
     */
    public static function wlbToAnabixContact(array $submission): array
    {
        $name = $submission['jmeno'] ?? '';
        $firstName = '';
        $lastName = '';

        // Try to split "Jméno Příjmení" into first and last name
        if ($name !== '') {
            $parts = preg_split('/\s+/', trim($name), 2);
            $firstName = $parts[0] ?? '';
            $lastName = $parts[1] ?? '';
        }

        return array_filter([
            'email' => $submission['e-mail'] ?? '',
            'name' => $firstName,
            'surname' => $lastName,
            'phone' => $submission['telefon'] ?? '',
            'company' => $submission['firma'] ?? $submission['spolecnost'] ?? '',
        ], fn($v) => $v !== '');
    }

    /**
     * Build an activity note body from a WLB form submission.
     *
     * Includes all form fields and meta data.
     *
     * @param array $submission Form submission data from WLB API
     * @param int $formId WLB form ID
     * @return string Formatted note body
     */
    public static function wlbToActivityNote(array $submission, int $formId): string
    {
        $lines = [];
        $lines[] = "Formulář z webu (WLB #{$formId})";
        $lines[] = str_repeat('-', 40);

        // System meta fields first
        if (!empty($submission['meta-data-url'])) {
            $lines[] = "URL: " . $submission['meta-data-url'];
        }
        if (!empty($submission['meta-data-datum'])) {
            $lines[] = "Datum: " . $submission['meta-data-datum'];
        }
        if (!empty($submission['meta-data-ip-adresa'])) {
            $lines[] = "IP: " . $submission['meta-data-ip-adresa'];
        }

        $lines[] = '';

        // All other form fields (skip meta fields and internal fields)
        foreach ($submission as $key => $value) {
            if (str_starts_with($key, 'meta-data-') || $key === '_submission_id') {
                continue;
            }

            if (is_array($value)) {
                // Handle select/checkbox fields with id/hodnota structure
                $options = [];
                foreach ($value as $option) {
                    $options[] = $option['hodnota'] ?? $option['value'] ?? json_encode($option);
                }
                $lines[] = "{$key}: " . implode(', ', $options);
            } else {
                $lines[] = "{$key}: {$value}";
            }
        }

        return implode("\n", $lines);
    }
}
