<?php

/**
 * Transforms Anabix contact data into Ecomail subscriber payload.
 *
 * Handles:
 *  - Standard field mapping (name, surname, email, phone, gender)
 *  - pretitle/surtitle extraction from Anabix 'title' field (UTF-8 safe)
 *  - Organization data (company, street, city, zip, country)
 *  - Tags from list memberships
 *  - Custom fields: vip, primaryContact, projectManager, prvniObchod, anabixId
 *  - Birthday normalization from custom field
 *  - Owner ID → name mapping for projectManager
 *  - Configurable Anabix custom field IDs via ANABIX_CF_* env vars
 */
class Transformer
{
    /** @var array<int, string>  idOwner => full name */
    private array $ownerMap;

    /** @var string  Default owner name when idOwner is not in ownerMap */
    private string $defaultOwner;

    /** @var array<string, int>  Ecomail merge tag => Anabix custom field ID */
    private array $customFieldMap;

    /** @var int|null  Anabix custom field ID for birthday */
    private ?int $birthdayFieldId;

    public function __construct(
        array $ownerMap = [],
        array $customFieldMap = [],
        ?int $birthdayFieldId = null,
        string $defaultOwner = 'Robot Karel'
    ) {
        $this->ownerMap = $ownerMap;
        $this->defaultOwner = $defaultOwner;
        $this->customFieldMap = $customFieldMap;
        $this->birthdayFieldId = $birthdayFieldId;
    }

    /**
     * Transform a single Anabix contact into Ecomail subscriber payload.
     *
     * @param array      $contact       Anabix contact data
     * @param array|null $organization  Organization data (if fetched separately)
     * @return array|null  Ecomail subscriber payload, or null if no valid email
     */
    public function transform(array $contact, ?array $organization = null): ?array
    {
        $email = strtolower(trim($contact['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $firstName = trim($contact['firstName'] ?? $contact['name'] ?? '');
        $lastName = trim($contact['lastName'] ?? $contact['surname'] ?? '');
        $title = trim($contact['title'] ?? '');

        $subscriber = [
            'email' => $email,
            'name' => $firstName,
            'surname' => $lastName,
            'phone' => $this->extractPhone($contact),
            'gender' => $this->normalizeGender($contact['sex'] ?? $contact['gender'] ?? ''),
            'pretitle' => self::extractPretitle($title, $firstName),
            'surtitle' => self::extractSurtitle($title, $lastName),
        ];

        // Organization data → company fields
        $org = $organization ?? $contact['organization'] ?? null;
        if (is_array($org)) {
            $subscriber['company'] = $org['title'] ?? $org['name'] ?? '';
            $subscriber['street'] = $org['billingStreet'] ?? $org['street'] ?? '';
            $subscriber['city'] = $org['billingCity'] ?? $org['city'] ?? '';
            $subscriber['zip'] = $org['billingCode'] ?? $org['billingZip'] ?? $org['zip'] ?? '';
            $subscriber['country'] = $org['billingCountry'] ?? $org['country'] ?? '';
        }

        // Tags from lists
        $tags = $this->buildTags($contact);
        if (!empty($tags)) {
            $subscriber['tags'] = $tags;
        }

        // Birthday from custom field
        $birthday = $this->extractBirthday($contact);
        if ($birthday !== null) {
            $subscriber['birthday'] = $birthday;
        }

        // Custom fields
        $subscriber['custom_fields'] = $this->buildCustomFields($contact);

        // Remove empty string / null values (cleaner payload)
        $subscriber = array_filter($subscriber, fn($v) => $v !== '' && $v !== null);

        // custom_fields must always be present
        if (!isset($subscriber['custom_fields'])) {
            $subscriber['custom_fields'] = (object) [];
        }

        return $subscriber;
    }

    // ── Pretitle / Surtitle ───────────────────────────────────────────

    /**
     * Extract academic title before the first name from Anabix 'title' field.
     *
     * Algorithm (UTF-8 safe):
     *  1. Find position of firstName in title (mb_strpos)
     *  2. Verify a word boundary follows firstName (space, comma, or end)
     *  3. Everything before that position is the pretitle
     *
     * Examples:
     *  "Ing. Mgr. Jan Mochťák"  → "Ing. Mgr."
     *  "Jan Novák"              → null (pos=0, no pretitle)
     *  "Janković Ing."          → null ("Jan" found but "k" follows)
     *  "Ing. Jan Novák, Ph.D."  → "Ing."
     */
    public static function extractPretitle(string $title, string $firstName): ?string
    {
        if ($title === '' || $firstName === '') {
            return null;
        }

        $pos = mb_strpos($title, $firstName);

        if ($pos === false || $pos === 0) {
            return null;
        }

        // Character after firstName must be space, comma, or end of string
        $afterPos = $pos + mb_strlen($firstName);
        if ($afterPos < mb_strlen($title)) {
            $charAfter = mb_substr($title, $afterPos, 1);
            if ($charAfter !== ' ' && $charAfter !== ',') {
                return null;
            }
        }

        $pretitle = trim(mb_substr($title, 0, $pos));

        return $pretitle !== '' ? $pretitle : null;
    }

    /**
     * Extract academic title after the last name from Anabix 'title' field.
     *
     * Examples:
     *  "Ing. Jan Novák, Ph.D."   → "Ph.D."
     *  "Ing. Mgr. Jan Mochťák"  → null (nothing after lastName)
     */
    public static function extractSurtitle(string $title, string $lastName): ?string
    {
        if ($title === '' || $lastName === '') {
            return null;
        }

        $pos = mb_strpos($title, $lastName);

        if ($pos === false) {
            return null;
        }

        $afterName = mb_substr($title, $pos + mb_strlen($lastName));
        $afterName = ltrim($afterName, ' ,');
        $afterName = trim($afterName);

        return $afterName !== '' ? $afterName : null;
    }

    // ── Field helpers ─────────────────────────────────────────────────

    private function normalizeGender(string $value): string
    {
        $value = mb_strtolower(trim($value));

        if (in_array($value, ['male', 'muž', 'muz', 'm', '1'], true)) {
            return 'male';
        }
        if (in_array($value, ['female', 'žena', 'zena', 'f', 'ž', 'z', '2'], true)) {
            return 'female';
        }

        return '';
    }

    private function extractPhone(array $contact): string
    {
        foreach (['phoneNumber', 'phone', 'mobile', 'telephone'] as $key) {
            $value = trim($contact[$key] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Build tags from contact's list memberships.
     * Deduplicated, each tag max 50 bytes (Ecomail limit).
     */
    private function buildTags(array $contact): array
    {
        $tags = [];

        // List/group titles
        $lists = $contact['lists'] ?? $contact['groups'] ?? [];
        foreach ($lists as $list) {
            $title = is_array($list) ? ($list['title'] ?? $list['name'] ?? '') : (string) $list;
            $title = trim($title);
            if ($title !== '') {
                $tags[] = $title;
            }
        }

        // Direct tags/labels
        foreach (['tags', 'labels', 'categories'] as $key) {
            if (!isset($contact[$key])) {
                continue;
            }
            $items = is_array($contact[$key]) ? $contact[$key] : explode(',', $contact[$key]);
            foreach ($items as $item) {
                $item = trim(is_array($item) ? ($item['name'] ?? $item['title'] ?? '') : $item);
                if ($item !== '') {
                    $tags[] = $item;
                }
            }
        }

        // Deduplicate, truncate
        $tags = array_unique($tags);
        $tags = array_map(function (string $tag): string {
            while (strlen($tag) > 50) {
                $tag = mb_substr($tag, 0, mb_strlen($tag) - 1);
            }
            return $tag;
        }, $tags);

        return array_values($tags);
    }

    private function extractBirthday(array $contact): ?string
    {
        if ($this->birthdayFieldId === null) {
            return null;
        }

        $value = $this->getCustomFieldValue($contact, $this->birthdayFieldId);

        if ($value === null || $value === '') {
            return null;
        }

        $formats = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'Y-m-d H:i:s', 'd.m.Y H:i:s'];
        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Build the custom_fields map for Ecomail subscriber.
     */
    private function buildCustomFields(array $contact): array
    {
        $fields = [];

        // VIP flag
        $fields['vip'] = !empty($contact['vip']) ? '1' : '0';

        // Primary contact flag
        $fields['primaryContact'] = !empty($contact['primaryContact']) ? '1' : '0';

        // Project manager (idOwner → name via ownerMap, fallback to defaultOwner)
        $idOwner = $contact['idOwner'] ?? $contact['ownerId'] ?? $contact['idUser'] ?? null;
        if ($idOwner !== null && isset($this->ownerMap[(int) $idOwner])) {
            $fields['projectManager'] = $this->ownerMap[(int) $idOwner];
        } else {
            $fields['projectManager'] = $this->defaultOwner;
        }

        // Anabix contact ID (for reverse lookup in activities sync)
        $idContact = $contact['idContact'] ?? $contact['id'] ?? null;
        if ($idContact !== null) {
            $fields['anabixId'] = (string) $idContact;
        }

        // Dynamic custom fields from ANABIX_CF_* env mapping
        foreach ($this->customFieldMap as $ecomailKey => $anabixFieldId) {
            $value = $this->getCustomFieldValue($contact, $anabixFieldId);
            if ($value !== null && $value !== '') {
                $fields[$ecomailKey] = $value;
            }
        }

        // prvniObchod must be a valid date (YYYY-MM-DD) or empty
        if (isset($fields['prvniObchod'])) {
            $fields['prvniObchod'] = self::normalizeDate($fields['prvniObchod']);
        }

        return $fields;
    }

    /**
     * Read a custom field value from an Anabix contact by field ID.
     *
     * Handles:
     *  - {5: "value", 10: "value"} (keyed by ID)
     *  - [{idCustomField: 5, value: "..."}, ...] (array of objects)
     */
    private function getCustomFieldValue(array $contact, int $fieldId): ?string
    {
        $customFields = $contact['customFields'] ?? $contact['custom_fields'] ?? [];

        if (empty($customFields)) {
            return null;
        }

        // Keyed by ID (value may be scalar, array with 'value' key, or flat array)
        $raw = $customFields[$fieldId] ?? $customFields[(string) $fieldId] ?? null;
        if ($raw !== null) {
            return self::scalarize($raw);
        }

        // Array of objects: [{idCustomField: 5, value: "..."}, ...]
        if (is_array($customFields) && isset($customFields[0])) {
            foreach ($customFields as $cf) {
                if (!is_array($cf)) {
                    continue;
                }
                $cfId = $cf['idCustomField'] ?? $cf['id'] ?? null;
                if ((int) $cfId === $fieldId) {
                    return self::scalarize($cf['value'] ?? $cf['data'] ?? '');
                }
            }
        }

        return null;
    }

    /**
     * Normalize a value to YYYY-MM-DD date string, or empty string if invalid.
     */
    private static function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $formats = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'Y-m-d H:i:s', 'd.m.Y H:i:s'];
        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        // Try generic parsing as last resort
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Safely convert a value to string. Handles arrays/objects that Anabix
     * sometimes wraps around scalar values.
     */
    private static function scalarize($value): string
    {
        if (is_scalar($value) || $value === null) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            // {"value": "x"} or {"data": "x"}
            if (isset($value['value'])) {
                return trim((string) $value['value']);
            }
            if (isset($value['data'])) {
                return trim((string) $value['data']);
            }
            // Flat array like ["2024-01-15"] — take first scalar element
            foreach ($value as $item) {
                if (is_scalar($item) && (string) $item !== '') {
                    return trim((string) $item);
                }
            }
        }

        return '';
    }
}
