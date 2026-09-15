<?php
/**
 * ==========================================================
 * API XÁC THỰC VÀ TỰ ĐỘNG ĐĂNG NHẬP QUA JWT TỪ LOCALSTORAGE
 * File: api/verify-token.php
 * Website: ThanhQuyTech
 * ==========================================================
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/jwt.php';

// Chỉ nhận phương thức POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Phương thức không được hỗ trợ']);
    exit;
}

// Lấy Token từ Authorization Header hoặc Request Body
$token = '';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $token = $matches[1];
} else {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    $token = $data['token'] ?? ($_POST['token'] ?? '');
}

if (empty($token)) {
    echo json_encode(['success' => false, 'message' => 'Không tìm thấy JWT Token']);
    exit;
}

// Giải mã và kiểm tra tính hợp lệ của Token
$payload = jwt_decode($token);

if (!$payload || empty($payload['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'JWT Token không hợp lệ hoặc đã hết hạn']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, uid, uuid, name, username, email, balance, avatar, role, status, created_at 
        FROM users 
        WHERE id = ? 
        LIMIT 1
    ");
    $stmt->execute([$payload['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Người dùng không tồn tại']);
        exit;
    }

    if ($user['status'] !== 'Active') {
        echo json_encode(['success' => false, 'message' => 'Tài khoản đang bị khóa hoặc chưa kích hoạt']);
        exit;
    }

    // Tự động khôi phục Session đăng nhập
    session_regenerate_id(true);

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_uid']  = $user['uid'];
    $_SESSION['user_uuid'] = $user['uuid'];
    $_SESSION['user']      = [
        'id'         => $user['id'],
        'uid'        => $user['uid'],
        'uuid'       => $user['uuid'],
        'name'       => $user['name'],
        'username'   => $user['username'],
        'email'      => $user['email'],
        'balance'    => $user['balance'],
        'avatar'     => $user['avatar'],
        'role'       => $user['role'],
        'status'     => $user['status'],
        'created_at' => $user['created_at']
    ];

    echo json_encode([
        'success'  => true,
        'message'  => 'Xác thực JWT thành công. Đang chuyển hướng...',
        'redirect' => 'index.php'
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()]);
    exit;
}
