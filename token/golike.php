<?php
/**
 * ==========================================================
 * XỬ LÝ & ĐỒNG BỘ TOKEN ĐA NỀN TẢNG (GOLIKE, TDS, TTC...)
 * File: token/golike.php
 * Website: ThanhQuyTech
 * ==========================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';

/**
 * Tự động kiểm tra và khởi tạo bảng `tokens` nếu chưa tồn tại
 * Hỗ trợ đa nền tảng (Golike, TDS, TTC...) với cột `platform`
 */
function ensure_tokens_table(PDO $pdo): void {
    try {
        // Tự động chuyển đổi nếu đã có bảng golike_tokens cũ
        $checkOld = $pdo->query("SHOW TABLES LIKE 'golike_tokens'");
        if ($checkOld && $checkOld->rowCount() > 0) {
            $checkNew = $pdo->query("SHOW TABLES LIKE 'tokens'");
            if (!$checkNew || $checkNew->rowCount() === 0) {
                $pdo->exec("RENAME TABLE `golike_tokens` TO `tokens`");
            }
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `tokens` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_uuid` CHAR(36) NOT NULL,
                `platform` VARCHAR(50) NOT NULL DEFAULT 'golike' COMMENT 'Nền tảng (golike, tds, ttc...)',
                `account_id` VARCHAR(50) NOT NULL COMMENT 'ID tài khoản trên nền tảng',
                `name` VARCHAR(150) NOT NULL COMMENT 'Tên hiển thị tài khoản',
                `username` VARCHAR(150) NOT NULL COMMENT 'Tên người dùng / username',
                `coin` BIGINT NOT NULL DEFAULT 0 COMMENT 'Số dư xu / coin',
                `token` TEXT NOT NULL COMMENT 'Chuỗi Authorization Token Bearer hoặc Access Token',
                `status` ENUM('Active', 'Expired', 'Error') NOT NULL DEFAULT 'Active',
                `last_checked_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_tokens_user_uuid` (`user_uuid`),
                INDEX `idx_tokens_platform` (`platform`),
                INDEX `idx_tokens_account_id` (`account_id`),
                INDEX `idx_tokens_status` (`status`),
                UNIQUE KEY `uq_user_platform_account` (`user_uuid`, `platform`, `account_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Kiểm tra và bổ sung cột platform nếu bảng cũ chưa có
        $colCheck = $pdo->query("SHOW COLUMNS FROM `tokens` LIKE 'platform'");
        if ($colCheck && $colCheck->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `tokens` ADD COLUMN `platform` VARCHAR(50) NOT NULL DEFAULT 'golike' AFTER `user_uuid`");
        }

        // Kiểm tra và đồng bộ cột account_id
        $colCheckAcc = $pdo->query("SHOW COLUMNS FROM `tokens` LIKE 'account_id'");
        if ($colCheckAcc && $colCheckAcc->rowCount() === 0) {
            $colCheckGo = $pdo->query("SHOW COLUMNS FROM `tokens` LIKE 'golike_id'");
            if ($colCheckGo && $colCheckGo->rowCount() > 0) {
                $pdo->exec("ALTER TABLE `tokens` CHANGE `golike_id` `account_id` VARCHAR(50) NOT NULL");
            } else {
                $pdo->exec("ALTER TABLE `tokens` ADD COLUMN `account_id` VARCHAR(50) NOT NULL AFTER `platform`");
            }
        }

        // Kiểm tra và bổ sung cột image trong bảng platforms & lưu ảnh Golike
        try {
            $colPlatImg = $pdo->query("SHOW COLUMNS FROM `platforms` LIKE 'image'");
            if ($colPlatImg && $colPlatImg->rowCount() === 0) {
                $pdo->exec("ALTER TABLE `platforms` ADD COLUMN `image` VARCHAR(255) DEFAULT NULL AFTER `icon`");
            }
            $pdo->exec("UPDATE `platforms` SET `image` = 'https://cdn.jsdelivr.net/gh/thanhquytech-stack/images@main/golike.png' WHERE `code` = 'golike'");
        } catch (Exception $e) {
            // Bỏ qua nếu bảng platforms chưa có
        }
    } catch (Exception $e) {
        // Bỏ qua nếu bảng đã sẵn sàng
    }
}

// Giữ alias ensure_golike_table để đảm bảo tương thích ngược
function ensure_golike_table(PDO $pdo): void {
    ensure_tokens_table($pdo);
}

/**
 * 1. Gọi API Golike /api/users/me với token (tương đương me(token) trong token/golike.py)
 */
function me(string $token): array {
    $token = trim($token);
    if ($token === '') {
        return [
            'status'  => 400,
            'success' => false,
            'message' => 'Mã token Golike không được để trống.'
        ];
    }

    if (stripos($token, 'Bearer ') !== 0) {
        $token = 'Bearer ' . $token;
    }

    $headers = [
        'accept: application/json, text/plain, */*',
        'accept-language: vi,en;q=0.9,en-GB;q=0.8,en-US;q=0.7',
        'authorization: ' . $token,
        'content-type: application/json;charset=utf-8',
        'origin: https://app.golike.net',
        'priority: u=1, i',
        'sec-ch-ua: "Chromium";v="152", "Not?A_Brand";v="24", "Google Chrome";v="152"',
        'sec-ch-ua-mobile: ?1',
        'sec-ch-ua-platform: "iOS"',
        'sec-fetch-dest: empty',
        'sec-fetch-mode: cors',
        'sec-fetch-site: same-site',
        'user-agent: Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1',
    ];

    $ch = curl_init('https://gateway.golike.net/api/users/me');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 7,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || !empty($curlErr)) {
        return [
            'status'  => 500,
            'success' => false,
            'message' => 'Lỗi kết nối tới máy chủ Golike: ' . $curlErr
        ];
    }

    $json = json_decode($response, true);
    if (!is_array($json)) {
        return [
            'status'  => $httpCode ?: 500,
            'success' => false,
            'message' => 'Không thể giải mã phản hồi JSON từ Golike.',
            'raw'     => $response
        ];
    }

    return $json;
}

/**
 * 2. Gọi API Trao Đổi Sub (TDS) kiểm tra token
 */
function check_tds_token(string $token): array {
    $token = trim($token);
    if ($token === '') {
        return [
            'status'  => 400,
            'success' => false,
            'message' => 'Access Token Trao Đổi Sub không được để trống.'
        ];
    }

    $url = 'https://traodoisub.com/api/?fields=profile&access_token=' . urlencode($token);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false || !empty($err)) {
        return [
            'status'  => 500,
            'success' => false,
            'message' => 'Không thể kết nối máy chủ Trao Đổi Sub: ' . $err
        ];
    }

    $json = json_decode($res, true);
    if (is_array($json) && (!empty($json['success']) || isset($json['data']['user']))) {
        $userData = $json['data'] ?? $json;
        $user = (string)($userData['user'] ?? 'TDS_User');
        $xu = (int)($userData['xu'] ?? 0);
        return [
            'status'  => 200,
            'success' => true,
            'data'    => [
                'id'       => $user,
                'name'     => $user,
                'username' => $user,
                'coin'     => $xu
            ]
        ];
    }

    return [
        'status'  => 400,
        'success' => false,
        'message' => $json['error'] ?? 'Token Trao Đổi Sub (TDS) không chính xác hoặc đã hết hạn.'
    ];
}

/**
 * 3. Gọi API Tương Tác Chéo (TTC) kiểm tra token
 */
function check_ttc_token(string $token): array {
    $token = trim($token);
    if ($token === '') {
        return [
            'status'  => 400,
            'success' => false,
            'message' => 'Token Tương Tác Chéo không được để trống.'
        ];
    }

    $url = 'https://tuongtaccheo.com/logintoken.php';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['access_token' => $token]),
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false || !empty($err)) {
        return [
            'status'  => 500,
            'success' => false,
            'message' => 'Không thể kết nối máy chủ Tương Tác Chéo: ' . $err
        ];
    }

    $json = json_decode($res, true);
    if (is_array($json) && isset($json['status']) && $json['status'] === 'success') {
        $user = (string)($json['data']['user'] ?? $json['data']['username'] ?? 'TTC_User');
        $coin = (int)($json['data']['sodu'] ?? $json['data']['xu'] ?? 0);
        return [
            'status'  => 200,
            'success' => true,
            'data'    => [
                'id'       => $user,
                'name'     => $user,
                'username' => $user,
                'coin'     => $coin
            ]
        ];
    }

    return [
        'status'  => 400,
        'success' => false,
        'message' => $json['message'] ?? 'Token Tương Tác Chéo (TTC) không chính xác hoặc đã hết hạn.'
    ];
}

/**
 * 4. Điều phối kiểm tra token theo nền tảng được chọn
 */
function fetch_platform_info(string $platform, string $token): array {
    $platform = strtolower(trim($platform));
    if ($platform === 'golike') {
        return me($token);
    }
    if ($platform === 'tds') {
        return check_tds_token($token);
    }
    if ($platform === 'ttc') {
        return check_ttc_token($token);
    }

    // Nền tảng khác / JWT chung: Thử trích xuất payload nếu là chuỗi JWT chuẩn
    $tokenClean = str_ireplace('Bearer ', '', trim($token));
    $parts = explode('.', $tokenClean);
    $name = 'Tài khoản ' . strtoupper($platform);
    $username = 'user_' . substr(md5($token), 0, 8);
    $accId = substr(md5($token), 0, 8);

    if (count($parts) === 3) {
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (is_array($payload)) {
            $name = $payload['name'] ?? $payload['sub'] ?? $name;
            $username = $payload['username'] ?? $payload['user'] ?? $payload['sub'] ?? $username;
            $accId = (string)($payload['id'] ?? $payload['sub'] ?? $accId);
        }
    }

    return [
        'status'  => 200,
        'success' => true,
        'data'    => [
            'id'       => $accId,
            'name'     => $name,
            'username' => $username,
            'coin'     => 0
        ]
    ];
}

/**
 * 5. Lưu hoặc cập nhật tài khoản token theo nền tảng vào bảng `tokens`
 */
function save_or_update_platform_account(PDO $pdo, string $userUuid, string $platform, string $rawToken): array {
    $rawToken = trim($rawToken);
    if ($rawToken === '') {
        return [
            'success' => false,
            'message' => 'Vui lòng nhập hoặc dán mã Token.'
        ];
    }

    $platform = strtolower(trim($platform)) ?: 'golike';
    $tokenToSave = $rawToken;

    // Đối với Golike: Đảm bảo có tiền tố Bearer
    if ($platform === 'golike' && stripos($rawToken, 'Bearer ') !== 0) {
        $tokenToSave = 'Bearer ' . $rawToken;
    }

    // Gọi hàm kiểm tra theo nền tảng
    $res = fetch_platform_info($platform, $tokenToSave);

    $isOk = (
        isset($res['status']) && 
        (int)$res['status'] === 200 && 
        (!empty($res['success']) || !empty($res['data'])) && 
        !empty($res['data'])
    );

    if (!$isOk) {
        $errMsg = $res['message'] ?? 'Token không hợp lệ hoặc đã hết hạn trên nền tảng đã chọn.';
        return [
            'success' => false,
            'message' => $errMsg,
            'data'    => $res
        ];
    }

    $accountData = $res['data'];
    $accId    = (string)($accountData['id'] ?? '');
    $name     = (string)($accountData['name'] ?? 'Tài khoản ' . strtoupper($platform));
    $username = (string)($accountData['username'] ?? 'user_' . substr(md5($tokenToSave), 0, 8));
    $coin     = (int)($accountData['coin'] ?? 0);

    if ($accId === '') {
        $accId = substr(md5($tokenToSave), 0, 8);
    }

    ensure_tokens_table($pdo);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO `tokens` 
                (`user_uuid`, `platform`, `account_id`, `name`, `username`, `coin`, `token`, `status`, `last_checked_at`, `updated_at`)
            VALUES 
                (?, ?, ?, ?, ?, ?, ?, 'Active', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                `name` = VALUES(`name`),
                `username` = VALUES(`username`),
                `coin` = VALUES(`coin`),
                `token` = VALUES(`token`),
                `status` = 'Active',
                `last_checked_at` = NOW(),
                `updated_at` = NOW()
        ");
        $stmt->execute([$userUuid, $platform, $accId, $name, $username, $coin, $tokenToSave]);

        $formattedCoin = number_format($coin, 0, ',', '.') . ' xu';

        return [
            'success'        => true,
            'message'        => "Đã liên kết tài khoản {$name} (@{$username}) trên nền tảng " . strtoupper($platform) . " thành công!",
            'platform'       => $platform,
            'account_id'     => $accId,
            'golike_id'      => $accId,
            'name'           => $name,
            'username'       => $username,
            'coin'           => $coin,
            'coin_formatted' => $formattedCoin,
            'token'          => $tokenToSave,
            'raw_data'       => $accountData
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Lỗi cơ sở dữ liệu khi lưu token: ' . $e->getMessage()
        ];
    }
}

// Alias cho hàm cũ để giữ tương thích
function save_or_update_golike_account(PDO $pdo, string $userUuid, string $rawToken): array {
    return save_or_update_platform_account($pdo, $userUuid, 'golike', $rawToken);
}

/**
 * 6. Làm mới (đồng bộ) số dư và trạng thái tài khoản
 */
function refresh_platform_account(PDO $pdo, string $userUuid, int $recordId): array {
    ensure_tokens_table($pdo);

    $stmt = $pdo->prepare("
        SELECT id, platform, account_id, account_id AS golike_id, name, username, coin, token, status 
        FROM `tokens` 
        WHERE `id` = ? AND `user_uuid` = ? 
        LIMIT 1
    ");
    $stmt->execute([$recordId, $userUuid]);
    $account = $stmt->fetch();

    if (!$account) {
        return [
            'success' => false,
            'message' => 'Không tìm thấy tài khoản yêu cầu.'
        ];
    }

    $plat = $account['platform'] ?: 'golike';
    $res = fetch_platform_info($plat, $account['token']);
    $isOk = (
        isset($res['status']) && 
        (int)$res['status'] === 200 && 
        (!empty($res['success']) || !empty($res['data'])) && 
        !empty($res['data'])
    );

    if (!$isOk) {
        $stmtUpdate = $pdo->prepare("
            UPDATE `tokens` 
            SET `status` = 'Expired', `last_checked_at` = NOW() 
            WHERE `id` = ? AND `user_uuid` = ?
        ");
        $stmtUpdate->execute([$recordId, $userUuid]);

        return [
            'success' => false,
            'status'  => 'Expired',
            'message' => 'Token đã hết hạn hoặc không hợp lệ trên nền tảng ' . strtoupper($plat) . '.'
        ];
    }

    $accountData = $res['data'];
    $name     = (string)($accountData['name'] ?? $account['name']);
    $username = (string)($accountData['username'] ?? $account['username']);
    $coin     = (int)($accountData['coin'] ?? 0);

    $stmtUpdate = $pdo->prepare("
        UPDATE `tokens` 
        SET `name` = ?, `username` = ?, `coin` = ?, `status` = 'Active', `last_checked_at` = NOW(), `updated_at` = NOW() 
        WHERE `id` = ? AND `user_uuid` = ?
    ");
    $stmtUpdate->execute([$name, $username, $coin, $recordId, $userUuid]);

    $formattedCoin = number_format($coin, 0, ',', '.') . ' xu';

    return [
        'success'        => true,
        'status'         => 'Active',
        'message'        => "Cập nhật thành công số dư tài khoản {$name}: {$formattedCoin}",
        'coin'           => $coin,
        'coin_formatted' => $formattedCoin
    ];
}

// Alias cho hàm cũ
function refresh_golike_account(PDO $pdo, string $userUuid, int $recordId): array {
    return refresh_platform_account($pdo, $userUuid, $recordId);
}

// ----------------------------------------------------------
// XỬ LÝ KHI GỌI TRỰC TIẾP QUA AJAX / HTTP POST
// ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.'
        ]);
        exit;
    }

    $stmtUser = $pdo->prepare("SELECT uuid FROM users WHERE id = ? LIMIT 1");
    $stmtUser->execute([$_SESSION['user_id']]);
    $userUuid = $stmtUser->fetchColumn();

    if (!$userUuid) {
        echo json_encode([
            'success' => false,
            'message' => 'Không xác định được tài khoản người dùng.'
        ]);
        exit;
    }

    $action = $_POST['ajax_action'];

    // 1. Kiểm tra nhanh token (không lưu)
    if ($action === 'check_token') {
        $token = trim($_POST['token'] ?? '');
        $platform = trim($_POST['platform'] ?? 'golike');
        $res = fetch_platform_info($platform, $token);
        if (isset($res['status']) && (int)$res['status'] === 200 && (!empty($res['success']) || !empty($res['data'])) && !empty($res['data'])) {
            $coin = (int)($res['data']['coin'] ?? 0);
            echo json_encode([
                'success' => true,
                'data'    => [
                    'id'             => $res['data']['id'] ?? '',
                    'name'           => $res['data']['name'] ?? '',
                    'username'       => $res['data']['username'] ?? '',
                    'coin'           => $coin,
                    'coin_formatted' => number_format($coin, 0, ',', '.') . ' xu'
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $res['message'] ?? 'Mã Token không hợp lệ hoặc đã hết hạn.'
            ]);
        }
        exit;
    }

    // 2. Lưu token vào danh sách
    if ($action === 'save_token') {
        $token = trim($_POST['token'] ?? '');
        $platform = trim($_POST['platform'] ?? 'golike');
        $result = save_or_update_platform_account($pdo, $userUuid, $platform, $token);
        echo json_encode($result);
        exit;
    }

    // 3. Làm mới số dư của tài khoản
    if ($action === 'refresh_account') {
        $recordId = (int)($_POST['record_id'] ?? 0);
        $result = refresh_platform_account($pdo, $userUuid, $recordId);
        echo json_encode($result);
        exit;
    }

    // 4. Xóa tài khoản
    if ($action === 'delete_account') {
        $recordId = (int)($_POST['record_id'] ?? 0);
        ensure_tokens_table($pdo);
        $stmt = $pdo->prepare("DELETE FROM `tokens` WHERE `id` = ? AND `user_uuid` = ?");
        $stmt->execute([$recordId, $userUuid]);
        echo json_encode([
            'success' => true,
            'message' => 'Đã xóa tài khoản khỏi danh sách.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => false,
        'message' => 'Yêu cầu không hợp lệ.'
    ]);
    exit;
}
