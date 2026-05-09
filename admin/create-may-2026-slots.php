<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
ligacu_require_admin();
ligacu_require_post();

$price = 18000;
$slots = [
    ['10:00:00', '11:30:00'],
    ['15:00:00', '16:30:00'],
    ['20:00:00', '21:30:00'],
];

$pdo = ligacu_pdo();
$created = 0;
$skipped = 0;

try {
    ligacu_verify_csrf_token();
    ligacu_ensure_schema();
    $pdo->beginTransaction();

    $exists = $pdo->prepare(
        "SELECT id
         FROM availability_slots
         WHERE start_time = ?
           AND end_time = ?
         LIMIT 1"
    );

    $insert = $pdo->prepare(
        "INSERT INTO availability_slots (
            start_time,
            end_time,
            price,
            status,
            created_at,
            updated_at
         )
         VALUES (?, ?, ?, 'available', NOW(), NOW())"
    );

    for ($day = 1; $day <= 31; $day++) {
        $date = sprintf('2026-05-%02d', $day);

        foreach ($slots as [$startTime, $endTime]) {
            $start = $date . ' ' . $startTime;
            $end = $date . ' ' . $endTime;

            $exists->execute([$start, $end]);
            if ($exists->fetch()) {
                $skipped++;
                continue;
            }

            $insert->execute([$start, $end, $price]);
            $created++;
        }
    }

    $pdo->commit();
    ligacu_redirect_admin('message', "2026年5月の空き枠を作成しました。追加: {$created}件 / 既存のためスキップ: {$skipped}件");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    ligacu_redirect_admin('error', '2026年5月の空き枠作成に失敗しました。' . $e->getMessage());
}
