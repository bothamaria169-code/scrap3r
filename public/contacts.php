<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

// The last search is kept in the session so importing from it never spends
// a second round of credits.
$results = $_SESSION['hunter_results'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'search') {
            $results = Hunter::fromSettings()->domainSearch((string) ($_POST['target'] ?? ''), [
                'department' => $_POST['department'] ?? '',
                'seniority'  => $_POST['seniority'] ?? '',
                'job_titles' => $_POST['job_titles'] ?? '',
                'limit'      => $_POST['limit'] ?? 10,
                // Named people only: info@/sales@ inboxes are no use for personal outreach.
                'type'       => 'personal',
            ]);
            $results['query'] = trim((string) $_POST['target']);
            $_SESSION['hunter_results'] = $results;

            if (!$results['emails']) {
                flash('Hunter found no named people for that search. Empty searches cost nothing.', 'warn');
            }
            redirect('contacts.php');
        }

        if ($action === 'import' && $results) {
            $picked = array_flip(array_map('intval', (array) ($_POST['pick'] ?? [])));
            $chosen = array_values(array_intersect_key($results['emails'], $picked));
            $chosen = array_values(array_filter($chosen, static fn($c) => !Suppression::has($c['email'])));

            if (!$chosen) {
                flash('Tick at least one contact to import.', 'err');
                redirect('contacts.php');
            }

            $r = LeadImporter::importHunterContacts($chosen, $results['domain'], (int) $currentUser['id']);
            flash(sprintf('%d new lead(s) added, %d existing lead(s) updated.', $r['created'], $r['updated']));
            redirect('leads.php?has_email=yes');
        }

        if ($action === 'clear') {
            unset($_SESSION['hunter_results']);
            redirect('contacts.php');
        }
    } catch (HunterException $e) {
        flash($e->getMessage(), 'err');
        redirect('contacts.php');
    }
}

$account = null;
$accountError = null;
if (Hunter::configured()) {
    try {
        $account = Hunter::fromSettings()->account();
    } catch (HunterException $e) {
        $accountError = $e->getMessage();
    }
}

// Flag results that are already leads, or have opted out, before anyone ticks them.
$existing = [];
$suppressed = [];
foreach ($results['emails'] ?? [] as $c) {
    if (Db::value('SELECT 1 FROM leads WHERE email = :e', ['e' => $c['email']])) {
        $existing[$c['email']] = true;
    }
    if (Suppression::has($c['email'])) {
        $suppressed[$c['email']] = true;
    }
}

render('contacts', [
    'title'        => 'Find contacts',
    'account'      => $account,
    'accountError' => $accountError,
    'results'      => $results,
    'existing'     => $existing,
    'suppressed'   => $suppressed,
    'prefill'      => (string) ($_GET['q'] ?? ($results['query'] ?? '')),
]);
