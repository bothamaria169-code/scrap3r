<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'save') {
        $values = [];
        foreach (array_keys(Settings::DEFAULTS) as $key) {
            if ($key === 'brand_logo') {
                continue;   // only ever set by an image upload below, never from a form field
            }
            if (array_key_exists($key, $_POST)) {
                // Blank password means "keep the stored one".
                if (in_array($key, ['smtp_pass', 'hunter_api_key'], true) && trim((string) $_POST[$key]) === '') {
                    continue;
                }
                $values[$key] = trim((string) $_POST[$key]);
            }
        }
        if (isset($values['brand_color']) && !EmailDesign::isHex($values['brand_color'])) {
            $values['brand_color'] = '';
        }
        if (!empty($_POST['remove_logo'])) {
            $values['brand_logo'] = '';
        }
        if (!empty($_FILES['brand_logo_file']['name'])) {
            $logo = EmailDesign::storeImage($_FILES['brand_logo_file']);
            if (is_string($logo)) {
                flash('Logo not saved: ' . $logo, 'err');
                redirect('settings.php');
            }
            $values['brand_logo'] = $logo['url'];
        }
        Settings::setMany($values);
        flash('Settings saved.');
        redirect('settings.php');
    }

    if ($action === 'test') {
        $to = trim((string) ($_POST['test_to'] ?? ''));
        if (!is_valid_email($to)) {
            $testResult = ['ok' => false, 'text' => 'Enter a valid address to send the test to.'];
        } else {
            $smtp = Smtp::fromSettings();
            try {
                $smtp->connect();
                $from = Settings::get('from_email');
                $body = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n"
                      . 'From: <' . $from . ">\r\nTo: <" . $to . ">\r\n"
                      . "Subject: scrap3r SMTP test\r\n\r\n"
                      . "If you are reading this, outbound mail works.\r\n";
                $smtp->send($from, [$to], $body);
                $smtp->quit();
                $testResult = ['ok' => true, 'text' => "Test message accepted by the server for $to.\n\n" . $smtp->transcript()];
            } catch (SmtpException $e) {
                $smtp->quit();
                $testResult = ['ok' => false, 'text' => $e->getMessage() . "\n\n" . $smtp->transcript()];
            }
        }
    }
}

render('settings', [
    'title' => 'Settings',
    'settings' => Settings::all(),
    'testResult' => $testResult,
    'suppressions' => Db::all('SELECT * FROM suppressions ORDER BY created_at DESC LIMIT 50'),
    'reps' => Db::all('SELECT * FROM users ORDER BY name'),
]);
