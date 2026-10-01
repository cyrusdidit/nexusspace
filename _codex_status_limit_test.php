<?php

declare(strict_types=1);

session_start();
$_SESSION['user_id'] = 3;
$_SESSION['profile_edit_token'] = 'codex-status-limit-test';
header('Content-Type: text/plain; charset=utf-8');
echo 'ready';
