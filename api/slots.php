<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

try {
    ligacu_cleanup_expired_pending_slots();

    $stmt = ligacu_pdo()->query(
        "SELECT id, start_time, end_time, price, status
         FROM availability_slots
         WHERE status = 'available'
           AND start_time > NOW()
         ORDER BY start_time ASC"
    );

    $slots = array_map(static function (array $slot): array {
        return [
            'id' => (int) $slot['id'],
            'start_time' => ligacu_iso_datetime($slot['start_time']),
            'end_time' => ligacu_iso_datetime($slot['end_time']),
            'price' => (int) $slot['price'],
            'status' => $slot['status'],
        ];
    }, $stmt->fetchAll());

    ligacu_json(['slots' => $slots]);
} catch (Throwable $e) {
    ligacu_json(['error' => '空き枠を取得できませんでした。'], 500);
}
