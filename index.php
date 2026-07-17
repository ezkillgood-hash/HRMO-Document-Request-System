<?php
declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| INFINITYFREE DATABASE SETTINGS
|--------------------------------------------------------------------------
| IMPORTANT: Replace DB_NAME with the exact database name shown under
| "Available Database Names" in your InfinityFree control panel.
*/
define('DB_HOST', 'sql300.infinityfree.com');
define('DB_NAME', 'if0_42423147_hrmo');
define('DB_USER', 'if0_42423147');
define('DB_PASS', 'vhWUdpN1wEAeAvy');


/*
|--------------------------------------------------------------------------
| TELEGRAM ADMIN NOTIFICATION
|--------------------------------------------------------------------------
| 1. Create a bot using @BotFather.
| 2. Paste the bot token below.
| 3. Send /start to your bot.
| 4. Paste the administrator's numeric chat ID below.
*/
define('TELEGRAM_ENABLED', false);
define('TELEGRAM_BOT_TOKEN', '8721178482:AAGsOgpcF57VxmPFGWsJSzyZ4zd8trk6a4A');
define('TELEGRAM_ADMIN_CHAT_ID', '6043099433');


/*
|--------------------------------------------------------------------------
| GITHUB ACTIONS RELAY
|--------------------------------------------------------------------------
| This value MUST exactly match the HRMO_SECRET_KEY repository secret.
*/
define('HRMO_RELAY_SECRET', 'JRMSU_HRMO_2026_X7A93LQK');

date_default_timezone_set('Asia/Manila');

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        exit(
            '<div style="font-family:Arial;padding:30px;max-width:760px;margin:auto">' .
            '<h2>Database connection failed</h2>' .
            '<p>Open this PHP file and verify the <strong>DB_NAME</strong>, username, password, and hostname.</p>' .
            '<pre style="white-space:pre-wrap;background:#f5f5f5;padding:15px;border-radius:8px">' .
            htmlspecialchars($e->getMessage()) .
            '</pre></div>'
        );
    }
}

$pdo = db();

/*
|--------------------------------------------------------------------------
| AUTOMATIC TABLE INSTALLATION
|--------------------------------------------------------------------------
| The code below automatically creates all required tables.
*/
$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    position_designation VARCHAR(150) NOT NULL,
    office_department VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(30) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('requester','admin') NOT NULL DEFAULT 'requester',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_no VARCHAR(40) NULL UNIQUE,
    user_id INT UNSIGNED NOT NULL,
    purpose VARCHAR(255) NOT NULL,
    remarks TEXT NULL,
    status ENUM('Pending','Processing','Ready for Release','Released','Cancelled') NOT NULL DEFAULT 'Pending',
    processed_by INT UNSIGNED NULL,
    date_requested DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    release_date DATE NULL,
    release_time TIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_requests_processor FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_requests_status (status),
    INDEX idx_requests_date (date_requested)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS request_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    document_name VARCHAR(255) NOT NULL,
    copy_type ENUM('Original','Photocopy','Others') NOT NULL,
    other_copy_type VARCHAR(150) NULL,
    copies INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_docs_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    INDEX idx_docs_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    activity VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    message VARCHAR(255) NOT NULL,
    request_id INT UNSIGNED NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_request FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    INDEX idx_notifications_user_read (user_id, is_read),
    INDEX idx_notifications_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS telegram_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NULL,
    event_type VARCHAR(60) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    http_code INT NULL,
    response_text TEXT NULL,
    error_text TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_telegram_logs_created (created_at),
    INDEX idx_telegram_logs_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS telegram_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NULL,
    event_type VARCHAR(60) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    INDEX idx_queue_status_created (status, created_at),
    INDEX idx_queue_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");


function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url = 'index.php'): never {
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$token)) {
        http_response_code(419);
        exit('Invalid or expired security token. Please go back and try again.');
    }
}

function user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): void {
    if (!user()) {
        redirect('index.php?page=login');
    }
}

function require_admin(): void {
    require_login();
    if ((user()['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Access denied.');
    }
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pull_flash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function log_activity(PDO $pdo, ?int $userId, string $activity): void {
    $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, activity) VALUES (?, ?)');
    $stmt->execute([$userId, $activity]);
}


function create_notification(
    PDO $pdo,
    int $userId,
    string $title,
    string $message,
    ?int $requestId = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, title, message, request_id)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $title, $message, $requestId]);
}

function notify_all_admins(
    PDO $pdo,
    string $title,
    string $message,
    ?int $requestId = null
): void {
    $adminIds = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($adminIds as $adminId) {
        create_notification($pdo, (int)$adminId, $title, $message, $requestId);
    }
}


function telegram_log(
    PDO $pdo,
    ?int $requestId,
    string $eventType,
    bool $success,
    ?int $httpCode,
    ?string $responseText,
    ?string $errorText
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO telegram_logs
             (request_id, event_type, success, http_code, response_text, error_text)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $requestId,
            $eventType,
            $success ? 1 : 0,
            $httpCode,
            $responseText,
            $errorText,
        ]);
    } catch (Throwable $ignored) {
    }
}

function send_telegram_admin_notification(
    PDO $pdo,
    string $message,
    ?int $requestId = null,
    string $eventType = 'notification'
): array {
    if (!TELEGRAM_ENABLED) {
        $result = ['ok' => false, 'http_code' => null, 'response' => null, 'error' => 'Telegram is disabled.'];
        telegram_log($pdo, $requestId, $eventType, false, null, null, $result['error']);
        return $result;
    }

    $token = trim((string)TELEGRAM_BOT_TOKEN);
    $chatId = trim((string)TELEGRAM_ADMIN_CHAT_ID);

    if ($token === '' || $chatId === '' || str_contains($token, 'PASTE_') || str_contains($chatId, 'PASTE_')) {
        $result = ['ok' => false, 'http_code' => null, 'response' => null, 'error' => 'Telegram token or chat ID is missing.'];
        telegram_log($pdo, $requestId, $eventType, false, null, null, $result['error']);
        return $result;
    }

    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $payload = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true',
    ];

    $response = false;
    $httpCode = null;
    $errorText = null;

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'JRMSU-HRMO-System/1.0',
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            $errorText = 'cURL error ' . curl_errno($ch) . ': ' . curl_error($ch);
        }
        curl_close($ch);
    } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($payload),
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if (isset($http_response_header[0]) && preg_match('/\\s(\\d{3})\\s/', $http_response_header[0], $m)) {
            $httpCode = (int)$m[1];
        }
        if ($response === false) {
            $lastError = error_get_last();
            $errorText = $lastError['message'] ?? 'file_get_contents failed.';
        }
    } else {
        $errorText = 'Neither cURL nor allow_url_fopen is available.';
    }

    $decoded = is_string($response) ? json_decode($response, true) : null;
    $ok = is_array($decoded) && ($decoded['ok'] ?? false) === true;

    if (!$ok && !$errorText && is_array($decoded)) {
        $errorText = (string)($decoded['description'] ?? 'Telegram returned an error.');
    }

    telegram_log(
        $pdo,
        $requestId,
        $eventType,
        $ok,
        $httpCode,
        is_string($response) ? $response : null,
        $errorText
    );

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'response' => is_string($response) ? $response : null,
        'error' => $errorText,
    ];
}

function telegram_escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


function enqueue_telegram_message(
    PDO $pdo,
    string $message,
    ?int $requestId = null,
    string $eventType = 'notification'
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO telegram_queue (request_id, event_type, message)
         VALUES (?, ?, ?)'
    );
    $stmt->execute([$requestId, $eventType, $message]);
    return (int)$pdo->lastInsertId();
}

function relay_key_is_valid(string $provided): bool {
    return $provided !== '' && hash_equals((string)HRMO_RELAY_SECRET, $provided);
}

function generate_request_no(int $id): string {
    return 'HRMO-' . date('Ymd') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

function status_class(string $status): string {
    return match ($status) {
        'Pending' => 'warning',
        'Processing' => 'info',
        'Ready for Release' => 'success',
        'Released' => 'primary',
        'Cancelled' => 'danger',
        default => 'secondary',
    };
}

// Create default administrator only when there are no users.
try {
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO users
             (full_name, position_designation, office_department, email, phone, password_hash, role)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'HRMO Administrator',
            'Administrator',
            'Human Resource Management Office',
            'admin@jrmsu.local',
            '09000000000',
            password_hash('Admin123!', PASSWORD_DEFAULT),
            'admin',
        ]);
    }
} catch (Throwable $ignored) {
    // schema.sql may not yet be imported.
}


/*
|--------------------------------------------------------------------------
| REAL-TIME NOTIFICATION API
|--------------------------------------------------------------------------
| Uses lightweight AJAX polling, which works on shared hosting such as
| InfinityFree without requiring WebSocket access.
*/
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');

    $api = (string)$_GET['api'];

    // GitHub relay endpoints use the shared secret instead of a login session.
    // They must remain accessible to GitHub Actions even when no user is logged in.
    if ($api === 'github_telegram_queue') {
        $providedKey = (string)($_GET['key'] ?? '');

        if (!relay_key_is_valid($providedKey)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Invalid relay key']);
            exit;
        }

        $pdo->beginTransaction();

        try {
            $items = $pdo->query(
                "SELECT id, request_id, event_type, message, attempts, created_at
                 FROM telegram_queue
                 WHERE status IN ('pending','failed') AND attempts < 10
                 ORDER BY id ASC
                 LIMIT 20
                 FOR UPDATE"
            )->fetchAll();

            if ($items) {
                $ids = array_map(fn($row) => (int)$row['id'], $items);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                $stmt = $pdo->prepare(
                    "UPDATE telegram_queue
                     SET status = 'processing', attempts = attempts + 1
                     WHERE id IN ($placeholders)"
                );
                $stmt->execute($ids);
            }

            $pdo->commit();

            echo json_encode([
                'ok' => true,
                'count' => count($items),
                'items' => $items,
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'message' => 'Unable to read Telegram queue',
            ]);
        }
        exit;
    }

    if ($api === 'github_telegram_ack') {
        $providedKey = (string)($_GET['key'] ?? '');
        $queueId = (int)($_GET['id'] ?? 0);
        $result = (string)($_GET['result'] ?? 'sent');
        $error = trim((string)($_GET['error'] ?? ''));

        if (!relay_key_is_valid($providedKey)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Invalid relay key']);
            exit;
        }

        if ($queueId < 1) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Invalid queue ID']);
            exit;
        }

        if ($result === 'sent') {
            $stmt = $pdo->prepare(
                "UPDATE telegram_queue
                 SET status = 'sent', sent_at = NOW(), last_error = NULL
                 WHERE id = ?"
            );
            $stmt->execute([$queueId]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE telegram_queue
                 SET status = 'failed', last_error = ?
                 WHERE id = ?"
            );
            $stmt->execute([$error !== '' ? $error : 'Unknown relay error', $queueId]);
        }

        echo json_encode(['ok' => true, 'id' => $queueId, 'result' => $result]);
        exit;
    }

    // All remaining API actions are only for authenticated users.
    if (!user()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Not logged in']);
        exit;
    }

    if ($api === 'notifications') {
        $lastId = max(0, (int)($_GET['last_id'] ?? 0));

        $stmt = $pdo->prepare(
            'SELECT id, title, message, request_id, is_read, created_at
             FROM notifications
             WHERE user_id = ? AND id > ?
             ORDER BY id ASC
             LIMIT 20'
        );
        $stmt->execute([(int)user()['id'], $lastId]);
        $items = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM notifications
             WHERE user_id = ? AND is_read = 0'
        );
        $stmt->execute([(int)user()['id']]);
        $unread = (int)$stmt->fetchColumn();

        echo json_encode([
            'ok' => true,
            'items' => $items,
            'unread' => $unread,
            'server_time' => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    if ($api === 'mark_notifications_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $stmt = $pdo->prepare(
            'UPDATE notifications SET is_read = 1
             WHERE user_id = ? AND is_read = 0'
        );
        $stmt->execute([(int)user()['id']]);

        echo json_encode(['ok' => true, 'unread' => 0]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Unknown API action']);
    exit;
}

$page = $_GET['page'] ?? (user() ? 'dashboard' : 'login');
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'logout') {
    session_unset();
    session_destroy();
    redirect('index.php?page=login');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if ($action === 'register') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $position = trim((string)($_POST['position_designation'] ?? ''));
        $office = trim((string)($_POST['office_department'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if ($fullName === '' || $position === '' || $office === '' || $email === '' || $phone === '') {
            flash('danger', 'Please complete all required fields.');
            redirect('index.php?page=register');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'Please enter a valid email address.');
            redirect('index.php?page=register');
        }
        if (strlen($password) < 8) {
            flash('danger', 'Password must contain at least 8 characters.');
            redirect('index.php?page=register');
        }
        if ($password !== $confirm) {
            flash('danger', 'Passwords do not match.');
            redirect('index.php?page=register');
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users
                 (full_name, position_designation, office_department, email, phone, password_hash)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $fullName,
                $position,
                $office,
                $email,
                $phone,
                password_hash($password, PASSWORD_DEFAULT),
            ]);
            log_activity($pdo, (int)$pdo->lastInsertId(), 'Registered a requester account.');
            flash('success', 'Registration successful. You may now log in.');
            redirect('index.php?page=login');
        } catch (PDOException $e) {
            if ((int)$e->errorInfo[1] === 1062) {
                flash('danger', 'That email address is already registered.');
            } else {
                flash('danger', 'Registration failed. Please try again.');
            }
            redirect('index.php?page=register');
        }
    }

    if ($action === 'login') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $account = $stmt->fetch();

        if (!$account || !password_verify($password, $account['password_hash'])) {
            flash('danger', 'Incorrect email or password.');
            redirect('index.php?page=login');
        }

        session_regenerate_id(true);
        unset($account['password_hash']);
        $_SESSION['user'] = $account;
        log_activity($pdo, (int)$account['id'], 'Logged in.');
        redirect('index.php?page=dashboard');
    }

    if ($action === 'test_telegram') {
        require_admin();

        enqueue_telegram_message(
            $pdo,
            "✅ <b>HRMO GITHUB RELAY TEST</b>\n\n" .
            "This test message was queued by the HRMO system.\n" .
            "Created by: " . telegram_escape((string)user()['full_name']) . "\n" .
            "Date: " . telegram_escape(date('F d, Y h:i A')),
            null,
            'manual_test'
        );

        flash(
            'success',
            'Telegram test message was added to the GitHub relay queue. Run the GitHub workflow now.'
        );

        redirect('index.php?page=telegram_settings');
    }

    if ($action === 'create_request') {
        require_login();

        $purposeChoice = trim((string)($_POST['purpose'] ?? ''));
        $purposeOther = trim((string)($_POST['purpose_other'] ?? ''));
        $purpose = $purposeChoice === 'Others' ? $purposeOther : $purposeChoice;
        $remarks = trim((string)($_POST['remarks'] ?? ''));

        $documentNames = $_POST['document_name'] ?? [];
        $copyTypes = $_POST['copy_type'] ?? [];
        $otherCopyTypes = $_POST['other_copy_type'] ?? [];
        $copiesList = $_POST['copies'] ?? [];

        if ($purpose === '') {
            flash('danger', 'Please select or enter the purpose of the request.');
            redirect('index.php?page=new_request');
        }

        $documents = [];
        foreach ($documentNames as $i => $nameRaw) {
            $name = trim((string)$nameRaw);
            $copyType = (string)($copyTypes[$i] ?? '');
            $otherCopy = trim((string)($otherCopyTypes[$i] ?? ''));
            $copies = max(1, min(100, (int)($copiesList[$i] ?? 1)));

            if ($name === '') {
                continue;
            }
            if (!in_array($copyType, ['Original', 'Photocopy', 'Others'], true)) {
                continue;
            }
            if ($copyType === 'Others' && $otherCopy === '') {
                flash('danger', 'Please specify the copy type for every item marked Others.');
                redirect('index.php?page=new_request');
            }

            $documents[] = [
                'name' => $name,
                'copy_type' => $copyType,
                'other_copy' => $copyType === 'Others' ? $otherCopy : null,
                'copies' => $copies,
            ];
        }

        if (!$documents) {
            flash('danger', 'Please add at least one document request.');
            redirect('index.php?page=new_request');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO requests (user_id, purpose, remarks) VALUES (?, ?, ?)'
            );
            $stmt->execute([
                (int)user()['id'],
                $purpose,
                $remarks !== '' ? $remarks : null,
            ]);

            $requestId = (int)$pdo->lastInsertId();
            $requestNo = generate_request_no($requestId);

            $stmt = $pdo->prepare('UPDATE requests SET request_no = ? WHERE id = ?');
            $stmt->execute([$requestNo, $requestId]);

            $docStmt = $pdo->prepare(
                'INSERT INTO request_documents
                 (request_id, document_name, copy_type, other_copy_type, copies)
                 VALUES (?, ?, ?, ?, ?)'
            );

            foreach ($documents as $doc) {
                $docStmt->execute([
                    $requestId,
                    $doc['name'],
                    $doc['copy_type'],
                    $doc['other_copy'],
                    $doc['copies'],
                ]);
            }

            log_activity($pdo, (int)user()['id'], "Submitted request {$requestNo}.");
            notify_all_admins(
                $pdo,
                'New HRMO Document Request',
                user()['full_name'] . " submitted request {$requestNo}.",
                $requestId
            );

            $telegramLines = [
                "🔔 <b>NEW HRMO DOCUMENT REQUEST</b>",
                "",
                "Request No.: <b>" . telegram_escape($requestNo) . "</b>",
                "Requester: " . telegram_escape((string)user()['full_name']),
                "Position: " . telegram_escape((string)user()['position_designation']),
                "Office: " . telegram_escape((string)user()['office_department']),
                "Purpose: " . telegram_escape($purpose),
                "",
                "<b>Requested Document/s:</b>",
            ];

            foreach ($documents as $index => $doc) {
                $copyLabel = $doc['copy_type'] === 'Others'
                    ? (string)$doc['other_copy']
                    : (string)$doc['copy_type'];

                $telegramLines[] =
                    ($index + 1) . ". " .
                    telegram_escape((string)$doc['name']) .
                    " — " .
                    telegram_escape($copyLabel) .
                    " (" . (int)$doc['copies'] . " cop" .
                    ((int)$doc['copies'] === 1 ? "y" : "ies") . ")";
            }

            $telegramLines[] = "";
            $telegramLines[] = "Date: " . telegram_escape(date('F d, Y h:i A'));

            $pdo->commit();

            enqueue_telegram_message(
                $pdo,
                implode("
", $telegramLines),
                $requestId,
                'new_request'
            );

            flash('success', "Request {$requestNo} was submitted successfully.");
            redirect('index.php?page=view_request&id=' . $requestId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', 'Unable to save the request. Please try again.');
            redirect('index.php?page=new_request');
        }
    }

    if ($action === 'update_status') {
        require_admin();

        $requestId = (int)($_POST['request_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        $allowed = ['Pending', 'Processing', 'Ready for Release', 'Released', 'Cancelled'];

        if ($requestId < 1 || !in_array($status, $allowed, true)) {
            flash('danger', 'Invalid request update.');
            redirect('index.php?page=admin_requests');
        }

        $releaseDate = null;
        $releaseTime = null;
        if ($status === 'Released') {
            $releaseDate = date('Y-m-d');
            $releaseTime = date('H:i:s');
        }

        $stmt = $pdo->prepare(
            'UPDATE requests
             SET status = ?, processed_by = ?, release_date = ?, release_time = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $status,
            (int)user()['id'],
            $releaseDate,
            $releaseTime,
            $requestId,
        ]);

        $stmt = $pdo->prepare('SELECT request_no FROM requests WHERE id = ?');
        $stmt->execute([$requestId]);
        $requestNo = (string)$stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT user_id FROM requests WHERE id = ?');
        $stmt->execute([$requestId]);
        $requesterId = (int)$stmt->fetchColumn();

        create_notification(
            $pdo,
            $requesterId,
            'Request Status Updated',
            "Your request {$requestNo} is now {$status}.",
            $requestId
        );

        enqueue_telegram_message(
            $pdo,
            "📌 <b>HRMO REQUEST STATUS UPDATED</b>

" .
            "Request No.: <b>" . telegram_escape($requestNo) . "</b>
" .
            "New Status: <b>" . telegram_escape($status) . "</b>
" .
            "Updated By: " . telegram_escape((string)user()['full_name']) . "
" .
            "Date: " . telegram_escape(date('F d, Y h:i A')),
            $requestId,
            'status_update'
        );

        log_activity($pdo, (int)user()['id'], "Updated {$requestNo} to {$status}.");
        flash('success', "Request status changed to {$status}.");
        redirect('index.php?page=view_request&id=' . $requestId);
    }
}

$flash = pull_flash();

function render_header(string $title = 'HRMO Document Request System'): void {
    $currentUser = user();
    $page = $_GET['page'] ?? '';
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <link rel="icon" type="image/png" href="jrmsu-logo.png">
    <link rel="shortcut icon" type="image/png" href="jrmsu-logo.png">
    <link rel="apple-touch-icon" href="jrmsu-logo.png">
    <meta name="theme-color" content="#072a65">
    <style>
        :root{
            --blue:#0b3d91;
            --blue2:#072a65;
            --gold:#f4c542;
            --bg:#f2f5fa;
            --text:#1d2736;
            --muted:#667085;
            --white:#fff;
            --border:#d9e0ea;
            --danger:#b42318;
            --success:#067647;
            --warning:#b54708;
            --info:#175cd3;
        }
        *{box-sizing:border-box}
        body{margin:0;font-family:Arial,Helvetica,sans-serif;background:var(--bg);color:var(--text)}
        a{text-decoration:none;color:inherit}
        .topbar{background:linear-gradient(135deg,var(--blue2),var(--blue));color:white;position:sticky;top:0;z-index:20;box-shadow:0 2px 12px rgba(0,0,0,.15)}
        .topbar-inner{max-width:1180px;margin:auto;min-height:70px;padding:10px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px}
        .brand{display:flex;align-items:center;gap:12px}
        .logo{width:58px;height:58px;border-radius:50%;object-fit:cover;background:white;border:3px solid rgba(255,255,255,.9);box-shadow:0 4px 12px rgba(0,0,0,.18);flex:0 0 58px}
        .brand h1{font-size:16px;margin:0;line-height:1.25}
        .brand small{display:block;opacity:.85;font-size:12px}
        .nav{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .nav a{padding:10px 12px;border-radius:9px;font-size:14px}
        .nav a:hover,.nav a.active{background:rgba(255,255,255,.15)}
        .container{max-width:1180px;margin:24px auto;padding:0 18px}
        .card{background:white;border:1px solid var(--border);border-radius:16px;padding:22px;box-shadow:0 8px 30px rgba(16,24,40,.06)}
        .auth{max-width:520px;margin:48px auto}
        .auth-logo{display:block;width:110px;height:110px;object-fit:cover;border-radius:50%;margin:0 auto 16px;box-shadow:0 10px 30px rgba(11,61,145,.18)}
        .auth-title{text-align:center;margin-bottom:6px}
        .auth-subtitle{text-align:center}
        h2,h3{margin-top:0}
        .grid{display:grid;gap:16px}
        .grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}
        .grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
        .grid-5{grid-template-columns:repeat(5,minmax(0,1fr))}
        label{display:block;font-weight:700;font-size:14px;margin-bottom:7px}
        input,select,textarea{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:10px;background:white;font:inherit;outline:none}
        input:focus,select:focus,textarea:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(11,61,145,.12)}
        textarea{min-height:90px;resize:vertical}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:0;border-radius:10px;padding:11px 16px;font-weight:700;cursor:pointer}
        .btn-primary{background:var(--blue);color:white}
        .btn-secondary{background:#e9eef6;color:var(--blue2)}
        .btn-danger{background:#fee4e2;color:var(--danger)}
        .btn-success{background:#dcfae6;color:var(--success)}
        .btn-warning{background:#fff3d6;color:var(--warning)}
        .btn-sm{padding:8px 10px;font-size:13px}
        .actions{display:flex;gap:10px;flex-wrap:wrap}
        .alert{padding:13px 15px;border-radius:10px;margin-bottom:16px;font-weight:700}
        .alert-success{background:#dcfae6;color:#067647}
        .alert-danger{background:#fee4e2;color:#b42318}
        .alert-warning{background:#fff3d6;color:#b54708}
        .stat{border-radius:14px;padding:18px;background:white;border:1px solid var(--border)}
        .stat .number{font-size:30px;font-weight:800;color:var(--blue)}
        .stat .label{color:var(--muted);margin-top:4px}
        .table-wrap{overflow:auto}
        table{width:100%;border-collapse:collapse;min-width:760px}
        th,td{text-align:left;padding:12px;border-bottom:1px solid #e6eaf0;vertical-align:top}
        th{background:#f8fafc;font-size:13px;color:#475467}
        .badge{display:inline-block;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:800}
        .badge-warning{background:#fff3d6;color:#b54708}
        .badge-info{background:#eaf2ff;color:#175cd3}
        .badge-success{background:#dcfae6;color:#067647}
        .badge-primary{background:#e8edff;color:#3538cd}
        .badge-danger{background:#fee4e2;color:#b42318}
        .badge-secondary{background:#eef2f6;color:#475467}
        .muted{color:var(--muted)}
        .doc-row{border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:14px;background:#fbfcfe}
        .doc-row-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
        .doc-number{font-weight:800;color:var(--blue)}
        .other-wrap{display:none}
        .section-title{border-left:5px solid var(--gold);padding-left:10px;margin:24px 0 14px}
        .details{display:grid;grid-template-columns:180px 1fr;gap:10px 18px}
        .details div:nth-child(odd){font-weight:700;color:#475467}
        .footer{max-width:1180px;margin:30px auto;padding:20px 18px;color:#667085;text-align:center;font-size:13px}
        .print-only{display:none}
        .notification-wrap{position:relative}
        .notification-button{position:relative;background:rgba(255,255,255,.12);color:white;border:0;border-radius:10px;padding:10px 12px;cursor:pointer;font-weight:700}
        .notification-badge{position:absolute;top:-6px;right:-6px;min-width:20px;height:20px;padding:0 5px;border-radius:999px;background:#e11d48;color:white;font-size:11px;display:none;align-items:center;justify-content:center;border:2px solid var(--blue2)}
        .notification-panel{display:none;position:absolute;right:0;top:48px;width:min(370px,92vw);max-height:430px;overflow:auto;background:white;color:var(--text);border:1px solid var(--border);border-radius:14px;box-shadow:0 18px 50px rgba(16,24,40,.22);z-index:100}
        .notification-panel.open{display:block}
        .notification-head{position:sticky;top:0;background:white;padding:14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;z-index:2}
        .notification-item{padding:13px 14px;border-bottom:1px solid #edf0f4;cursor:pointer}
        .notification-item:hover{background:#f8fafc}
        .notification-title{font-weight:800;font-size:14px;margin-bottom:4px}
        .notification-message{font-size:13px;color:#475467}
        .notification-time{font-size:11px;color:#98a2b3;margin-top:5px}
        .notification-empty{padding:24px;text-align:center;color:#667085}
        .toast-container{position:fixed;right:18px;bottom:18px;z-index:9999;display:grid;gap:10px;width:min(360px,calc(100vw - 36px))}
        .toast{background:#101828;color:white;border-radius:12px;padding:14px 16px;box-shadow:0 15px 35px rgba(0,0,0,.22);animation:toastIn .25s ease}
        .toast strong{display:block;margin-bottom:4px}
        @keyframes toastIn{from{transform:translateY(15px);opacity:0}to{transform:none;opacity:1}}
        @media(max-width:850px){
            .grid-2,.grid-3,.grid-5{grid-template-columns:1fr}
            .topbar-inner{align-items:flex-start;flex-direction:column}
            .nav{width:100%}
            .details{grid-template-columns:1fr}
            .details div:nth-child(odd){margin-top:8px}
        }
        @media print{
            body{background:white}
            .topbar,.footer,.no-print,.alert{display:none!important}
            .container{max-width:none;margin:0;padding:0}
            .card{box-shadow:none;border:0;padding:0}
            .print-only{display:block}
            table{min-width:0}
        }
    </style>
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <div class="brand">
            <img class="logo" src="jrmsu-logo.png" alt="JRMSU Logo">
            <div>
                <h1>JOSE RIZAL MEMORIAL STATE UNIVERSITY</h1>
                <small>Siocon Campus · Human Resource Management Office</small>
            </div>
        </div>
        <nav class="nav">
            <?php if ($currentUser): ?>
                <a class="<?= $page === 'dashboard' ? 'active' : '' ?>" href="index.php?page=dashboard">Dashboard</a>
                <?php if ($currentUser['role'] === 'requester'): ?>
                    <a class="<?= $page === 'new_request' ? 'active' : '' ?>" href="index.php?page=new_request">New Request</a>
                    <a class="<?= $page === 'my_requests' ? 'active' : '' ?>" href="index.php?page=my_requests">My Requests</a>
                <?php else: ?>
                    <a class="<?= $page === 'admin_requests' ? 'active' : '' ?>" href="index.php?page=admin_requests">Manage Requests</a>
                    <a class="<?= $page === 'telegram_settings' ? 'active' : '' ?>" href="index.php?page=telegram_settings">Telegram Test</a>
                <?php endif; ?>
                <div class="notification-wrap">
                    <button id="notification-button" class="notification-button" type="button" aria-label="Notifications">
                        🔔 Notifications
                        <span id="notification-badge" class="notification-badge">0</span>
                    </button>
                    <div id="notification-panel" class="notification-panel">
                        <div class="notification-head">
                            <strong>Notifications</strong>
                            <button id="mark-read-button" class="btn btn-secondary btn-sm" type="button">Mark all read</button>
                        </div>
                        <div id="notification-list">
                            <div class="notification-empty">No notifications yet.</div>
                        </div>
                    </div>
                </div>
                <a href="index.php?action=logout">Logout</a>
            <?php else: ?>
                <a href="index.php?page=login">Login</a>
                <a href="index.php?page=register">Register</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="container">
<?php
}

function render_footer(): void {
    ?>
</main>
<footer class="footer">
    JRMSU Siocon Campus · HRMO Document Request and Tracking System
</footer>
<?php if (user()): ?>
<div id="toast-container" class="toast-container"></div>
<?php endif; ?>
<script>
function updateDocumentNumbers() {
    document.querySelectorAll('.doc-row').forEach((row, index) => {
        const number = row.querySelector('.doc-number');
        if (number) number.textContent = 'Document Request #' + (index + 1);
        const remove = row.querySelector('.remove-doc');
        if (remove) remove.style.display = document.querySelectorAll('.doc-row').length > 1 ? 'inline-flex' : 'none';
    });
}

function toggleOther(select) {
    const row = select.closest('.doc-row');
    if (!row) return;
    const wrap = row.querySelector('.other-wrap');
    const input = row.querySelector('input[name="other_copy_type[]"]');
    if (select.value === 'Others') {
        wrap.style.display = 'block';
        input.required = true;
    } else {
        wrap.style.display = 'none';
        input.required = false;
        input.value = '';
    }
}

document.addEventListener('click', function (event) {
    if (event.target.closest('#add-document')) {
        const container = document.querySelector('#documents-container');
        const first = container.querySelector('.doc-row');
        const clone = first.cloneNode(true);

        clone.querySelectorAll('input').forEach(input => {
            if (input.name === 'copies[]') input.value = '1';
            else input.value = '';
        });
        clone.querySelectorAll('select').forEach(select => select.selectedIndex = 0);
        clone.querySelector('.other-wrap').style.display = 'none';
        clone.querySelector('input[name="other_copy_type[]"]').required = false;
        container.appendChild(clone);
        updateDocumentNumbers();
    }

    const removeButton = event.target.closest('.remove-doc');
    if (removeButton) {
        const rows = document.querySelectorAll('.doc-row');
        if (rows.length > 1) {
            removeButton.closest('.doc-row').remove();
            updateDocumentNumbers();
        }
    }
});

document.addEventListener('change', function (event) {
    if (event.target.matches('select[name="copy_type[]"]')) {
        toggleOther(event.target);
    }
    if (event.target.matches('#purpose')) {
        const purposeOther = document.querySelector('#purpose-other-wrap');
        const otherInput = document.querySelector('#purpose_other');
        if (event.target.value === 'Others') {
            purposeOther.style.display = 'block';
            otherInput.required = true;
        } else {
            purposeOther.style.display = 'none';
            otherInput.required = false;
            otherInput.value = '';
        }
    }
});


let notificationLastId = Number(localStorage.getItem('hrmoNotificationLastId') || 0);
let notificationInitialized = false;
let notificationAudioEnabled = false;

function enableNotificationAudio() {
    notificationAudioEnabled = true;
    document.removeEventListener('click', enableNotificationAudio);
}
document.addEventListener('click', enableNotificationAudio);

function beepNotification() {
    if (!notificationAudioEnabled) return;
    try {
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioContext.createOscillator();
        const gain = audioContext.createGain();
        oscillator.connect(gain);
        gain.connect(audioContext.destination);
        oscillator.frequency.value = 880;
        gain.gain.setValueAtTime(0.12, audioContext.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + 0.35);
        oscillator.start();
        oscillator.stop(audioContext.currentTime + 0.35);
    } catch (error) {
        console.debug('Notification sound unavailable.');
    }
}

function showToast(title, message, requestId) {
    const container = document.querySelector('#toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.innerHTML =
        '<strong></strong><div></div>';

    toast.querySelector('strong').textContent = title;
    toast.querySelector('div').textContent = message;

    if (requestId) {
        toast.style.cursor = 'pointer';
        toast.addEventListener('click', () => {
            window.location.href = 'index.php?page=view_request&id=' + encodeURIComponent(requestId);
        });
    }

    container.appendChild(toast);
    setTimeout(() => toast.remove(), 7000);
}

function renderNotifications(items) {
    const list = document.querySelector('#notification-list');
    if (!list) return;

    if (!items.length && !list.querySelector('.notification-item')) {
        list.innerHTML = '<div class="notification-empty">No notifications yet.</div>';
        return;
    }

    const empty = list.querySelector('.notification-empty');
    if (empty) empty.remove();

    items.slice().reverse().forEach(item => {
        if (list.querySelector('[data-notification-id="' + item.id + '"]')) return;

        const row = document.createElement('div');
        row.className = 'notification-item';
        row.dataset.notificationId = item.id;

        const title = document.createElement('div');
        title.className = 'notification-title';
        title.textContent = item.title;

        const message = document.createElement('div');
        message.className = 'notification-message';
        message.textContent = item.message;

        const time = document.createElement('div');
        time.className = 'notification-time';
        time.textContent = item.created_at;

        row.append(title, message, time);

        if (item.request_id) {
            row.addEventListener('click', () => {
                window.location.href = 'index.php?page=view_request&id=' + encodeURIComponent(item.request_id);
            });
        }

        list.prepend(row);
    });
}

async function fetchNotifications() {
    const button = document.querySelector('#notification-button');
    if (!button) return;

    try {
        const response = await fetch(
            'index.php?api=notifications&last_id=' + encodeURIComponent(notificationLastId),
            {cache: 'no-store', credentials: 'same-origin'}
        );

        if (!response.ok) return;
        const data = await response.json();
        if (!data.ok) return;

        const badge = document.querySelector('#notification-badge');
        if (badge) {
            badge.textContent = data.unread > 99 ? '99+' : data.unread;
            badge.style.display = data.unread > 0 ? 'flex' : 'none';
        }

        renderNotifications(data.items || []);

        if ((data.items || []).length) {
            const newestId = Math.max(...data.items.map(item => Number(item.id)));

            if (notificationInitialized) {
                data.items.forEach(item => {
                    showToast(item.title, item.message, item.request_id);
                });
                beepNotification();

                if ('Notification' in window && Notification.permission === 'granted') {
                    const latest = data.items[data.items.length - 1];
                    new Notification(latest.title, {body: latest.message});
                }
            }

            notificationLastId = Math.max(notificationLastId, newestId);
            localStorage.setItem('hrmoNotificationLastId', String(notificationLastId));
        }

        notificationInitialized = true;
    } catch (error) {
        console.debug('Notification polling temporarily unavailable.');
    }
}

async function markNotificationsRead() {
    try {
        const response = await fetch('index.php?api=mark_notifications_read', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'read=1'
        });
        const data = await response.json();
        if (data.ok) {
            const badge = document.querySelector('#notification-badge');
            if (badge) {
                badge.textContent = '0';
                badge.style.display = 'none';
            }
        }
    } catch (error) {
        console.debug('Unable to mark notifications as read.');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    updateDocumentNumbers();

    const notificationButton = document.querySelector('#notification-button');
    const notificationPanel = document.querySelector('#notification-panel');
    const markReadButton = document.querySelector('#mark-read-button');

    if (notificationButton && notificationPanel) {
        notificationButton.addEventListener('click', async function (event) {
            event.stopPropagation();
            notificationPanel.classList.toggle('open');
            if (notificationPanel.classList.contains('open')) {
                await markNotificationsRead();
            }

            if ('Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission().catch(() => {});
            }
        });

        notificationPanel.addEventListener('click', event => event.stopPropagation());
        document.addEventListener('click', () => notificationPanel.classList.remove('open'));
    }

    if (markReadButton) {
        markReadButton.addEventListener('click', markNotificationsRead);
    }

    fetchNotifications();
    setInterval(fetchNotifications, 5000);
});
</script>
</body>
</html>
<?php
}

render_header();

if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif;

if ($page === 'login') {
    if (user()) redirect('index.php?page=dashboard');
    ?>
    <section class="card auth">
        <img class="auth-logo" src="jrmsu-logo.png" alt="JRMSU Logo">
        <h2 class="auth-title">Account Login</h2>
        <p class="muted auth-subtitle">Enter your registered email address and password.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="login">
            <div style="margin-bottom:14px">
                <label>Email Address</label>
                <input type="email" name="email" required autocomplete="email">
            </div>
            <div style="margin-bottom:18px">
                <label>Password</label>
                <input type="password" name="password" required autocomplete="current-password">
            </div>
            <button class="btn btn-primary" type="submit">Login</button>
            <a class="btn btn-secondary" href="index.php?page=register">Create Account</a>
        </form>
        <hr style="border:0;border-top:1px solid #e5e7eb;margin:22px 0">
        <p class="muted"><strong>Default Admin:</strong> admin@jrmsu.local / Admin123!</p>
    </section>
    <?php
} elseif ($page === 'register') {
    if (user()) redirect('index.php?page=dashboard');
    ?>
    <section class="card auth" style="max-width:760px">
        <img class="auth-logo" src="jrmsu-logo.png" alt="JRMSU Logo">
        <h2 class="auth-title">Requester Registration</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="register">
            <div class="grid grid-2">
                <div>
                    <label>Full Name</label>
                    <input name="full_name" required>
                </div>
                <div>
                    <label>Position / Designation</label>
                    <input name="position_designation" required>
                </div>
                <div>
                    <label>Office / Department</label>
                    <input name="office_department" required>
                </div>
                <div>
                    <label>Phone Number</label>
                    <input name="phone" required>
                </div>
                <div>
                    <label>Email Address</label>
                    <input type="email" name="email" required>
                </div>
                <div></div>
                <div>
                    <label>Password</label>
                    <input type="password" name="password" minlength="8" required>
                </div>
                <div>
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" minlength="8" required>
                </div>
            </div>
            <div class="actions" style="margin-top:18px">
                <button class="btn btn-primary" type="submit">Register</button>
                <a class="btn btn-secondary" href="index.php?page=login">Back to Login</a>
            </div>
        </form>
    </section>
    <?php
} elseif ($page === 'dashboard') {
    require_login();

    if (user()['role'] === 'admin') {
        $stats = [];
        foreach (['Pending','Processing','Ready for Release','Released','Cancelled'] as $status) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM requests WHERE status = ?');
            $stmt->execute([$status]);
            $stats[$status] = (int)$stmt->fetchColumn();
        }
        $recent = $pdo->query(
            'SELECT r.id, r.request_no, r.status, r.date_requested, u.full_name, u.office_department,
                    COUNT(d.id) AS total_documents
             FROM requests r
             JOIN users u ON u.id = r.user_id
             LEFT JOIN request_documents d ON d.request_id = r.id
             GROUP BY r.id
             ORDER BY r.id DESC
             LIMIT 10'
        )->fetchAll();
        ?>
        <h2>HRMO Administrator Dashboard</h2>
        <div class="grid grid-5" style="margin-bottom:20px">
            <?php foreach ($stats as $label => $number): ?>
                <div class="stat">
                    <div class="number"><?= $number ?></div>
                    <div class="label"><?= e($label) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <section class="card">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:center">
                <h3>Recent Requests</h3>
                <a class="btn btn-primary btn-sm" href="index.php?page=admin_requests">View All</a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Request No.</th><th>Name</th><th>Office</th><th>Documents</th><th>Status</th><th>Date</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $row): ?>
                        <tr>
                            <td><?= e($row['request_no']) ?></td>
                            <td><?= e($row['full_name']) ?></td>
                            <td><?= e($row['office_department']) ?></td>
                            <td><?= (int)$row['total_documents'] ?></td>
                            <td><span class="badge badge-<?= status_class($row['status']) ?>"><?= e($row['status']) ?></span></td>
                            <td><?= e(date('M d, Y h:i A', strtotime($row['date_requested']))) ?></td>
                            <td><a class="btn btn-secondary btn-sm" href="index.php?page=view_request&id=<?= (int)$row['id'] ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recent): ?>
                        <tr><td colspan="7" class="muted">No requests yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php
    } else {
        $stmt = $pdo->prepare(
            'SELECT status, COUNT(*) AS total FROM requests WHERE user_id = ? GROUP BY status'
        );
        $stmt->execute([(int)user()['id']]);
        $counts = array_fill_keys(['Pending','Processing','Ready for Release','Released'], 0);
        foreach ($stmt->fetchAll() as $row) {
            if (isset($counts[$row['status']])) $counts[$row['status']] = (int)$row['total'];
        }

        $stmt = $pdo->prepare(
            'SELECT r.id, r.request_no, r.status, r.date_requested, COUNT(d.id) AS total_documents
             FROM requests r
             LEFT JOIN request_documents d ON d.request_id = r.id
             WHERE r.user_id = ?
             GROUP BY r.id
             ORDER BY r.id DESC
             LIMIT 8'
        );
        $stmt->execute([(int)user()['id']]);
        $recent = $stmt->fetchAll();
        ?>
        <h2>Welcome, <?= e(user()['full_name']) ?></h2>
        <div class="grid grid-5" style="margin-bottom:20px">
            <?php foreach ($counts as $label => $number): ?>
                <div class="stat">
                    <div class="number"><?= $number ?></div>
                    <div class="label"><?= e($label) ?></div>
                </div>
            <?php endforeach; ?>
            <div class="stat">
                <div class="number"><?= array_sum($counts) ?></div>
                <div class="label">Total Active Records</div>
            </div>
        </div>
        <section class="card">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:center">
                <h3>Recent Requests</h3>
                <a class="btn btn-primary btn-sm" href="index.php?page=new_request">+ New Request</a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Request No.</th><th>Documents</th><th>Status</th><th>Date</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $row): ?>
                        <tr>
                            <td><?= e($row['request_no']) ?></td>
                            <td><?= (int)$row['total_documents'] ?></td>
                            <td><span class="badge badge-<?= status_class($row['status']) ?>"><?= e($row['status']) ?></span></td>
                            <td><?= e(date('M d, Y h:i A', strtotime($row['date_requested']))) ?></td>
                            <td><a class="btn btn-secondary btn-sm" href="index.php?page=view_request&id=<?= (int)$row['id'] ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recent): ?>
                        <tr><td colspan="5" class="muted">You have not submitted a request yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php
    }
} elseif ($page === 'new_request') {
    require_login();
    if (user()['role'] !== 'requester') redirect('index.php?page=dashboard');
    ?>
    <section class="card">
        <h2>New Document Request</h2>
        <p class="muted">Type the document you need, select Original, Photocopy, or Others, and add another item when necessary.</p>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_request">

            <h3 class="section-title">Requester Information</h3>
            <div class="grid grid-2">
                <div><label>Full Name</label><input value="<?= e(user()['full_name']) ?>" readonly></div>
                <div><label>Position / Designation</label><input value="<?= e(user()['position_designation']) ?>" readonly></div>
                <div><label>Office / Department</label><input value="<?= e(user()['office_department']) ?>" readonly></div>
                <div><label>Phone Number</label><input value="<?= e(user()['phone']) ?>" readonly></div>
            </div>

            <h3 class="section-title">Document Request/s</h3>
            <div id="documents-container">
                <div class="doc-row">
                    <div class="doc-row-head">
                        <span class="doc-number">Document Request #1</span>
                        <button class="btn btn-danger btn-sm remove-doc" type="button">Remove</button>
                    </div>
                    <div class="grid grid-3">
                        <div>
                            <label>Document Requested</label>
                            <input name="document_name[]" placeholder="Example: Certificate of Employment" required>
                        </div>
                        <div>
                            <label>Copy Type</label>
                            <select name="copy_type[]" required>
                                <option value="Original">Original</option>
                                <option value="Photocopy">Photocopy</option>
                                <option value="Others">Others</option>
                            </select>
                        </div>
                        <div>
                            <label>Number of Copies</label>
                            <input type="number" name="copies[]" min="1" max="100" value="1" required>
                        </div>
                    </div>
                    <div class="other-wrap" style="margin-top:12px">
                        <label>Please Specify the Other Copy Type</label>
                        <input name="other_copy_type[]" placeholder="Example: Certified True Copy">
                    </div>
                </div>
            </div>

            <button id="add-document" class="btn btn-secondary" type="button">+ Add Another Document</button>

            <h3 class="section-title">Purpose and Remarks</h3>
            <div class="grid grid-2">
                <div>
                    <label>Purpose</label>
                    <select id="purpose" name="purpose" required>
                        <option value="">Select purpose</option>
                        <option>Employment</option>
                        <option>Promotion</option>
                        <option>Loan</option>
                        <option>Visa</option>
                        <option>Scholarship</option>
                        <option>Personal Copy</option>
                        <option>Others</option>
                    </select>
                    <div id="purpose-other-wrap" style="display:none;margin-top:12px">
                        <label>Please Specify</label>
                        <input id="purpose_other" name="purpose_other">
                    </div>
                </div>
                <div>
                    <label>Remarks (Optional)</label>
                    <textarea name="remarks" placeholder="Additional instructions or details"></textarea>
                </div>
            </div>

            <div class="actions" style="margin-top:20px">
                <button class="btn btn-primary" type="submit">Submit Request</button>
                <a class="btn btn-secondary" href="index.php?page=dashboard">Cancel</a>
            </div>
        </form>
    </section>
    <?php
} elseif ($page === 'my_requests') {
    require_login();
    if (user()['role'] !== 'requester') redirect('index.php?page=dashboard');

    $stmt = $pdo->prepare(
        'SELECT r.id, r.request_no, r.purpose, r.status, r.date_requested, COUNT(d.id) AS total_documents
         FROM requests r
         LEFT JOIN request_documents d ON d.request_id = r.id
         WHERE r.user_id = ?
         GROUP BY r.id
         ORDER BY r.id DESC'
    );
    $stmt->execute([(int)user()['id']]);
    $rows = $stmt->fetchAll();
    ?>
    <section class="card">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:center">
            <h2>My Requests</h2>
            <a class="btn btn-primary" href="index.php?page=new_request">+ New Request</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Request No.</th><th>Purpose</th><th>Documents</th><th>Status</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['request_no']) ?></td>
                        <td><?= e($row['purpose']) ?></td>
                        <td><?= (int)$row['total_documents'] ?></td>
                        <td><span class="badge badge-<?= status_class($row['status']) ?>"><?= e($row['status']) ?></span></td>
                        <td><?= e(date('M d, Y h:i A', strtotime($row['date_requested']))) ?></td>
                        <td><a class="btn btn-secondary btn-sm" href="index.php?page=view_request&id=<?= (int)$row['id'] ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="muted">No requests found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
} elseif ($page === 'admin_requests') {
    require_admin();

    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $search = trim((string)($_GET['search'] ?? ''));

    $sql =
        'SELECT r.id, r.request_no, r.purpose, r.status, r.date_requested,
                u.full_name, u.office_department, COUNT(d.id) AS total_documents
         FROM requests r
         JOIN users u ON u.id = r.user_id
         LEFT JOIN request_documents d ON d.request_id = r.id
         WHERE 1=1';
    $params = [];

    if ($statusFilter !== '') {
        $sql .= ' AND r.status = ?';
        $params[] = $statusFilter;
    }
    if ($search !== '') {
        $sql .= ' AND (r.request_no LIKE ? OR u.full_name LIKE ? OR u.office_department LIKE ? OR r.purpose LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $sql .= ' GROUP BY r.id ORDER BY r.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <section class="card">
        <h2>Manage Document Requests</h2>
        <form method="get" class="grid grid-3 no-print" style="margin-bottom:18px">
            <input type="hidden" name="page" value="admin_requests">
            <div>
                <label>Search</label>
                <input name="search" value="<?= e($search) ?>" placeholder="Request no., name, office, purpose">
            </div>
            <div>
                <label>Status</label>
                <select name="status">
                    <option value="">All Statuses</option>
                    <?php foreach (['Pending','Processing','Ready for Release','Released','Cancelled'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;align-items:end;gap:8px">
                <button class="btn btn-primary" type="submit">Filter</button>
                <a class="btn btn-secondary" href="index.php?page=admin_requests">Reset</a>
            </div>
        </form>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Request No.</th><th>Requester</th><th>Office</th><th>Purpose</th><th>Documents</th><th>Status</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['request_no']) ?></td>
                        <td><?= e($row['full_name']) ?></td>
                        <td><?= e($row['office_department']) ?></td>
                        <td><?= e($row['purpose']) ?></td>
                        <td><?= (int)$row['total_documents'] ?></td>
                        <td><span class="badge badge-<?= status_class($row['status']) ?>"><?= e($row['status']) ?></span></td>
                        <td><?= e(date('M d, Y h:i A', strtotime($row['date_requested']))) ?></td>
                        <td><a class="btn btn-secondary btn-sm" href="index.php?page=view_request&id=<?= (int)$row['id'] ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="muted">No matching requests found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
} elseif ($page === 'telegram_settings') {
    require_admin();

    $logs = $pdo->query('SELECT * FROM telegram_queue ORDER BY id DESC LIMIT 30')->fetchAll();
    ?>
    <section class="card">
        <h2>GitHub Telegram Relay Queue</h2>
        <p class="muted">The HRMO system stores messages here. GitHub Actions fetches them and sends them to Telegram.</p>

        <div class="details" style="margin-bottom:20px">
            <div>Relay API</div><div>Enabled</div>
            <div>Queue Endpoint</div><div><code>?api=github_telegram_queue</code></div>
            <div>Acknowledge Endpoint</div><div><code>?api=github_telegram_ack</code></div>
            <div>Pending Messages</div><div><?= (int)$pdo->query("SELECT COUNT(*) FROM telegram_queue WHERE status IN ('pending','failed')")->fetchColumn() ?></div>
        </div>

        <form method="post" class="no-print" style="margin-bottom:24px">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="test_telegram">
            <button class="btn btn-primary" type="submit">Add Test Message to Relay Queue</button>
        </form>

        <h3 class="section-title">Recent Queue Records</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Date</th><th>Event</th><th>Status</th><th>Attempts</th><th>Message / Error</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= e(date('M d, Y h:i:s A', strtotime($log['created_at']))) ?></td>
                        <td><?= e($log['event_type']) ?></td>
                        <td><span class="badge badge-<?= $log['status'] === 'sent' ? 'success' : ($log['status'] === 'failed' ? 'danger' : 'warning') ?>"><?= e($log['status']) ?></span></td>
                        <td><?= (int)$log['attempts'] ?></td>
                        <td style="max-width:520px;white-space:pre-wrap"><?= e($log['last_error'] ?: $log['message']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?><tr><td colspan="5" class="muted">No queued messages yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
} elseif ($page === 'view_request') {
    require_login();
    $requestId = (int)($_GET['id'] ?? 0);

    $stmt = $pdo->prepare(
        'SELECT r.*, u.full_name, u.position_designation, u.office_department, u.email, u.phone,
                p.full_name AS processor_name
         FROM requests r
         JOIN users u ON u.id = r.user_id
         LEFT JOIN users p ON p.id = r.processed_by
         WHERE r.id = ?'
    );
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();

    if (!$request) {
        http_response_code(404);
        exit('Request not found.');
    }

    if (user()['role'] !== 'admin' && (int)$request['user_id'] !== (int)user()['id']) {
        http_response_code(403);
        exit('Access denied.');
    }

    $stmt = $pdo->prepare('SELECT * FROM request_documents WHERE request_id = ? ORDER BY id');
    $stmt->execute([$requestId]);
    $documents = $stmt->fetchAll();
    ?>
    <section class="card">
        <div class="print-only" style="text-align:center;margin-bottom:18px">
            <h2 style="margin-bottom:3px">JOSE RIZAL MEMORIAL STATE UNIVERSITY</h2>
            <div>Siocon Campus · Human Resource Management Office</div>
            <h3 style="margin-top:14px">DOCUMENT REQUEST SLIP</h3>
        </div>

        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start">
            <div>
                <h2>Request <?= e($request['request_no']) ?></h2>
                <span class="badge badge-<?= status_class($request['status']) ?>"><?= e($request['status']) ?></span>
            </div>
            <div class="actions no-print">
                <button class="btn btn-secondary" onclick="window.print()">Print</button>
                <a class="btn btn-secondary" href="<?= user()['role'] === 'admin' ? 'index.php?page=admin_requests' : 'index.php?page=my_requests' ?>">Back</a>
            </div>
        </div>

        <h3 class="section-title">Requester Information</h3>
        <div class="details">
            <div>Name</div><div><?= e($request['full_name']) ?></div>
            <div>Position / Designation</div><div><?= e($request['position_designation']) ?></div>
            <div>Office / Department</div><div><?= e($request['office_department']) ?></div>
            <div>Email Address</div><div><?= e($request['email']) ?></div>
            <div>Phone Number</div><div><?= e($request['phone']) ?></div>
            <div>Date Requested</div><div><?= e(date('F d, Y h:i A', strtotime($request['date_requested']))) ?></div>
        </div>

        <h3 class="section-title">Requested Documents</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>#</th><th>Document Requested</th><th>Copy Type</th><th>Number of Copies</th></tr></thead>
                <tbody>
                <?php foreach ($documents as $i => $doc): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= e($doc['document_name']) ?></td>
                        <td><?= e($doc['copy_type'] === 'Others' ? $doc['other_copy_type'] : $doc['copy_type']) ?></td>
                        <td><?= (int)$doc['copies'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <h3 class="section-title">Purpose and Processing Details</h3>
        <div class="details">
            <div>Purpose</div><div><?= e($request['purpose']) ?></div>
            <div>Remarks</div><div><?= e($request['remarks'] ?: 'None') ?></div>
            <div>Processed By</div><div><?= e($request['processor_name'] ?: 'Not yet assigned') ?></div>
            <div>Release Date</div><div><?= e($request['release_date'] ?: '—') ?></div>
            <div>Release Time</div><div><?= e($request['release_time'] ? date('h:i A', strtotime($request['release_time'])) : '—') ?></div>
        </div>

        <?php if (user()['role'] === 'admin'): ?>
            <div class="no-print">
                <h3 class="section-title">Update Status</h3>
                <form method="post" class="grid grid-2">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                    <div>
                        <label>Status</label>
                        <select name="status" required>
                            <?php foreach (['Pending','Processing','Ready for Release','Released','Cancelled'] as $status): ?>
                                <option value="<?= e($status) ?>" <?= $request['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:flex;align-items:end">
                        <button class="btn btn-primary" type="submit">Save Status</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </section>
    <?php
} else {
    http_response_code(404);
    echo '<section class="card"><h2>Page not found</h2></section>';
}

render_footer();
