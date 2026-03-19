<?php
// login.php

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/classes/Auth.php';

header('Location: ' . Auth::loginUrl());
exit;