<?php

/**
 * Transforms Ecomail subscriber data to Anabix contact format.
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
}
