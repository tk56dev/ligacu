<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ligacu_json(['error' => 'Method not allowed.'], 405);
}

$data = ligacu_json_body();
$slotId = (int) ($data['slot_id'] ?? 0);
$customerName = trim((string) ($data['customer_name'] ?? ''));
$customerEmail = trim((string) ($data['customer_email'] ?? ''));
$customerPhone = trim((string) ($data['customer_phone'] ?? ''));

if ($slotId <= 0 || $customerName === '' || $customerEmail === '' || $customerPhone === '') {
    ligacu_json(['error' => '必要な情報を入力してください。'], 400);
}

if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    ligacu_json(['error' => 'メールアドレスの形式を確認してください。'], 400);
}

$pending = null;

try {
    ligacu_cleanup_expired_pending_slots();
    $pending = ligacu_create_pending_booking([
        'slot_id' => $slotId,
        'customer_name' => $customerName,
        'customer_email' => $customerEmail,
        'customer_phone' => $customerPhone,
    ]);

    $session = ligacu_create_checkout_session(
        (int) $pending['booking_id'],
        $pending['slot'],
        $customerEmail
    );

    ligacu_attach_stripe_session((int) $pending['booking_id'], (string) $session['id']);
    ligacu_json(['url' => $session['url']]);
} catch (Throwable $e) {
    if ($pending) {
        ligacu_release_pending_booking((int) $pending['booking_id'], (int) $pending['slot']['id']);
    }

    ligacu_json(['error' => $e->getMessage()], 400);
}
