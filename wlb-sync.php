<?php

/**
 * WLB → Anabix sync script.
 *
 * Fetches new form submissions from WLB (Web Local Business) and:
 * 1. Creates or finds the contact in Anabix by email
 * 2. Creates an activity (note) on the contact with all form data
 *
 * Supports multiple forms - configure WLB_FORMS in .env.
 *
 * Usage: php wlb-sync.php
 * Can be run manually or from cron.
 */

require_once __DIR__ . '/src/env.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/WlbClient.php';
require_once __DIR__ . '/src/AnabixClient.php';
require_once __DIR__ . '/src/Transformer.php';

// Load configuration
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Error: .env file not found. Copy .env.example to .env and configure it.\n");
    exit(1);
}
loadEnv($envFile);

// Initialize components
$logger = new Logger(__DIR__ . '/storage/logs');

$wlb = new WlbClient(
    env('WLB_API_URL', 'https://www.optimal-marketing.cz/wlb'),
    $logger
);

$anabix = new AnabixClient(
    env('ANABIX_API_USER'),
    env('ANABIX_API_TOKEN'),
    env('ANABIX_API_URL'),
    $logger
);

$groupIds = array_filter(array_map('intval', explode(',', env('WLB_ANABIX_GROUP_IDS', env('ANABIX_GROUP_IDS', '')))));

// Parse form configurations: "formId:password,formId:password,..."
$formsConfig = env('WLB_FORMS', '');
if ($formsConfig === '') {
    $logger->error("WLB sync: WLB_FORMS not configured");
    fwrite(STDERR, "Error: WLB_FORMS not configured in .env\n");
    exit(1);
}

$forms = [];
foreach (explode(',', $formsConfig) as $entry) {
    $entry = trim($entry);
    if ($entry === '') {
        continue;
    }
    $parts = explode(':', $entry, 2);
    if (count($parts) !== 2) {
        $logger->error("WLB sync: invalid form config entry", ['entry' => $entry]);
        fwrite(STDERR, "Error: invalid WLB_FORMS entry: {$entry} (expected formId:password)\n");
        exit(1);
    }
    $forms[] = [
        'id' => (int) $parts[0],
        'password' => $parts[1],
    ];
}

// Run sync
$logger->info("WLB sync starting", ['forms' => array_column($forms, 'id')]);

$report = [
    'status' => 'ok',
    'created' => 0,
    'updated' => 0,
    'activities' => 0,
    'skipped' => 0,
    'failed' => 0,
    'errors' => [],
];

foreach ($forms as $form) {
    $formId = $form['id'];
    $password = $form['password'];

    try {
        $submissions = $wlb->getNewSubmissions($formId, $password);

        foreach ($submissions as $submission) {
            $submissionId = $submission['_submission_id'] ?? 'unknown';

            // Check for valid email
            if (!Transformer::isValidWlb($submission)) {
                $logger->warning("WLB sync: submission without valid email", [
                    'form_id' => $formId,
                    'submission_id' => $submissionId,
                ]);
                $report['skipped']++;
                continue;
            }

            $email = Transformer::getWlbEmail($submission);

            try {
                // Find or create contact in Anabix
                $existingContact = $anabix->findContactByEmail($email);
                $contactId = null;

                if ($existingContact !== null) {
                    $contactId = $existingContact['idContact'] ?? $existingContact['id'] ?? null;
                    $logger->info("WLB sync: contact exists", [
                        'email' => $email,
                        'contact_id' => $contactId,
                    ]);
                    $report['updated']++;
                } else {
                    // Create new contact
                    $contactData = Transformer::wlbToAnabixContact($submission);
                    $result = $anabix->createContact($contactData);

                    if ($result !== null) {
                        $contactId = $result['idContact'] ?? $result['id'] ?? $result['data']['idContact'] ?? null;
                        $report['created']++;
                        $logger->info("WLB sync: created contact", [
                            'email' => $email,
                            'contact_id' => $contactId,
                        ]);

                        // Add to groups
                        if ($contactId) {
                            foreach ($groupIds as $groupId) {
                                $anabix->addContactToGroup((int) $contactId, $groupId);
                            }
                        }
                    } else {
                        $report['failed']++;
                        $report['errors'][] = "Failed to create contact: {$email} (form #{$formId})";
                        continue;
                    }
                }

                // Create activity (note) with form data
                if ($contactId) {
                    $title = "Webový formulář #{$formId}";
                    $body = Transformer::wlbToActivityNote($submission, $formId);

                    $activityResult = $anabix->createActivity((int) $contactId, $title, $body, 'note');

                    if ($activityResult !== null) {
                        $report['activities']++;
                        $logger->info("WLB sync: created activity", [
                            'email' => $email,
                            'contact_id' => $contactId,
                            'form_id' => $formId,
                        ]);
                    } else {
                        $report['errors'][] = "Failed to create activity for {$email} (form #{$formId})";
                    }
                }

            } catch (Throwable $e) {
                $report['failed']++;
                $report['errors'][] = "Error processing {$email}: " . $e->getMessage();
                $logger->error("WLB sync: submission processing failed", [
                    'email' => $email,
                    'form_id' => $formId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

    } catch (Throwable $e) {
        $report['status'] = 'error';
        $report['errors'][] = "Form #{$formId}: " . $e->getMessage();
        $logger->error("WLB sync: form fetch failed", [
            'form_id' => $formId,
            'error' => $e->getMessage(),
        ]);
    }
}

$logger->info("WLB sync completed", $report);

// Output JSON report
header('Content-Type: application/json');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
