<?php
/**
 * ==========================================================
 * XỬ LÝ & ĐỒNG BỘ TOKEN GOLIKE - THANHQUYTECH
 * File: token/golike.php
 * Chuyển đổi từ: token/golike.py
 * ==========================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';

/**
 * Tự động kiểm tra và khởi tạo bảng `golike_tokens` nếu chưa tồn tại
 */
function ensure_golike_table(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `golike_tokens` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_uuid` CHAR(36) NOT NULL,
                `golike_id` VARCHAR(50) NOT NULL,
                `name` VARCHAR(150) NOT NULL,
                `username` VARCHAR(150) NOT NULL,
                `coin` BIGINT NOT NULL DEFAULT 0,
                `token` TEXT NOT NULL,
                `status` ENUM('Active', 'Expired', 'Error') NOT NULL DEFAULT 'Active',
                `last_checked_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_gtoken_user_uuid` (`user_uuid`),
                INDEX `idx_gtoken_golike_id` (`golike_id`),
                INDEX `idx_gtoken_status` (`status`),
                UNIQUE KEY `uq_user_golike_id` (`user_uuid`, `golike_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (Exception $e) {
        // Bỏ qua nếu bảng đã sẵn sàng
    }
}

/**
 * Gọi API Golike /api/users/me với token (tương đương hàm me(token) trong token/golike.py)
 *
 * @param string $token Token JWT (có hoặc không có tiền tố Bearer)
 * @return array Mảng dữ liệu JSON trả về từ Golike API
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

    // Chuẩn hóa token: tự động thêm tiền tố "Bearer " nếu người dùng chưa nhập
    if (stripos($token, 'Bearer ') !== 0) {
        $token = 'Bearer ' . $token;
    }

    // Headers chuẩn tương ứng chính xác file token/golike.py
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
 * Lấy thông tin từ Golike và lưu/cập nhật vào bảng cơ sở dữ liệu `golike_tokens`
 *
 * @param PDO $pdo
 * @param string $userUuid
 * @param string $rawToken
 * @return array Kết quả xử lý
 */
function save_or_update_golike_account(PDO $pdo, string $userUuid, string $rawToken): array {
    $rawToken = trim($rawToken);
    if ($rawToken === '') {
        return [
            'success' => false,
            'message' => 'Vui lòng nhập chuỗi Token Golike.'
        ];
    }

    // Đảm bảo token có tiền tố Bearer khi lưu trữ & gửi
    $tokenWithBearer = (stripos($rawToken, 'Bearer ') === 0) ? $rawToken : ('Bearer ' . $rawToken);

    // Gọi API Golike kiểm tra thông tin
    $res = me($tokenWithBearer);

    $isOk = (
        isset($res['status']) && 
        (int)$res['status'] === 200 && 
        !empty($res['success']) && 
        !empty($res['data'])
    );

    if (!$isOk) {
        $errMsg = $res['message'] ?? 'Token Golike không chính xác hoặc đã hết hạn đăng nhập.';
        return [
            'success' => false,
            'message' => $errMsg,
            'data'    => $res
        ];
    }

    $accountData = $res['data'];
    $golikeId = (string)($accountData['id'] ?? '');
    $name     = (string)($accountData['name'] ?? '');
    $username = (string)($accountData['username'] ?? '');
    $coin     = (int)($accountData['coin'] ?? 0);

    if ($golikeId === '') {
        return [
            'success' => false,
            'message' => 'Không tìm thấy ID người dùng trong dữ liệu Golike trả về.',
            'data'    => $res
        ];
    }

    // Đảm bảo cấu trúc bảng tồn tại
    ensure_golike_table($pdo);

    try {
        // Sử dụng INSERT ... ON DUPLICATE KEY UPDATE để nếu đã tồn tại thì cập nhật
        $stmt = $pdo->prepare("
            INSERT INTO `golike_tokens` 
                (`user_uuid`, `golike_id`, `name`, `username`, `coin`, `token`, `status`, `last_checked_at`, `updated_at`)
            VALUES 
                (?, ?, ?, ?, ?, ?, 'Active', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                `name` = VALUES(`name`),
                `username` = VALUES(`username`),
                `coin` = VALUES(`coin`),
                `token` = VALUES(`token`),
                `status` = 'Active',
                `last_checked_at` = NOW(),
                `updated_at` = NOW()
        ");
        $stmt->execute([$userUuid, $golikeId, $name, $username, $coin, $tokenWithBearer]);

        $formattedCoin = number_format($coin, 0, ',', '.') . ' xu';

        return [
            'success'        => true,
            'message'        => "Đã liên kết tài khoản Golike thành công: {$name} (@{$username})",
            'golike_id'      => $golikeId,
            'name'           => $name,
            'username'       => $username,
            'coin'           => $coin,
            'coin_formatted' => $formattedCoin,
            'token'          => $tokenWithBearer,
            'raw_data'       => $accountData
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Lỗi cơ sở dữ liệu khi lưu token Golike: ' . $e->getMessage()
        ];
    }
}

/**
 * Làm mới (đồng bộ) số dư và thông tin của một tài khoản Golike đã lưu
 */
function refresh_golike_account(PDO $pdo, string $userUuid, int $recordId): array {
    ensure_golike_table($pdo);

    $stmt = $pdo->prepare("
        SELECT id, golike_id, name, username, coin, token, status 
        FROM `golike_tokens` 
        WHERE `id` = ? AND `user_uuid` = ? 
        LIMIT 1
    ");
    $stmt->execute([$recordId, $userUuid]);
    $account = $stmt->fetch();

    if (!$account) {
        return [
            'success' => false,
            'message' => 'Không tìm thấy tài khoản Golike yêu cầu.'
        ];
    }

    $res = me($account['token']);
    $isOk = (
        isset($res['status']) && 
        (int)$res['status'] === 200 && 
        !empty($res['success']) && 
        !empty($res['data'])
    );

    if (!$isOk) {
        // Đánh dấu token hết hạn
        $stmtUpdate = $pdo->prepare("
            UPDATE `golike_tokens` 
            SET `status` = 'Expired', `last_checked_at` = NOW() 
            WHERE `id` = ? AND `user_uuid` = ?
        ");
        $stmtUpdate->execute([$recordId, $userUuid]);

        return [
            'success' => false,
            'status'  => 'Expired',
            'message' => 'Token Golike đã hết hạn đăng nhập hoặc không hợp lệ. Vui lòng cập nhật token mới.'
        ];
    }

    $accountData = $res['data'];
    $name     = (string)($accountData['name'] ?? $account['name']);
    $username = (string)($accountData['username'] ?? $account['username']);
    $coin     = (int)($accountData['coin'] ?? 0);

    $stmtUpdate = $pdo->prepare("
        UPDATE `golike_tokens` 
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

    // 1. Kiểm tra nhanh token Golike (chỉ kiểm tra không lưu)
    if ($action === 'check_token') {
        $token = trim($_POST['token'] ?? '');
        $res = me($token);
        if (isset($res['status']) && (int)$res['status'] === 200 && !empty($res['success'])) {
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
                'message' => $res['message'] ?? 'Token Golike không hợp lệ hoặc đã hết hạn.'
            ]);
        }
        exit;
    }

    // 2. Lưu token Golike vào danh sách
    if ($action === 'save_token') {
        $token = trim($_POST['token'] ?? '');
        $result = save_or_update_golike_account($pdo, $userUuid, $token);
        echo json_encode($result);
        exit;
    }

    // 3. Làm mới số dư của tài khoản
    if ($action === 'refresh_account') {
        $recordId = (int)($_POST['record_id'] ?? 0);
        $result = refresh_golike_account($pdo, $userUuid, $recordId);
        echo json_encode($result);
        exit;
    }

    // 4. Xóa tài khoản Golike
    if ($action === 'delete_account') {
        $recordId = (int)($_POST['record_id'] ?? 0);
        ensure_golike_table($pdo);
        $stmt = $pdo->prepare("DELETE FROM `golike_tokens` WHERE `id` = ? AND `user_uuid` = ?");
        $stmt->execute([$recordId, $userUuid]);
        echo json_encode([
            'success' => true,
            'message' => 'Đã xóa tài khoản Golike khỏi danh sách.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => false,
        'message' => 'Yêu cầu không hợp lệ.'
    ]);
    exit;
}
