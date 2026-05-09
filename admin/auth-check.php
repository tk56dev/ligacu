<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
ligacu_require_admin();

header('Content-Type: text/plain; charset=utf-8');
echo "admin auth ok\n";
echo "PHP_AUTH_USER=" . ($_SERVER['PHP_AUTH_USER'] ?? '') . "\n";
echo "HTTP_AUTHORIZATION=" . (ligacu_authorization_header() !== '' ? 'present' : 'missing') . "\n";
