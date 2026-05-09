<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
ligacu_require_admin();
ligacu_require_post();

try {
    ligacu_verify_csrf_token();
    ligacu_ensure_schema();

    $slotId = (int) ($_POST['slot_id'] ?? 0);
    $allowBooked = ($_POST['allow_booked'] ?? '') === 'true';

    if ($slotId <= 0) {
        throw new RuntimeException('空き枠が見つかりません。');
    }

    $pdo = ligacu_pdo();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT id, status FROM availability_slots WHERE id = ? FOR UPDATE");
    $stmt->execute([$slotId]);
    $slot = $stmt->fetch();

    if (!$slot) {
        throw new RuntimeException('空き枠が見つかりません。');
    }

    if ($slot['status'] === 'booked' && !$allowBooked) {
        throw new RuntimeException('予約済み枠を削除するには確認が必要です。');
    }

    $deleteBookings = $pdo->prepare("DELETE FROM bookings WHERE slot_id = ?");
    $deleteBookings->execute([$slotId]);

    $deleteSlot = $pdo->prepare("DELETE FROM availability_slots WHERE id = ?");
    $deleteSlot->execute([$slotId]);

    $pdo->commit();
    ligacu_redirect_admin('message', '空き枠を削除しました。');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ligacu_redirect_admin('error', $e->getMessage());
}
