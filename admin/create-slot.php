<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
ligacu_require_admin();
ligacu_require_post();

function normalize_date_time(string $date, string $time): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        throw new RuntimeException('日付と時刻の形式が正しくありません。');
    }

    return $date . ' ' . $time . ':00';
}

function next_date(string $date): string
{
    return (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('Asia/Tokyo')))
        ->modify('+1 day')
        ->format('Y-m-d');
}

try {
    ligacu_verify_csrf_token();
    ligacu_ensure_schema();

    $date = (string) ($_POST['date'] ?? '');
    $startTime = (string) ($_POST['start_time'] ?? '');
    $endTime = (string) ($_POST['end_time'] ?? '');
    $price = (int) ($_POST['price'] ?? 0);

    if ($date === '' || $startTime === '' || $endTime === '') {
        throw new RuntimeException('日付と時刻を入力してください。');
    }

    if ($price <= 0) {
        throw new RuntimeException('価格は1円以上で入力してください。');
    }

    $start = normalize_date_time($date, $startTime);
    $endDate = $endTime < $startTime ? next_date($date) : $date;
    $end = normalize_date_time($endDate, $endTime);

    if (strtotime($end) <= strtotime($start)) {
        throw new RuntimeException('終了時間は開始時間より後にしてください。');
    }

    $stmt = ligacu_pdo()->prepare(
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
    $stmt->execute([$start, $end, $price]);

    ligacu_redirect_admin('message', '空き枠を追加しました。');
} catch (Throwable $e) {
    ligacu_redirect_admin('error', $e->getMessage());
}
