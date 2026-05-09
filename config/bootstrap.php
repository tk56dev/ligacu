<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

const LIGACU_PENDING_TTL_MINUTES = 30;

function ligacu_send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
}

function ligacu_start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function ligacu_csrf_token(): string
{
    ligacu_start_secure_session();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function ligacu_verify_csrf_token(): void
{
    ligacu_start_secure_session();

    $token = (string) ($_POST['csrf_token'] ?? '');
    $expected = (string) ($_SESSION['csrf_token'] ?? '');

    if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
        throw new RuntimeException('セッションの確認に失敗しました。管理画面を再読み込みしてからもう一度お試しください。');
    }
}

function ligacu_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo 'Method not allowed.';
        exit;
    }
}

function ligacu_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        http_response_code(500);
        echo 'config/config.php is missing. Copy config.example.php and configure it.';
        exit;
    }

    $config = require $path;
    return $config;
}

function ligacu_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = ligacu_config()['db'];
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['name'],
        $db['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function ligacu_ensure_schema(): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $pdo = ligacu_pdo();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS availability_slots (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          start_time DATETIME NOT NULL,
          end_time DATETIME NOT NULL,
          price INT UNSIGNED NOT NULL,
          status ENUM('available', 'pending', 'booked') NOT NULL DEFAULT 'available',
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          INDEX idx_availability_slots_status_start (status, start_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS bookings (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          slot_id BIGINT UNSIGNED NOT NULL,
          customer_name VARCHAR(120) NOT NULL,
          customer_email VARCHAR(255) NOT NULL,
          customer_phone VARCHAR(40) NOT NULL,
          stripe_session_id VARCHAR(255) NULL UNIQUE,
          payment_status ENUM('pending', 'paid', 'failed') NOT NULL DEFAULT 'pending',
          booking_status ENUM('pending', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending',
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          INDEX idx_bookings_slot_id (slot_id),
          CONSTRAINT fk_bookings_slot_id
            FOREIGN KEY (slot_id) REFERENCES availability_slots(id)
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensured = true;
}

function ligacu_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ligacu_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function ligacu_base_url(): string
{
    return rtrim((string) ligacu_config()['app']['base_url'], '/');
}

function ligacu_cleanup_expired_pending_slots(): void
{
    ligacu_ensure_schema();

    $pdo = ligacu_pdo();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "SELECT b.id, b.slot_id
             FROM bookings b
             JOIN availability_slots s ON s.id = b.slot_id
             WHERE b.payment_status = 'pending'
               AND b.booking_status = 'pending'
               AND s.status = 'pending'
               AND b.created_at <= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)"
        );
        $stmt->bindValue(':minutes', LIGACU_PENDING_TTL_MINUTES, PDO::PARAM_INT);
        $stmt->execute();
        $bookings = $stmt->fetchAll();

        foreach ($bookings as $booking) {
            $updateBooking = $pdo->prepare(
                "UPDATE bookings
                 SET payment_status = 'failed',
                     booking_status = 'cancelled',
                     updated_at = NOW()
                 WHERE id = ?
                   AND payment_status = 'pending'
                   AND booking_status = 'pending'"
            );
            $updateBooking->execute([$booking['id']]);

            $updateSlot = $pdo->prepare(
                "UPDATE availability_slots
                 SET status = 'available',
                     updated_at = NOW()
                 WHERE id = ?
                   AND status = 'pending'"
            );
            $updateSlot->execute([$booking['slot_id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ligacu_iso_datetime(string $mysqlDateTime): string
{
    $date = new DateTimeImmutable($mysqlDateTime, new DateTimeZone('Asia/Tokyo'));
    return $date->format('Y-m-d\TH:i:sP');
}

function ligacu_create_pending_booking(array $input): array
{
    $pdo = ligacu_pdo();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "SELECT id, start_time, end_time, price, status
             FROM availability_slots
             WHERE id = ?
             FOR UPDATE"
        );
        $stmt->execute([(int) $input['slot_id']]);
        $slot = $stmt->fetch();

        if (!$slot) {
            throw new RuntimeException('選択された空き枠が見つかりません。');
        }

        if ($slot['status'] !== 'available') {
            throw new RuntimeException('この枠は現在選択できません。別の時間をお選びください。');
        }

        $updateSlot = $pdo->prepare(
            "UPDATE availability_slots
             SET status = 'pending',
                 updated_at = NOW()
             WHERE id = ?
               AND status = 'available'"
        );
        $updateSlot->execute([$slot['id']]);

        if ($updateSlot->rowCount() !== 1) {
            throw new RuntimeException('この枠は現在選択できません。別の時間をお選びください。');
        }

        $insert = $pdo->prepare(
            "INSERT INTO bookings (
                slot_id,
                customer_name,
                customer_email,
                customer_phone,
                payment_status,
                booking_status,
                created_at,
                updated_at
             )
             VALUES (?, ?, ?, ?, 'pending', 'pending', NOW(), NOW())"
        );
        $insert->execute([
            $slot['id'],
            trim((string) $input['customer_name']),
            trim((string) $input['customer_email']),
            trim((string) $input['customer_phone']),
        ]);

        $bookingId = (int) $pdo->lastInsertId();
        $pdo->commit();

        return [
            'booking_id' => $bookingId,
            'slot' => $slot,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ligacu_attach_stripe_session(int $bookingId, string $sessionId): void
{
    $stmt = ligacu_pdo()->prepare(
        "UPDATE bookings
         SET stripe_session_id = ?,
             updated_at = NOW()
         WHERE id = ?"
    );
    $stmt->execute([$sessionId, $bookingId]);
}

function ligacu_release_pending_booking(int $bookingId, int $slotId): void
{
    $pdo = ligacu_pdo();
    $pdo->beginTransaction();

    try {
        $booking = $pdo->prepare(
            "UPDATE bookings
             SET payment_status = 'failed',
                 booking_status = 'cancelled',
                 updated_at = NOW()
             WHERE id = ?
               AND payment_status = 'pending'
               AND booking_status = 'pending'"
        );
        $booking->execute([$bookingId]);

        $slot = $pdo->prepare(
            "UPDATE availability_slots
             SET status = 'available',
                 updated_at = NOW()
             WHERE id = ?
               AND status = 'pending'"
        );
        $slot->execute([$slotId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ligacu_confirm_paid_booking(int $bookingId, int $slotId, string $sessionId): void
{
    $pdo = ligacu_pdo();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "SELECT id, slot_id, payment_status, booking_status
             FROM bookings
             WHERE id = ?
             FOR UPDATE"
        );
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch();

        if (!$booking || (int) $booking['slot_id'] !== $slotId) {
            throw new RuntimeException('Webhook metadata does not match booking data.');
        }

        if ($booking['payment_status'] === 'paid' && $booking['booking_status'] === 'confirmed') {
            $pdo->commit();
            return;
        }

        $updateBooking = $pdo->prepare(
            "UPDATE bookings
             SET stripe_session_id = COALESCE(stripe_session_id, ?),
                 payment_status = 'paid',
                 booking_status = 'confirmed',
                 updated_at = NOW()
             WHERE id = ?"
        );
        $updateBooking->execute([$sessionId, $bookingId]);

        $updateSlot = $pdo->prepare(
            "UPDATE availability_slots
             SET status = 'booked',
                 updated_at = NOW()
             WHERE id = ?"
        );
        $updateSlot->execute([$slotId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ligacu_stripe_request(string $path, array $params): array
{
    $secretKey = ligacu_config()['stripe']['secret_key'];
    if (!$secretKey) {
        throw new RuntimeException('Stripe secret key is not configured.');
    }

    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_USERPWD => $secretKey . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error) {
        throw new RuntimeException('Stripeへ接続できませんでした。');
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Stripeの応答を読み取れませんでした。');
    }

    if ($status >= 400) {
        $message = $data['error']['message'] ?? 'Stripe Checkout Sessionを作成できませんでした。';
        throw new RuntimeException($message);
    }

    return $data;
}

function ligacu_create_checkout_session(int $bookingId, array $slot, string $customerEmail): array
{
    $stripeConfig = ligacu_config()['stripe'];
    $params = [
        'mode' => 'payment',
        'customer_email' => $customerEmail,
        'success_url' => ligacu_base_url() . '/success.html?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => ligacu_base_url() . '/cancel.html',
        'expires_at' => time() + LIGACU_PENDING_TTL_MINUTES * 60,
        'line_items[0][quantity]' => 1,
        'line_items[0][price_data][currency]' => 'jpy',
        'line_items[0][price_data][unit_amount]' => (int) $slot['price'],
        'metadata[booking_id]' => (string) $bookingId,
        'metadata[slot_id]' => (string) $slot['id'],
    ];

    if (!empty($stripeConfig['product_id'])) {
        $params['line_items[0][price_data][product]'] = $stripeConfig['product_id'];
    } else {
        $params['line_items[0][price_data][product_data][name]'] = 'ligacu Recovery Session 90min';
    }

    return ligacu_stripe_request('checkout/sessions', $params);
}

function ligacu_verify_stripe_signature(string $payload, string $header, string $secret): bool
{
    if (!$secret || !$header) {
        return false;
    }

    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($key === 't') {
            $timestamp = $value;
        }
        if ($key === 'v1') {
            $signatures[] = $value;
        }
    }

    if (!$timestamp || !$signatures) {
        return false;
    }

    if (abs(time() - (int) $timestamp) > 300) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }

    return false;
}

function ligacu_require_admin(): void
{
    $app = ligacu_config()['app'];
    $validUser = (string) $app['admin_user'];
    $validPassword = (string) $app['admin_password'];
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $password = $_SERVER['PHP_AUTH_PW'] ?? '';

    if (($user === '' || $password === '') && ligacu_authorization_header() !== '') {
        $authorization = ligacu_authorization_header();
        if (stripos($authorization, 'Basic ') === 0) {
            $decoded = base64_decode(substr($authorization, 6), true);
            if (is_string($decoded) && strpos($decoded, ':') !== false) {
                [$user, $password] = explode(':', $decoded, 2);
            }
        }
    }

    if (!hash_equals($validUser, $user) || !hash_equals($validPassword, $password)) {
        header('WWW-Authenticate: Basic realm="ligacu admin"');
        http_response_code(401);
        echo 'Authentication required.';
        exit;
    }
}

function ligacu_authorization_header(): string
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['Authorization'] ?? '',
        $_SERVER['REDIRECT_Authorization'] ?? '',
    ];

    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'authorization') {
                $candidates[] = (string) $value;
            }
        }
    }

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function ligacu_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ligacu_redirect_admin(string $key, string $message): void
{
    header('Location: /admin/?' . http_build_query([$key => $message]));
    exit;
}

ligacu_send_security_headers();
