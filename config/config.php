<?php
/**
 * ==========================================================
 * CẤU HÌNH HỆ THỐNG, KẾT NỐI CƠ SỞ DỮ LIỆU & HÀM BẢO MẬT
 * File: config/config.php
 * Website: ThanhQuyTech
 * ==========================================================
 */

// Báo cáo lỗi
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Múi giờ Việt Nam
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Cấu hình CSDL MySQL
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'thanhquytech_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Thông tin website
define('APP_NAME', 'ThanhQuyTech');
define('APP_URL', 'http://localhost/Website-ThanhQuyTech');

// Khóa Pepper mật khẩu
define('PASSWORD_PEPPER', 'ThanhQuyTech@2026#SecureStretchingPepperKey!@$');

// Khởi tạo Session an toàn
if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = [
        'lifetime' => 86400 * 7,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    session_set_cookie_params($cookieParams);
    session_start();
}

// Kết nối PDO MySQL
try {
    $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=%s", DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    die("Lỗi kết nối cơ sở dữ liệu: " . htmlspecialchars($e->getMessage()));
}

// ==========================================================
// CÁC HÀM TIỆN ÍCH & BẢO MẬT (ĐỘC LẬP - KHÔNG CẦN INCLUDES)
// ==========================================================

/**
 * Sinh chuỗi UUIDv7 chuẩn RFC 9562 thuần PHP (Timestamp 48-bit + CSPRNG)
 */
function generate_uuidv7(): string {
    $timeMs = (int)(microtime(true) * 1000);
    $timeHex = str_pad(dechex($timeMs), 12, '0', STR_PAD_LEFT);

    $randomBytes = random_bytes(10);
    $randHex = bin2hex($randomBytes);

    $part1 = substr($timeHex, 0, 8);
    $part2 = substr($timeHex, 8, 4);
    $part3 = '7' . substr($randHex, 0, 3);
    $varByte = (hexdec(substr($randHex, 3, 2)) & 0x3f) | 0x80;
    $part4 = sprintf('%02x', $varByte) . substr($randHex, 5, 2);
    $part5 = substr($randHex, 7, 12);

    return sprintf('%s-%s-%s-%s-%s', $part1, $part2, $part3, $part4, $part5);
}

/**
 * Sinh mã định danh UID 7 số ngẫu nhiên duy nhất (1000000 - 9999999)
 */
function generate_unique_uid(PDO $pdo): int {
    $maxAttempts = 20;
    $attempts = 0;
    do {
        $uid = random_int(1000000, 9999999);
        $stmt = $pdo->prepare("SELECT id FROM users WHERE uid = ? LIMIT 1");
        $stmt->execute([$uid]);
        $exists = $stmt->fetch();
        $attempts++;
    } while ($exists && $attempts < $maxAttempts);

    if ($exists) {
        throw new Exception("Không thể tạo mã định danh UID duy nhất.");
    }
    return $uid;
}

/**
 * Băm mật khẩu an toàn: Hash + Salt + Stretching (BCrypt Cost 12) + Pepper
 */
function hash_password_stretched(string $password): string {
    $peppered = hash_hmac('sha256', $password, PASSWORD_PEPPER);
    return password_hash($peppered, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Kiểm tra mật khẩu băm (Hỗ trợ Stretched Hash + Pepper, BCrypt tiêu chuẩn và Seed)
 */
function verify_password_stretched(string $password, string $hash): bool {
    // 1. Kiểm tra chuẩn Stretching + Pepper
    $peppered = hash_hmac('sha256', $password, PASSWORD_PEPPER);
    if (password_verify($peppered, $hash)) {
        return true;
    }
    // 2. Kiểm tra BCrypt tiêu chuẩn
    if (password_verify($password, $hash)) {
        return true;
    }
    // 3. Kiểm tra mật khẩu khởi tạo từ Seed Data (sẽ được tự động nâng cấp hash sau khi đăng nhập)
    if ($hash === $password) {
        return true;
    }
    return false;
}

/**
 * Lấy CSRF Token
 */
function get_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Kiểm tra CSRF Token
 */
function verify_csrf_token(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Thiết lập Flash message
 */
function set_flash(string $type, string $message, string $title = ''): void {
    $_SESSION['flash'] = [
        'type'    => $type,
        'message' => $message,
        'title'   => $title
    ];
}

/**
 * Lấy Flash message
 */
function get_flash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Định dạng tiền tệ VND
 */
function format_currency($amount): string {
    return number_format((float)$amount, 0, ',', '.') . ' ₫';
}

/**
 * Kiểm tra trạng thái đăng nhập
 */
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['user_uuid']);
}

/**
 * Bắt buộc đăng nhập
 */
function require_login(): void {
    if (!is_logged_in()) {
        set_flash('warning', 'Vui lòng đăng nhập để tiếp tục.', 'Yêu Cầu Đăng Nhập');
        header("Location: login.php");
        exit;
    }
}

/**
 * Bắt buộc là khách
 */
function require_guest(): void {
    if (is_logged_in()) {
        header("Location: index.php");
        exit;
    }
}
