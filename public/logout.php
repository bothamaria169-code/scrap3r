<?php
declare(strict_types=1);
define('PUBLIC_PAGE', true);
require_once __DIR__ . '/_init.php';
Auth::logout();
redirect('login.php');
