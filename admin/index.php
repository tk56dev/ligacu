<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
ligacu_require_admin();
$csrfToken = ligacu_csrf_token();

function admin_time_options(string $selected = ''): string
{
    $html = '<option value="">選択してください</option>';
    for ($hour = 8; $hour <= 25; $hour++) {
        foreach (['00', '10', '20', '30', '40', '50'] as $minute) {
            $normalizedHour = $hour % 24;
            $value = sprintf('%02d:%s', $normalizedHour, $minute);
            $label = $hour >= 24 ? sprintf('翌%02d:%s', $normalizedHour, $minute) : sprintf('%02d:%s', $hour, $minute);
            $isSelected = $value === $selected ? ' selected' : '';
            $html .= sprintf('<option value="%s"%s>%s</option>', ligacu_h($value), $isSelected, ligacu_h($label));
        }
    }
    return $html;
}

function admin_status_label(string $status): string
{
    if ($status === 'booked') {
        return '予約済み';
    }

    if ($status === 'pending') {
        return '仮押さえ中';
    }

    return '空き枠';
}

$message = (string) ($_GET['message'] ?? '');
$error = (string) ($_GET['error'] ?? '');
$monthParam = (string) ($_GET['month'] ?? '');
$calendarMonth = preg_match('/^\d{4}-\d{2}$/', $monthParam)
    ? DateTimeImmutable::createFromFormat('!Y-m', $monthParam, new DateTimeZone('Asia/Tokyo'))
    : new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('Asia/Tokyo'));

if (!$calendarMonth instanceof DateTimeImmutable) {
    $calendarMonth = new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('Asia/Tokyo'));
}

$monthStart = $calendarMonth->modify('first day of this month')->setTime(0, 0);
$monthEnd = $monthStart->modify('first day of next month');
$calendarStart = $monthStart->modify('-' . (int) $monthStart->format('w') . ' days');
$calendarEnd = $monthEnd->modify('+' . (6 - (int) $monthEnd->modify('-1 day')->format('w')) . ' days');
$previousMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');
$slotsByDate = [];

try {
    ligacu_cleanup_expired_pending_slots();

    $stmt = ligacu_pdo()->prepare(
        "SELECT
            s.id,
            s.start_time,
            s.end_time,
            s.price,
            s.status,
            b.customer_name,
            b.customer_email,
            b.customer_phone,
            b.payment_status,
            b.booking_status
         FROM availability_slots s
         LEFT JOIN bookings b
           ON b.slot_id = s.id
          AND b.booking_status != 'cancelled'
         WHERE s.start_time >= ?
           AND s.start_time < ?
         ORDER BY s.start_time ASC"
    );
    $stmt->execute([$monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s')]);
    $slots = $stmt->fetchAll();

    foreach ($slots as $slot) {
        $slotDate = (new DateTimeImmutable($slot['start_time'], new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
        $slotsByDate[$slotDate][] = $slot;
    }
} catch (Throwable $e) {
    $slots = [];
    $slotsByDate = [];
    $error = '管理画面の読み込みに失敗しました。DB設定またはテーブル作成状況を確認してください。';
}
?>
<!doctype html>
<html lang="ja">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ligacu Admin</title>
    <style>
      body { margin: 0; background: #f5f4f2; color: #171717; font-family: -apple-system, BlinkMacSystemFont, "Helvetica Neue", sans-serif; }
      main { max-width: 1120px; margin: 0 auto; padding: 40px 20px 80px; }
      header { margin-bottom: 32px; }
      h1 { font-size: 32px; font-weight: 300; margin: 0 0 8px; }
      h2 { font-weight: 400; font-size: 20px; margin: 0 0 20px; }
      p { color: #68625d; font-size: 14px; line-height: 1.8; }
      section { background: white; border-radius: 16px; padding: 24px; margin-bottom: 24px; }
      label { display: block; font-size: 12px; color: #68625d; margin-bottom: 8px; }
      input, select { width: 100%; box-sizing: border-box; border: 1px solid #ddd8d2; border-radius: 10px; padding: 12px; font-size: 14px; background: #fff; }
      .grid { display: grid; grid-template-columns: repeat(4, 1fr) auto; gap: 14px; align-items: end; }
      button { border: 0; border-radius: 999px; background: #111; color: white; padding: 13px 22px; cursor: pointer; white-space: nowrap; }
      button.danger { background: transparent; color: #8f1d1d; border: 1px solid #e3c7c7; padding: 9px 16px; }
      .muted { color: #777; font-size: 12px; line-height: 1.7; }
      .notice { background: #eef5ef; color: #275436; padding: 12px 16px; border-radius: 12px; }
      .error { background: #f8eeee; color: #8f1d1d; padding: 12px 16px; border-radius: 12px; }
      .status { display: inline-flex; border-radius: 999px; padding: 6px 10px; font-size: 12px; background: #eeeae6; }
      .status-booked { background: #171717; color: white; }
      .status-pending { background: #e8dfd2; }
      .calendar-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
      .calendar-title { font-size: 24px; font-weight: 300; margin: 0; }
      .calendar-nav { display: flex; gap: 10px; }
      .calendar-nav a { color: #171717; text-decoration: none; border: 1px solid #ddd8d2; border-radius: 999px; padding: 9px 14px; font-size: 12px; }
      .calendar-weekdays { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 8px; margin-bottom: 8px; }
      .weekday { color: #777; font-size: 12px; text-align: center; }
      .calendar-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 8px; }
      .day { min-height: 148px; background: #faf9f7; border: 1px solid #eeeae6; border-radius: 14px; padding: 10px; }
      .day.outside { opacity: 0.38; }
      .day-number { display: flex; align-items: center; justify-content: space-between; gap: 8px; color: #68625d; font-size: 12px; margin-bottom: 8px; }
      .slot-card { background: #fff; border: 1px solid #eeeae6; border-radius: 12px; padding: 10px; margin-top: 8px; }
      .slot-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
      .slot-time { font-size: 13px; font-weight: 500; }
      .slot-price { color: #777; font-size: 11px; margin-top: 4px; }
      .slot-customer { border-top: 1px solid #eeeae6; margin-top: 8px; padding-top: 8px; }
      .slot-delete { margin-top: 8px; }
      .slot-delete button { width: 100%; padding: 8px 10px; font-size: 11px; }
      @media (max-width: 820px) {
        .grid { grid-template-columns: 1fr; }
        section { padding: 18px; }
        .calendar-head { align-items: flex-start; flex-direction: column; }
        .calendar-weekdays { display: none; }
        .calendar-grid { grid-template-columns: 1fr; }
        .day { min-height: auto; }
        .day.outside { display: none; }
      }
    </style>
  </head>
  <body>
    <main>
      <header>
        <h1>ligacu Admin</h1>
        <p>空き枠の追加、予約状況、予約者情報を確認できます。</p>
      </header>

      <?php if ($message !== ''): ?>
        <p class="notice"><?= ligacu_h($message) ?></p>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <p class="error"><?= ligacu_h($error) ?></p>
      <?php endif; ?>

      <section>
        <h2>空き枠を追加</h2>
        <form method="post" action="/admin/create-slot.php" class="grid">
          <input type="hidden" name="csrf_token" value="<?= ligacu_h($csrfToken) ?>">
          <div>
            <label>日付</label>
            <input type="date" name="date" required>
          </div>
          <div>
            <label>開始時間</label>
            <select name="start_time" required>
              <?= admin_time_options('20:00') ?>
            </select>
          </div>
          <div>
            <label>終了時間</label>
            <select name="end_time" required>
              <?= admin_time_options('21:30') ?>
            </select>
          </div>
          <div>
            <label>価格（円）</label>
            <input type="number" name="price" min="1" step="1" value="18000" required>
          </div>
          <button type="submit">追加する</button>
        </form>
      </section>

      <section>
        <div class="calendar-head">
          <div>
            <h2 class="calendar-title"><?= ligacu_h($monthStart->format('Y年n月')) ?></h2>
            <p class="muted">月間カレンダーで空き枠と予約状況を確認できます。</p>
          </div>
          <div class="calendar-nav">
            <a href="/admin/?month=<?= ligacu_h($previousMonth) ?>">前月</a>
            <a href="/admin/?month=<?= ligacu_h((new DateTimeImmutable('first day of this month', new DateTimeZone('Asia/Tokyo')))->format('Y-m')) ?>">今月</a>
            <a href="/admin/?month=<?= ligacu_h($nextMonth) ?>">翌月</a>
          </div>
        </div>

        <div class="calendar-weekdays">
          <?php foreach (['日', '月', '火', '水', '木', '金', '土'] as $weekday): ?>
            <div class="weekday"><?= ligacu_h($weekday) ?></div>
          <?php endforeach; ?>
        </div>

        <div class="calendar-grid">
          <?php for ($date = $calendarStart; $date <= $calendarEnd; $date = $date->modify('+1 day')): ?>
            <?php
              $dateKey = $date->format('Y-m-d');
              $daySlots = $slotsByDate[$dateKey] ?? [];
              $outside = $date->format('Y-m') !== $monthStart->format('Y-m');
            ?>
            <div class="day <?= $outside ? 'outside' : '' ?>">
              <div class="day-number">
                <span><?= ligacu_h($date->format('j')) ?></span>
                <span><?= ligacu_h(['日', '月', '火', '水', '木', '金', '土'][(int) $date->format('w')]) ?></span>
              </div>

              <?php foreach ($daySlots as $slot): ?>
                <?php
                  $start = new DateTimeImmutable($slot['start_time'], new DateTimeZone('Asia/Tokyo'));
                  $end = new DateTimeImmutable($slot['end_time'], new DateTimeZone('Asia/Tokyo'));
                  $status = (string) $slot['status'];
                ?>
                <div class="slot-card">
                  <div class="slot-top">
                    <span class="slot-time"><?= ligacu_h($start->format('H:i')) ?>-<?= ligacu_h($end->format('H:i')) ?></span>
                    <span class="status status-<?= ligacu_h($status) ?>"><?= ligacu_h(admin_status_label($status)) ?></span>
                  </div>
                  <div class="slot-price">¥<?= ligacu_h(number_format((int) $slot['price'])) ?></div>

                  <?php if ($slot['customer_name']): ?>
                    <div class="slot-customer">
                      <div><?= ligacu_h((string) $slot['customer_name']) ?></div>
                      <div class="muted"><?= ligacu_h((string) $slot['customer_email']) ?></div>
                      <div class="muted"><?= ligacu_h((string) $slot['customer_phone']) ?></div>
                      <div class="muted"><?= ligacu_h((string) $slot['payment_status']) ?> / <?= ligacu_h((string) $slot['booking_status']) ?></div>
                    </div>
                  <?php endif; ?>

                  <form
                    class="slot-delete"
                    method="post"
                    action="/admin/delete-slot.php"
                    onsubmit="<?= $status === 'booked' ? "return confirm('予約済み枠です。予約者情報も削除されます。本当に削除しますか？')" : "return confirm('この空き枠を削除しますか？')" ?>"
                  >
                    <input type="hidden" name="csrf_token" value="<?= ligacu_h($csrfToken) ?>">
                    <input type="hidden" name="slot_id" value="<?= ligacu_h((string) $slot['id']) ?>">
                    <?php if ($status === 'booked'): ?>
                      <input type="hidden" name="allow_booked" value="true">
                    <?php endif; ?>
                    <button class="danger" type="submit">削除</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endfor; ?>
        </div>
      </section>
    </main>
  </body>
</html>
