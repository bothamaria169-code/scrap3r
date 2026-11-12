<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

Auth::start();

// Public pages (booking, unsubscribe) opt out by defining PUBLIC_PAGE first.
if (!defined('PUBLIC_PAGE')) {
    $currentUser = Auth::require();
    csrf_check();
}
