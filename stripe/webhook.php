<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

$payload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret = (string) ligacu_config()['stripe']['webhook_secret'];

if (!ligacu_verify_stripe_signature($payload, $signature, $secret)) {
    http_response_code(400);
    echo 'Webhook signature verification failed.';
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo 'Invalid JSON.';
    exit;
}

try {
    $type = $event['type'] ?? '';
    $session = $event['data']['object'] ?? [];
    $metadata = $session['metadata'] ?? [];
    $bookingId = (int) ($metadata['booking_id'] ?? 0);
    $slotId = (int) ($metadata['slot_id'] ?? 0);
    $sessionId = (string) ($session['id'] ?? '');

    if ($type === 'checkout.session.completed' && ($session['payment_status'] ?? '') === 'paid') {
        ligacu_confirm_paid_booking($bookingId, $slotId, $sessionId);
    }

    if ($type === 'checkout.session.expired') {
        ligacu_release_pending_booking($bookingId, $slotId);
    }

    ligacu_json(['received' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Webhook handling failed.';
}
