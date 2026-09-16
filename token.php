<?php
/**
 * ==========================================================
 * TRANG QUẢN LÝ ACCESS TOKEN & KHÓA API - THANHQUYTECH
 * File: token.php
 * Website: ThanhQuyTech
 * ==========================================================
 */

require_once __DIR__ . '/config/config.php';

require_login();

// 1. Lấy thông tin tài khoản người dùng hiện tại
$stmt = $pdo->prepare("
    SELECT id, uid, uuid, name, username, email, balance, avatar, role, status 
    FROM users 
    WHERE id = ? 
    LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$isAdmin = ($user['role'] === 'Admin');
$currentUser = $user;

// 2. Tự động kiểm tra và khởi tạo bảng `token` nếu chưa có
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `token` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_uuid` CHAR(36) NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            `token` VARCHAR(100) NOT NULL UNIQUE,
            `abilities` TEXT NOT NULL,
            `last_used_at` DATETIME DEFAULT NULL,
            `expires_at` DATETIME DEFAULT NULL,
            `status` ENUM('Active', 'Revoked') NOT NULL DEFAULT 'Active',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_token_user_uuid` (`user_uuid`),
            INDEX `idx_token_key` (`token`),
            INDEX `idx_token_status` (`status`),
            INDEX `idx_token_expires_at` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {
    // Bỏ qua nếu bảng đã tồn tại
}

// 3. XỬ LÝ CÁC HÀNH ĐỘNG POST (Tạo, Thu hồi, Xóa Token)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Phiên làm việc hoặc mã bảo mật CSRF không hợp lệ. Vui lòng tải lại trang.', 'Lỗi xác thực');
        header("Location: token.php");
        exit;
    }

    $action = $_POST['action'] ?? '';

    // A. TẠO ACCESS TOKEN MỚI
    if ($action === 'create_token') {
        $tokenName = trim($_POST['token_name'] ?? '');
        $expiryOption = $_POST['expiry'] ?? '30d';
        $abilities = $_POST['abilities'] ?? ['all'];

        if (empty($tokenName)) {
            set_flash('error', 'Vui lòng nhập tên định danh hoặc mục đích sử dụng cho Access Token.', 'Thiếu thông tin');
            header("Location: token.php");
            exit;
        }

        if (strlen($tokenName) > 100) {
            set_flash('error', 'Tên định danh không được vượt quá 100 ký tự.', 'Dữ liệu không hợp lệ');
            header("Location: token.php");
            exit;
        }

        // Tính ngày hết hạn
        $expiresAt = null;
        switch ($expiryOption) {
            case '7d':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
                break;
            case '30d':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
                break;
            case '90d':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));
                break;
            case '180d':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+180 days'));
                break;
            case '365d':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+365 days'));
                break;
            case 'never':
            default:
                $expiresAt = null;
                break;
        }

        // Định dạng abilities
        if (!is_array($abilities)) {
            $abilities = ['all'];
        }
        $abilitiesJson = json_encode(array_values(array_unique($abilities)), JSON_UNESCAPED_UNICODE);

        // Sinh mã Token ngẫu nhiên bảo mật cao với tiền tố tqt_live_
        $randomHex = bin2hex(random_bytes(24));
        $generatedToken = 'tqt_live_' . $randomHex;

        try {
            $stmtInsert = $pdo->prepare("
                INSERT INTO `token` (`user_uuid`, `name`, `token`, `abilities`, `expires_at`, `status`)
                VALUES (?, ?, ?, ?, ?, 'Active')
            ");
            $stmtInsert->execute([$user['uuid'], $tokenName, $generatedToken, $abilitiesJson, $expiresAt]);

            // Lưu token mới vào Session để hiển thị 1 lần cho người dùng copy
            $_SESSION['newly_created_token'] = [
                'name'  => $tokenName,
                'token' => $generatedToken
            ];

            set_flash('success', 'Đã khởi tạo Personal Access Token mới thành công!', 'Thành Công');
            header("Location: token.php");
            exit;
        } catch (Exception $e) {
            set_flash('error', 'Không thể tạo Access Token. Chi tiết: ' . $e->getMessage(), 'Lỗi hệ thống');
            header("Location: token.php");
            exit;
        }
    }

    // B. THU HỒI TOKEN (REVOKE)
    if ($action === 'revoke_token') {
        $tokenId = (int)($_POST['token_id'] ?? 0);
        try {
            $stmtRevoke = $pdo->prepare("
                UPDATE `token` 
                SET `status` = 'Revoked' 
                WHERE `id` = ? AND `user_uuid` = ?
            ");
            $stmtRevoke->execute([$tokenId, $user['uuid']]);
            set_flash('warning', 'Đã thu hồi quyền truy cập của Token. Token này sẽ không thể dùng để gọi API nữa.', 'Đã thu hồi');
        } catch (Exception $e) {
            set_flash('error', 'Lỗi khi thu hồi token: ' . $e->getMessage(), 'Lỗi');
        }
        header("Location: token.php");
        exit;
    }

    // C. KÍCH HOẠT LẠI TOKEN (ACTIVATE)
    if ($action === 'activate_token') {
        $tokenId = (int)($_POST['token_id'] ?? 0);
        try {
            $stmtActivate = $pdo->prepare("
                UPDATE `token` 
                SET `status` = 'Active' 
                WHERE `id` = ? AND `user_uuid` = ?
            ");
            $stmtActivate->execute([$tokenId, $user['uuid']]);
            set_flash('success', 'Đã kích hoạt lại Access Token thành công!', 'Đã kích hoạt');
        } catch (Exception $e) {
            set_flash('error', 'Lỗi khi kích hoạt token: ' . $e->getMessage(), 'Lỗi');
        }
        header("Location: token.php");
        exit;
    }

    // D. XÓA TOKEN VĨNH VIỄN
    if ($action === 'delete_token') {
        $tokenId = (int)($_POST['token_id'] ?? 0);
        try {
            $stmtDelete = $pdo->prepare("
                DELETE FROM `token` 
                WHERE `id` = ? AND `user_uuid` = ?
            ");
            $stmtDelete->execute([$tokenId, $user['uuid']]);
            set_flash('success', 'Đã xóa vĩnh viễn Access Token khỏi hệ thống.', 'Đã xóa');
        } catch (Exception $e) {
            set_flash('error', 'Lỗi khi xóa token: ' . $e->getMessage(), 'Lỗi');
        }
        header("Location: token.php");
        exit;
    }
}

// 4. LẤY DANH SÁCH TOKEN CỦA USER
$tokens = [];
try {
    $stmtTokens = $pdo->prepare("
        SELECT id, name, token, abilities, last_used_at, expires_at, status, created_at 
        FROM `token` 
        WHERE `user_uuid` = ? 
        ORDER BY id DESC
    ");
    $stmtTokens->execute([$user['uuid']]);
    $tokens = $stmtTokens->fetchAll();
} catch (Exception $e) {
    $tokens = [];
}

// 5. THỐNG KÊ TỔNG QUAN
$totalTokens = count($tokens);
$activeTokens = 0;
$revokedTokens = 0;
$lastUsedDate = 'Chưa dùng';

foreach ($tokens as $t) {
    if ($t['status'] === 'Active') {
        // Kiểm tra nếu hết hạn
        if (!empty($t['expires_at']) && strtotime($t['expires_at']) < time()) {
            $revokedTokens++;
        } else {
            $activeTokens++;
        }
    } else {
        $revokedTokens++;
    }

    if (!empty($t['last_used_at']) && $lastUsedDate === 'Chưa dùng') {
        $lastUsedDate = date('d/m/Y H:i', strtotime($t['last_used_at']));
    }
}

// Token mới tạo (nếu có)
$newlyCreatedToken = $_SESSION['newly_created_token'] ?? null;
unset($_SESSION['newly_created_token']);

$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Access Token & API Key - ThanhQuyTech</title>
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Fira+Code:wght@400;500;600&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --bg-body: #f8fafc;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-heading: #0f172a;
            --text-body: #334155;
            --text-muted: #64748b;
            --primary: #4f46e5;
            --accent: #06b6d4;
            --gradient-primary: linear-gradient(135deg, #4f46e5 0%, #6366f1 50%, #06b6d4 100%);
            --radius-md: 14px;
            --radius-lg: 20px;
            --shadow-card: 0 10px 30px -10px rgba(0, 0, 0, 0.06), 0 2px 8px rgba(0, 0, 0, 0.03);
            --transition: all 0.25s ease-in-out;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        html, body {
            width: 100%;
            max-width: 100% !important;
            overflow-x: hidden !important;
            position: relative;
            touch-action: pan-y;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif !important;
            background-color: var(--bg-body) !important;
            color: var(--text-body) !important;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        /* HEADER CỐ ĐỊNH */
        .app-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 70px;
            z-index: 1040;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--card-border);
            box-shadow: 0 4px 20px -8px rgba(15, 23, 42, 0.07);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
            flex-shrink: 1;
        }

        .sidebar-toggle-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: #ffffff;
            color: var(--text-heading);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            cursor: pointer;
            transition: var(--transition);
            flex-shrink: 0;
        }

        .sidebar-toggle-btn:hover {
            background: #f1f5f9;
            color: var(--primary);
            border-color: #cbd5e1;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            min-width: 0;
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: var(--gradient-primary);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
            flex-shrink: 0;
        }

        .brand-name {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--text-heading);
            letter-spacing: -0.3px;
            white-space: nowrap;
        }

        .brand-name span {
            color: var(--primary);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }

        /* Khối Số Dư Nạp Vào (Bên Phải Header - Click chuyển nạp tiền) */
        .header-balance-card {
            display: flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1px solid #bbf7d0;
            padding: 6px 14px;
            border-radius: 50px;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.08);
            transition: var(--transition);
            flex-shrink: 0;
            text-decoration: none;
            cursor: pointer;
        }

        .header-balance-card:hover {
            border-color: #86efac;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.2);
            transform: translateY(-1px);
        }

        .balance-wallet-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #10b981;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.88rem;
            flex-shrink: 0;
        }

        .balance-text-group {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .balance-title {
            font-size: 0.68rem;
            text-transform: uppercase;
            font-weight: 700;
            color: #15803d;
            letter-spacing: 0.4px;
        }

        .balance-val {
            font-size: 0.95rem;
            font-weight: 800;
            color: #14532d;
            white-space: nowrap;
        }

        /* Khối Avatar & Bảng Popup Hồ Sơ */
        .user-profile-container {
            position: relative;
            flex-shrink: 0;
        }

        .user-profile-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2px;
            background: #ffffff;
            border: 2px solid #e2e8f0;
            border-radius: 50%;
            cursor: pointer;
            transition: var(--transition);
            outline: none;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }

        .user-profile-toggle:hover,
        .user-profile-toggle:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.22);
            transform: scale(1.05);
        }

        .user-avatar-small {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            display: block;
            flex-shrink: 0;
            pointer-events: none;
            user-select: none;
            background: #eef2ff;
        }

        /* Bảng Popup Hồ Sơ */
        .user-profile-popup {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 290px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.22), 0 4px 15px rgba(0, 0, 0, 0.06);
            padding: 16px;
            z-index: 1060;
            display: none;
            animation: popupFadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes popupFadeIn {
            from {
                opacity: 0;
                transform: translateY(-8px) scale(0.98);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .user-profile-popup.active {
            display: block !important;
        }

        .popup-menu-item {
            display: flex;
            align-items: center;
            padding: 9px 12px;
            border-radius: 10px;
            color: #334155;
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            cursor: pointer;
        }

        .popup-menu-item:hover {
            background: #f1f5f9;
            color: #4f46e5;
            transform: translateX(3px);
        }

        .popup-menu-item.text-danger:hover {
            background: #fef2f2;
            color: #dc2626;
        }

        /* ==========================================================
         * 2. SIDEBAR MENU CỐ ĐỊNH TRÁI (CHUẨN 1:1 THEO BUY-KEY & INDEX)
         * ========================================================== */
        .app-sidebar {
            position: fixed;
            top: 70px;
            left: 0;
            bottom: 0;
            width: 260px;
            background: #ffffff;
            border-right: 1px solid var(--card-border);
            z-index: 1030;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 18px 12px 30px;
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.02);
        }

        .app-sidebar::-webkit-scrollbar {
            width: 5px;
        }
        .app-sidebar::-webkit-scrollbar-track {
            background: transparent;
        }
        .app-sidebar::-webkit-scrollbar-thumb {
            background: #e2e8f0;
            border-radius: 10px;
        }
        .app-sidebar::-webkit-scrollbar-thumb:hover {
            background: #cbd5e1;
        }

        .sidebar-category {
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #94a3b8;
            padding: 12px 14px 6px;
            margin-top: 4px;
        }

        .sidebar-nav-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 12px;
            color: var(--text-body);
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            border: 1px solid transparent;
            width: 100%;
            background: transparent;
            text-align: left;
            cursor: pointer;
        }

        .sidebar-link:hover {
            background: #f1f5f9;
            color: var(--primary);
        }

        .sidebar-link.active {
            background: #eef2ff;
            color: var(--primary);
            border-color: #c7d2fe;
            font-weight: 700;
        }

        .sidebar-link.active .sidebar-icon {
            color: var(--primary);
        }

        /* 1:1 Bounding Box & Đồng bộ khoảng cách, độ đậm nhạt Icon */
        .sidebar-icon {
            width: 24px;
            height: 24px;
            min-width: 24px;
            max-width: 24px;
            font-size: 1.05rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #64748b;
            flex-shrink: 0;
            line-height: 1;
            transition: var(--transition);
        }

        .sidebar-icon i {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            text-align: center;
        }

        /* Đồng bộ độ đậm (stroke) để icon nét mảnh (fingerprint, headset) có cùng tỷ trọng quang học với icon khối đặc */
        .sidebar-icon .fa-fingerprint {
            font-size: 1.15rem;
            stroke: currentColor;
            stroke-width: 22px;
        }

        .sidebar-icon .fa-headset {
            font-size: 1.1rem;
            stroke: currentColor;
            stroke-width: 18px;
        }

        .sidebar-icon .fa-gear {
            font-size: 1.05rem;
        }

        .sidebar-link:hover .sidebar-icon {
            color: var(--primary);
        }

        .sidebar-title {
            flex-grow: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-arrow {
            font-size: 0.72rem;
            color: #94a3b8;
            transition: transform 0.25s ease;
        }

        .sidebar-link:not(.collapsed) .sidebar-arrow {
            transform: rotate(180deg);
        }

        /* Submenu accordion */
        .sidebar-submenu {
            list-style: none;
            padding: 4px 0 6px 14px;
            margin: 4px 0 4px 16px;
            border-left: 2px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .submenu-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 8px;
            color: var(--text-muted);
            font-size: 0.84rem;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
        }

        .submenu-link:hover {
            color: var(--primary);
            background: #f8fafc;
            padding-left: 15px;
        }

        .submenu-link.active {
            color: var(--primary);
            background: #eef2ff;
            font-weight: 700;
        }

        /* Huy hiệu Lịch Sử */
        .badge-history {
            font-size: 0.65rem;
            font-weight: 700;
            background: #f8fafc;
            color: #64748b;
            border: 1px solid #e2e8f0;
            padding: 2px 7px;
            border-radius: 6px;
            letter-spacing: 0.2px;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            transition: var(--transition);
        }

        .submenu-link:hover .badge-history,
        .sidebar-link:hover .badge-history {
            background: #e0e7ff;
            color: #4338ca;
            border-color: #c7d2fe;
        }

        /* Backdrop cho mobile */
        .sidebar-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(4px);
            z-index: 1025;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
        }

        .sidebar-backdrop.active {
            opacity: 1;
            visibility: visible;
        }

        /* MAIN CONTENT */
        .app-main {
            margin-left: 260px;
            padding-top: 70px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
        }

        .content-container {
            padding: 28px;
            flex-grow: 1;
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
        }

        /* HERO CARD */
        .token-hero {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
            border-radius: var(--radius-lg);
            padding: 30px 36px;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 35px -10px rgba(30, 27, 75, 0.35);
            margin-bottom: 28px;
        }

        .token-hero::before {
            content: "";
            position: absolute;
            top: -50px;
            right: -50px;
            width: 250px;
            height: 250px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.35) 0%, transparent 70%);
            border-radius: 50%;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #a5b4fc;
            margin-bottom: 14px;
        }

        .hero-title {
            font-size: 1.85rem;
            font-weight: 800;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .hero-subtitle {
            font-size: 0.95rem;
            color: #cbd5e1;
            max-width: 720px;
            line-height: 1.6;
            margin-bottom: 0;
        }

        /* STATS CARDS */
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: var(--shadow-card);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: #cbd5e1;
            box-shadow: 0 12px 28px -10px rgba(0, 0, 0, 0.1);
        }

        .stat-icon-wrapper {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
        }

        .stat-icon-primary { background: #e0e7ff; color: #4338ca; }
        .stat-icon-success { background: #dcfce7; color: #15803d; }
        .stat-icon-warning { background: #fef3c7; color: #b45309; }
        .stat-icon-info    { background: #e0f2fe; color: #0369a1; }

        .stat-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-heading);
            line-height: 1.2;
        }

        /* NEW TOKEN CALLOUT BANNER */
        .new-token-banner {
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
            border: 2px solid #86efac;
            border-radius: var(--radius-md);
            padding: 22px;
            margin-bottom: 26px;
            box-shadow: 0 10px 25px -8px rgba(34, 197, 94, 0.2);
            animation: fadeInMenu 0.3s ease-in-out;
        }

        .token-copy-box {
            background: #ffffff;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-family: 'Fira Code', monospace;
            font-size: 0.95rem;
            font-weight: 600;
            color: #166534;
            word-break: break-all;
            margin-top: 10px;
        }

        /* CONTENT CARDS */
        .section-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-card);
            margin-bottom: 28px;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--card-border);
            flex-wrap: wrap;
            gap: 12px;
        }

        .section-title {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        /* TABLE */
        .token-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .token-table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 12px 16px;
            border-bottom: 1px solid var(--card-border);
        }

        .token-table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--card-border);
            vertical-align: middle;
            font-size: 0.9rem;
        }

        .token-table tr:last-child td {
            border-bottom: none;
        }

        .token-table tr:hover td {
            background: #f8fafc;
        }

        .token-code-inline {
            font-family: 'Fira Code', monospace;
            background: #f1f5f9;
            color: #0f172a;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            border: 1px solid #e2e8f0;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 10px;
            border-radius: 50px;
            font-size: 0.76rem;
            font-weight: 700;
        }

        .status-pill.active {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .status-pill.revoked {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #cbd5e1;
        }

        .status-pill.expired {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }

        .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor;
        }

        /* CODE BLOCK TABS */
        .code-snippet-box {
            background: #0f172a;
            border-radius: var(--radius-md);
            padding: 18px;
            color: #e2e8f0;
            font-family: 'Fira Code', monospace;
            font-size: 0.86rem;
            overflow-x: auto;
            position: relative;
        }

        .code-nav-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 6px 14px;
            border-radius: 6px;
            transition: var(--transition);
        }

        .code-nav-btn.active {
            background: #1e293b;
            color: #38bdf8;
        }

        /* BUTTONS */
        .btn-gradient-primary {
            background: var(--gradient-primary);
            color: #ffffff;
            font-weight: 700;
            border: none;
            padding: 9px 20px;
            border-radius: 10px;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.28);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-gradient-primary:hover {
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(79, 70, 229, 0.38);
        }

        .btn-copy {
            background: #ffffff;
            border: 1px solid var(--card-border);
            color: var(--text-body);
            border-radius: 8px;
            padding: 5px 12px;
            font-size: 0.82rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition);
            cursor: pointer;
        }

        .btn-copy:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: #eef2ff;
        }

        /* FOOTER */
        .app-footer {
            background: #ffffff;
            border-top: 1px solid var(--card-border);
            padding: 16px 28px;
            color: var(--text-muted);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Responsive Mobile, Tablet & Desktop Sidebar Collapse */
        @media (min-width: 992px) {
            body.sidebar-collapsed .app-sidebar {
                transform: translateX(-100%) !important;
            }
            body.sidebar-collapsed .app-main {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
        }

        /* Nút đóng Sidebar trên Mobile */
        .btn-close-sidebar {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: #f8fafc;
            color: var(--text-muted);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-close-sidebar:hover {
            background: #fee2e2;
            color: #ef4444;
            border-color: #fca5a5;
        }

        /* RESPONSIVE */
        @media (max-width: 991.98px) {
            .app-sidebar {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                bottom: 0 !important;
                height: 100vh !important;
                height: 100dvh !important;
                width: 280px !important;
                max-width: 85vw !important;
                transform: translateX(-100%) !important;
                transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1) !important;
                z-index: 1060 !important;
                padding: 16px 14px 30px !important;
                box-shadow: none;
            }
            .app-sidebar.sidebar-open {
                transform: translateX(0) !important;
                box-shadow: 4px 0 30px rgba(0, 0, 0, 0.25) !important;
                display: block !important;
                visibility: visible !important;
            }
            .sidebar-backdrop {
                z-index: 1055 !important;
            }
            .app-main {
                margin-left: 0 !important;
                margin-top: 56px !important;
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
                padding: 16px 12px 50px !important;
                overflow-x: hidden !important;
                box-sizing: border-box !important;
            }
            .content-container {
                padding: 16px 4px;
            }
            .token-hero {
                padding: 24px 20px;
            }
            .hero-title {
                font-size: 1.45rem;
            }
        }

        @media (max-width: 767.98px) {
            html, body {
                width: 100% !important;
                max-width: 100% !important;
                overflow-x: hidden !important;
                touch-action: pan-y !important;
            }
            .app-main {
                margin-left: 0 !important;
                width: 100% !important;
                margin-top: 56px !important;
                padding: 16px 12px 50px !important;
            }
            .app-header {
                height: 56px !important;
                padding: 0 8px !important;
            }
            .brand-name {
                font-size: 0.86rem !important;
            }
            .brand-tech-suffix {
                display: none !important;
            }
            .user-avatar-small {
                width: 28px !important;
                height: 28px !important;
            }
            .user-profile-popup {
                position: fixed !important;
                top: 64px !important;
                right: 12px !important;
                left: 12px !important;
                width: auto !important;
                max-width: 360px !important;
                margin: 0 auto !important;
                z-index: 1060 !important;
            }
        }

        @media (max-width: 420px) {
            .app-header {
                padding: 0 6px !important;
            }
            .header-left {
                gap: 8px !important;
            }
            .header-right {
                gap: 6px !important;
            }
            .sidebar-toggle-btn {
                width: 36px !important;
                height: 36px !important;
            }
            .brand-icon {
                width: 32px !important;
                height: 32px !important;
                font-size: 1rem !important;
            }
            .header-balance-card {
                padding: 5px 10px !important;
                gap: 8px !important;
            }
            .balance-wallet-icon {
                width: 28px !important;
                height: 28px !important;
                font-size: 0.8rem !important;
            }
            .balance-val {
                font-size: 0.85rem !important;
            }
        }
    </style>
</head>
<body>

    <!-- ==========================================================
     * 1. THANH ĐIỀU HƯỚNG CỐ ĐỊNH TRÊN ĐẦU (HEADER)
     * ========================================================== -->
    <header class="app-header">
        <div class="header-left">
            <button type="button" class="sidebar-toggle-btn" id="sidebarToggle" onclick="toggleAppSidebar(event)" title="Đóng/Mở Menu">
                <i class="fa-solid fa-bars"></i>
            </button>
            <a href="index.php" class="brand-logo">
                <div class="brand-icon">
                    <i class="fa-solid fa-bolt"></i>
                </div>
                <div class="brand-name">
                    ThanhQuy<span>Tech</span>
                </div>
            </a>
        </div>

        <div class="header-right">
            <!-- Số dư tài khoản: Bấm vào khung để chuyển qua nạp tiền -->
            <a href="/payments/deposit" class="header-balance-card" title="Nạp tiền vào tài khoản">
                <div class="balance-wallet-icon">
                    <i class="fa-solid fa-wallet"></i>
                </div>
                <div class="balance-text-group">
                    <span class="balance-title">Số dư</span>
                    <span class="balance-val"><?= format_currency($currentUser['balance']) ?></span>
                </div>
            </a>

            <!-- Ảnh avatar hồ sơ & Bảng Popup Hồ Sơ -->
            <div class="user-profile-container" id="userDropdownContainer">
                <button type="button" class="user-profile-toggle" id="userProfileToggle" onclick="toggleUserPopup(event)" aria-expanded="false" title="<?= htmlspecialchars($currentUser['name']) ?>">
                    <img src="<?= htmlspecialchars($currentUser['avatar']) ?>" 
                         alt="Avatar" 
                         class="user-avatar-small"
                         onerror="this.onerror=null; this.src='assets/images/default-avatar.svg';">
                </button>

                <!-- Bảng Popup Thông Tin & Chức Năng Hồ Sơ -->
                <div class="user-profile-popup" id="userProfilePopup">
                    <!-- Thông tin người dùng -->
                    <div class="d-flex align-items-center gap-3 pb-3 border-bottom mb-3">
                        <img src="<?= htmlspecialchars($currentUser['avatar']) ?>" 
                             alt="Avatar" 
                             style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid #e0e7ff; background: #eef2ff;"
                             onerror="this.onerror=null; this.src='assets/images/default-avatar.svg';">
                        <div style="min-width: 0; flex-grow: 1;">
                            <div class="fw-bold text-dark text-truncate" style="font-size: 0.95rem;"><?= htmlspecialchars($currentUser['name']) ?></div>
                            <div class="text-muted small text-truncate"><?= htmlspecialchars($currentUser['username']) ?></div>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <span class="badge font-monospace text-primary bg-primary-subtle px-2 py-0" style="font-size: 0.7rem;">UID: #<?= htmlspecialchars($currentUser['uid']) ?></span>
                                <?php if ($isAdmin): ?>
                                    <span class="badge bg-danger text-white px-2 py-0" style="font-size: 0.68rem;">Admin</span>
                                <?php else: ?>
                                    <span class="badge bg-info-subtle text-info px-2 py-0" style="font-size: 0.68rem;">Thành viên</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Khung xem số dư và nạp tiền nhanh trong popup -->
                    <div class="p-2 px-3 rounded-3 mb-3 d-flex justify-content-between align-items-center" style="background: #f0fdf4; border: 1px solid #bbf7d0;">
                        <div>
                            <div class="text-muted" style="font-size: 0.68rem; font-weight: 700; text-transform: uppercase;">Số dư khả dụng</div>
                            <div class="fw-bold" style="color: #15803d; font-size: 0.95rem;"><?= format_currency($currentUser['balance']) ?></div>
                        </div>
                        <a href="/payments/deposit" class="btn btn-sm btn-success rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;">
                            <i class="fa-solid fa-circle-arrow-down me-1"></i> Nạp tiền
                        </a>
                    </div>

                    <!-- Các mục điều hướng -->
                    <div class="d-flex flex-column gap-1">
                        <a href="profile.php" class="popup-menu-item">
                            <i class="fa-solid fa-id-card text-primary me-2"></i> Hồ sơ cá nhân
                        </a>
                        <a href="index.php" class="popup-menu-item">
                            <i class="fa-solid fa-gauge-high text-info me-2"></i> Bảng tổng quan
                        </a>
                        <a href="buy-key.php" class="popup-menu-item">
                            <i class="fa-solid fa-key text-warning me-2"></i> Mua key bản quyền
                        </a>
                        <a href="cloud.php" class="popup-menu-item">
                            <i class="fa-solid fa-cloud text-info me-2"></i> Thuê cloud
                        </a>
                        <a href="token.php" class="popup-menu-item text-primary fw-bold">
                            <i class="fa-solid fa-fingerprint text-primary me-2"></i> Access Token
                        </a>
                        <a href="settings.php" class="popup-menu-item">
                            <i class="fa-solid fa-gear text-secondary me-2"></i> Cấu hình
                        </a>
                        <?php if ($isAdmin): ?>
                        <a href="/admin/dashboard" class="popup-menu-item text-danger fw-bold">
                            <i class="fa-solid fa-shield-halved text-danger me-2"></i> Quản trị Admin
                        </a>
                        <?php endif; ?>
                        <hr class="my-2 border-secondary-subtle">
                        <button type="button" class="popup-menu-item text-danger text-start border-0 bg-transparent w-100" onclick="closeUserPopup(); confirmLogout();">
                            <i class="fa-solid fa-right-from-bracket me-2"></i> Đăng xuất
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- ==========================================================
     * 2. MENU SIDEBAR CỐ ĐỊNH TRÁI
     * ========================================================== -->
    <aside class="app-sidebar" id="appSidebar">
        <!-- Header cho Sidebar trên Mobile (Loại bỏ hoàn toàn khoảng hở trên đầu) -->
        <div class="sidebar-mobile-header d-flex d-lg-none align-items-center justify-content-between pb-3 mb-2 border-bottom">
            <a href="index.php" class="brand-logo">
                <div class="brand-icon">
                    <i class="fa-solid fa-bolt"></i>
                </div>
                <div class="brand-name">
                    ThanhQuy<span>Tech</span>
                </div>
            </a>
            <button type="button" class="btn-close-sidebar" onclick="closeAppSidebar()" title="Đóng menu">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="sidebar-category">BẢNG ĐIỀU KHIỂN</div>
        <ul class="sidebar-nav-list">
            <li>
                <a href="index.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-house"></i></span>
                    <span class="sidebar-title">Trang chủ</span>
                </a>
            </li>
            <li>
                <a href="buy-key.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-key"></i></span>
                    <span class="sidebar-title">Mua key</span>
                </a>
            </li>
            <li>
                <a href="cloud.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-cloud"></i></span>
                    <span class="sidebar-title">Thuê cloud</span>
                </a>
            </li>
            <!-- Access Token (Active) -->
            <li>
                <a href="token.php" class="sidebar-link active">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-fingerprint"></i></span>
                    <span class="sidebar-title">Access Token</span>
                </a>
            </li>
            <!-- Cấu hình -->
            <li>
                <a href="settings.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-gear"></i></span>
                    <span class="sidebar-title">Cấu hình</span>
                </a>
            </li>
        </ul>

        <div class="sidebar-category">CÔNG CỤ & DỊCH VỤ</div>
        <ul class="sidebar-nav-list">
            <!-- Tool Golike -->
            <li>
                <button class="sidebar-link collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#submenuGolike" aria-expanded="false">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-robot"></i></span>
                    <span class="sidebar-title">Tool Golike</span>
                    <i class="fa-solid fa-chevron-down sidebar-arrow"></i>
                </button>
                <div class="collapse" id="submenuGolike">
                    <ul class="sidebar-submenu">
                        <li>
                            <a href="/jobs/golike" class="submenu-link fw-bold text-dark">
                                <span><i class="fa-solid fa-arrow-right-to-bracket me-1 text-primary"></i> Tổng quan</span>
                            </a>
                        </li>
                        <li>
                            <a href="/jobs/golike/instagram" class="submenu-link">
                                <span><i class="fa-brands fa-instagram me-1 text-danger"></i> Instagram</span>
                                <span class="badge-history"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                            </a>
                        </li>
                        <li>
                            <a href="/jobs/golike/threads" class="submenu-link">
                                <span><i class="fa-brands fa-threads me-1 text-dark"></i> Threads</span>
                                <span class="badge-history"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                            </a>
                        </li>
                        <li>
                            <a href="/jobs/golike/pinterest" class="submenu-link">
                                <span><i class="fa-brands fa-pinterest me-1 text-danger"></i> Pinterest</span>
                                <span class="badge-history"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- Account -->
            <li>
                <button class="sidebar-link collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#submenuAccount" aria-expanded="false">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-users-gear"></i></span>
                    <span class="sidebar-title">Account</span>
                    <i class="fa-solid fa-chevron-down sidebar-arrow"></i>
                </button>
                <div class="collapse" id="submenuAccount">
                    <ul class="sidebar-submenu">
                        <li>
                            <a href="/products/accounts" class="submenu-link fw-bold text-dark">
                                <span><i class="fa-solid fa-layer-group me-1 text-primary"></i> Kho tài khoản</span>
                            </a>
                        </li>
                        <li>
                            <a href="/products/accounts/instagram" class="submenu-link">
                                <span><i class="fa-brands fa-instagram me-1 text-danger"></i> Instagram</span>
                                <span class="badge-history"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                            </a>
                        </li>
                        <li>
                            <a href="/products/accounts/threads" class="submenu-link">
                                <span><i class="fa-brands fa-threads me-1 text-dark"></i> Threads</span>
                                <span class="badge-history"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                            </a>
                        </li>
                        <li>
                            <a href="/products/accounts/pinterest" class="submenu-link">
                                <span><i class="fa-brands fa-pinterest me-1 text-danger"></i> Pinterest</span>
                                <span class="badge-history"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- Payment -->
            <li>
                <button class="sidebar-link collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#submenuPayment" aria-expanded="false">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-credit-card"></i></span>
                    <span class="sidebar-title">Payment</span>
                    <i class="fa-solid fa-chevron-down sidebar-arrow"></i>
                </button>
                <div class="collapse" id="submenuPayment">
                    <ul class="sidebar-submenu">
                        <li>
                            <a href="/payments" class="submenu-link fw-bold text-dark">
                                <span><i class="fa-solid fa-wallet me-1 text-success"></i> Thanh toán</span>
                            </a>
                        </li>
                        <li>
                            <a href="/payments/deposit" class="submenu-link">
                                <span><i class="fa-solid fa-circle-arrow-down me-1 text-success"></i> Nạp tiền</span>
                                <span class="badge-history"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                            </a>
                        </li>
                        <li>
                            <a href="/payments/withdraw" class="submenu-link">
                                <span><i class="fa-solid fa-circle-arrow-up me-1 text-warning"></i> Rút tiền</span>
                                <span class="badge-history"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
        </ul>

        <div class="sidebar-category">TIỆN ÍCH & HỆ THỐNG</div>
        <ul class="sidebar-nav-list">
            <!-- Giới thiệu -->
            <li>
                <a href="referral.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-share-nodes"></i></span>
                    <span class="sidebar-title">Giới thiệu</span>
                    <span class="badge-history ms-auto"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                </a>
            </li>
            <!-- Hỗ trợ -->
            <li>
                <a href="support.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-headset"></i></span>
                    <span class="sidebar-title">Hỗ trợ</span>
                    <span class="badge-history ms-auto"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                </a>
            </li>
            <?php if ($isAdmin): ?>
            <!-- Admin Panel -->
            <li>
                <a href="/admin/dashboard" class="sidebar-link text-danger fw-bold">
                    <span class="sidebar-icon text-danger"><i class="fa-solid fa-fw fa-shield-halved"></i></span>
                    <span class="sidebar-title">Admin Panel</span>
                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 ms-auto" style="font-size: 0.65rem; padding: 2px 7px;">Admin</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </aside>

    <!-- Nền mờ khi mở menu trên mobile -->
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeAppSidebar()"></div>

    <!-- ==========================================================
     * 3. NỘI DUNG CHÍNH (APP MAIN)
     * ========================================================== -->
    <main class="app-main">
        <div class="content-container">

            <!-- BREADCRUMB -->
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb mb-0" style="font-size: 0.85rem; font-weight: 600;">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted"><i class="fa-solid fa-house me-1"></i> Trang chủ</a></li>
                    <li class="breadcrumb-item active text-primary" aria-current="page">Access Token</li>
                </ol>
            </nav>

            <!-- HERO CARD -->
            <div class="token-hero">
                <div class="hero-badge">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span>CỔNG KẾT NỐI API & DEVELOPER</span>
                </div>
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                    <div>
                        <h1 class="hero-title">Quản Lý Personal Access Token</h1>
                        <p class="hero-subtitle">
                            Khởi tạo và quản lý mã truy cập API để tích hợp các Tool Golike, máy chủ Cloud VPS, script tự động hóa bot với hệ thống ThanhQuyTech một cách an toàn và bảo mật.
                        </p>
                    </div>
                    <div class="flex-shrink-0">
                        <button type="button" class="btn btn-light fw-bold px-4 py-2 rounded-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#createTokenModal">
                            <i class="fa-solid fa-plus-circle text-primary me-2"></i> Tạo Access Token Mới
                        </button>
                    </div>
                </div>
            </div>

            <!-- FLASH NOTIFICATIONS -->
            <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> alert-dismissible fade show rounded-3 shadow-sm border-0 mb-4" role="alert">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-circle-check fs-5"></i>
                    <div>
                        <strong><?= htmlspecialchars($flash['title']) ?>:</strong> <?= htmlspecialchars($flash['message']) ?>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <!-- BẢNG THÔNG BÁO TOKEN VỪA TẠO (CHỈ HIỂN THỊ 1 LẦN) -->
            <?php if ($newlyCreatedToken): ?>
            <div class="new-token-banner">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-success text-white px-3 py-1 rounded-pill fw-bold">
                            <i class="fa-solid fa-sparkles me-1"></i> TOKEN VỪA KHỞI TẠO THÀNH CÔNG
                        </span>
                        <strong class="text-dark"><?= htmlspecialchars($newlyCreatedToken['name']) ?></strong>
                    </div>
                    <span class="text-danger small fw-bold">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i> Hãy lưu lại mã Token ngay. Mã sẽ không hiển thị lại toàn bộ!
                    </span>
                </div>
                <div class="token-copy-box">
                    <span id="rawCreatedToken" class="user-select-all"><?= htmlspecialchars($newlyCreatedToken['token']) ?></span>
                    <button type="button" class="btn-copy ms-2" onclick="copyText('<?= htmlspecialchars($newlyCreatedToken['token']) ?>', this)">
                        <i class="fa-solid fa-copy"></i>
                        <span>Sao chép</span>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <!-- STATS CARDS -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon-wrapper stat-icon-primary">
                            <i class="fa-solid fa-key"></i>
                        </div>
                        <div>
                            <div class="stat-label">Tổng số Token</div>
                            <div class="stat-value"><?= number_format($totalTokens) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon-wrapper stat-icon-success">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div>
                            <div class="stat-label">Đang hoạt động</div>
                            <div class="stat-value text-success"><?= number_format($activeTokens) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon-wrapper stat-icon-warning">
                            <i class="fa-solid fa-ban"></i>
                        </div>
                        <div>
                            <div class="stat-label">Đã thu hồi / Hết hạn</div>
                            <div class="stat-value text-muted"><?= number_format($revokedTokens) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon-wrapper stat-icon-info">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                        </div>
                        <div>
                            <div class="stat-label">Dùng gần nhất</div>
                            <div class="stat-value" style="font-size: 1.05rem;"><?= $lastUsedDate ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- DANH SÁCH TOKEN -->
            <div class="section-card">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fa-solid fa-fingerprint text-primary"></i>
                        <span>Danh Sách Access Token Của Bạn</span>
                    </h2>
                    <div>
                        <button type="button" class="btn-gradient-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createTokenModal">
                            <i class="fa-solid fa-plus"></i> Tạo Token
                        </button>
                    </div>
                </div>

                <?php if (empty($tokens)): ?>
                <div class="text-center py-5">
                    <div class="mb-3 text-muted" style="font-size: 3rem;">
                        <i class="fa-solid fa-key-skeleton"></i>
                    </div>
                    <h5 class="fw-bold text-dark">Bạn chưa có Personal Access Token nào</h5>
                    <p class="text-muted small mx-auto" style="max-width: 480px;">
                        Tạo mã Token đầu tiên để kết nối bot, tool tự động Golike, máy chủ Cloud VPS hoặc kiểm thử API qua các thư viện lập trình.
                    </p>
                    <button type="button" class="btn-gradient-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#createTokenModal">
                        <i class="fa-solid fa-plus"></i> Tạo Ngay Bây Giờ
                    </button>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="token-table">
                        <thead>
                            <tr>
                                <th>Tên Gợi Nhớ / Mục Đích</th>
                                <th>Mã Token (Ẩn một phần)</th>
                                <th>Quyền Hạn</th>
                                <th>Trạng Thái</th>
                                <th>Thời Hạn</th>
                                <th>Dùng Gần Nhất</th>
                                <th class="text-end">Thao Tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tokens as $tokenItem): 
                                $isExpired = (!empty($tokenItem['expires_at']) && strtotime($tokenItem['expires_at']) < time());
                                $isRevoked = ($tokenItem['status'] === 'Revoked');
                                $abilitiesArr = json_decode($tokenItem['abilities'], true) ?: [$tokenItem['abilities']];
                                
                                // Tạo chuỗi hiển thị rút gọn (ví dụ: tqt_live_9a7d••••••••4d3e)
                                $rawKey = $tokenItem['token'];
                                if (strlen($rawKey) > 18) {
                                    $maskedKey = substr($rawKey, 0, 13) . '••••••••' . substr($rawKey, -4);
                                } else {
                                    $maskedKey = $rawKey;
                                }
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($tokenItem['name']) ?></div>
                                    <span class="text-muted small">Tạo ngày <?= date('d/m/Y', strtotime($tokenItem['created_at'])) ?></span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="token-code-inline"><?= htmlspecialchars($maskedKey) ?></span>
                                        <button type="button" class="btn-copy" onclick="copyText('<?= htmlspecialchars($tokenItem['token']) ?>', this)" title="Sao chép toàn bộ chuỗi Token">
                                            <i class="fa-solid fa-copy"></i>
                                        </button>
                                    </div>
                                </td>
                                <td>
                                    <?php foreach ($abilitiesArr as $ability): ?>
                                        <?php if ($ability === 'all'): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Toàn quyền (All)</span>
                                        <?php elseif ($ability === 'jobs'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">Chạy Jobs</span>
                                        <?php elseif ($ability === 'read'): ?>
                                            <span class="badge bg-secondary-subtle text-secondary border">Chỉ đọc (Read)</span>
                                        <?php else: ?>
                                            <span class="badge bg-info-subtle text-info border border-info-subtle"><?= htmlspecialchars($ability) ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php if ($isRevoked): ?>
                                        <span class="status-pill revoked">
                                            <span class="status-dot"></span> Đã thu hồi
                                        </span>
                                    <?php elseif ($isExpired): ?>
                                        <span class="status-pill expired">
                                            <span class="status-dot"></span> Đã hết hạn
                                        </span>
                                    <?php else: ?>
                                        <span class="status-pill active">
                                            <span class="status-dot"></span> Hoạt động
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($tokenItem['expires_at'])): ?>
                                        <span class="badge bg-light text-dark border">Vĩnh viễn</span>
                                    <?php else: ?>
                                        <span class="<?= $isExpired ? 'text-danger fw-bold' : 'text-body' ?>">
                                            <?= date('d/m/Y H:i', strtotime($tokenItem['expires_at'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($tokenItem['last_used_at'])): ?>
                                        <span class="text-muted small">Chưa dùng</span>
                                    <?php else: ?>
                                        <span class="small text-body"><?= date('d/m/Y H:i', strtotime($tokenItem['last_used_at'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-light btn-sm border" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="fa-solid fa-ellipsis-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                            <li>
                                                <button class="dropdown-item text-primary" type="button" onclick="testToken('<?= htmlspecialchars($tokenItem['token']) ?>')">
                                                    <i class="fa-solid fa-bolt me-2"></i> Kiểm thử Token
                                                </button>
                                            </li>
                                            <?php if (!$isRevoked): ?>
                                            <li>
                                                <form action="token.php" method="POST" onsubmit="return confirmRevoke(this);">
                                                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                                                    <input type="hidden" name="action" value="revoke_token">
                                                    <input type="hidden" name="token_id" value="<?= $tokenItem['id'] ?>">
                                                    <button type="submit" class="dropdown-item text-warning">
                                                        <i class="fa-solid fa-ban me-2"></i> Thu hồi (Revoke)
                                                    </button>
                                                </form>
                                            </li>
                                            <?php else: ?>
                                            <li>
                                                <form action="token.php" method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                                                    <input type="hidden" name="action" value="activate_token">
                                                    <input type="hidden" name="token_id" value="<?= $tokenItem['id'] ?>">
                                                    <button type="submit" class="dropdown-item text-success">
                                                        <i class="fa-solid fa-rotate-right me-2"></i> Kích hoạt lại
                                                    </button>
                                                </form>
                                            </li>
                                            <?php endif; ?>
                                            <li><hr class="dropdown-divider my-1"></li>
                                            <li>
                                                <form action="token.php" method="POST" onsubmit="return confirmDelete(this);">
                                                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                                                    <input type="hidden" name="action" value="delete_token">
                                                    <input type="hidden" name="token_id" value="<?= $tokenItem['id'] ?>">
                                                    <button type="submit" class="dropdown-item text-danger">
                                                        <i class="fa-solid fa-trash-can me-2"></i> Xóa vĩnh viễn
                                                    </button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- LIVE TOKEN TESTER (SANDBOX) -->
            <div class="section-card">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fa-solid fa-vial text-info"></i>
                        <span>Kiểm Thử Token Trực Tiếp (Live API Tester)</span>
                    </h2>
                    <span class="badge bg-info-subtle text-info border border-info-subtle px-3 py-1">API Endpoint: /api/verify-token.php</span>
                </div>
                <div class="row g-3">
                    <div class="col-lg-7">
                        <label class="form-label fw-bold text-dark">Nhập hoặc dán mã Token cần kiểm tra:</label>
                        <div class="input-group mb-3">
                            <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-key"></i></span>
                            <input type="text" id="testTokenInput" class="form-control font-monospace" placeholder="Dán mã tqt_live_... vào đây" value="<?= !empty($tokens[0]['token']) ? htmlspecialchars($tokens[0]['token']) : '' ?>">
                            <button type="button" class="btn btn-primary fw-bold" onclick="runTokenTest()">
                                <i class="fa-solid fa-paper-plane me-1"></i> Gửi Xác Thực
                            </button>
                        </div>
                        <div class="small text-muted">
                            <i class="fa-solid fa-circle-info text-primary me-1"></i> Hệ thống sẽ gửi yêu cầu HTTP POST có tiêu đề <code>Authorization: Bearer [token]</code> tới API để đối soát tính hợp lệ và trả về dữ liệu thời gian thực.
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <label class="form-label fw-bold text-dark">Kết quả phản hồi từ máy chủ (JSON Response):</label>
                        <pre id="testResultBox" class="bg-dark text-light p-3 rounded-3 small font-monospace mb-0" style="min-height: 110px; max-height: 220px; overflow-y: auto;">Nhấn nút "Gửi Xác Thực" để xem kết quả...</pre>
                    </div>
                </div>
            </div>

            <!-- DEVELOPER QUICKSTART GUIDE -->
            <div class="section-card">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fa-solid fa-code text-success"></i>
                        <span>Hướng Dẫn Sử Dụng API Cho Lập Trình Viên</span>
                    </h2>
                    <div class="d-flex gap-2">
                        <button type="button" class="code-nav-btn active" onclick="switchSnippet('curl', this)">cURL</button>
                        <button type="button" class="code-nav-btn" onclick="switchSnippet('python', this)">Python</button>
                        <button type="button" class="code-nav-btn" onclick="switchSnippet('node', this)">Node.js</button>
                        <button type="button" class="code-nav-btn" onclick="switchSnippet('php', this)">PHP</button>
                    </div>
                </div>

                <!-- cURL -->
                <div id="snippet-curl" class="code-snippet-box">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-secondary small"># Gọi API bằng cURL Terminal:</span>
                        <button class="btn-copy btn-sm text-white bg-dark border-secondary" onclick="copySnippet('codeCurlText')"><i class="fa-solid fa-copy"></i> Sao chép</button>
                    </div>
                    <code id="codeCurlText">curl -X POST "<?= APP_URL ?>/api/verify-token.php" \
  -H "Authorization: Bearer tqt_live_your_token_here" \
  -H "Content-Type: application/json"</code>
                </div>

                <!-- Python -->
                <div id="snippet-python" class="code-snippet-box d-none">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-secondary small"># Gọi API bằng Python (requests):</span>
                        <button class="btn-copy btn-sm text-white bg-dark border-secondary" onclick="copySnippet('codePythonText')"><i class="fa-solid fa-copy"></i> Sao chép</button>
                    </div>
                    <code id="codePythonText">import requests

url = "<?= APP_URL ?>/api/verify-token.php"
headers = {
    "Authorization": "Bearer tqt_live_your_token_here",
    "Content-Type": "application/json"
}

response = requests.post(url, headers=headers)
data = response.json()
print("Kết quả:", data)</code>
                </div>

                <!-- Node.js -->
                <div id="snippet-node" class="code-snippet-box d-none">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-secondary small"># Gọi API bằng Node.js (fetch):</span>
                        <button class="btn-copy btn-sm text-white bg-dark border-secondary" onclick="copySnippet('codeNodeText')"><i class="fa-solid fa-copy"></i> Sao chép</button>
                    </div>
                    <code id="codeNodeText">const token = 'tqt_live_your_token_here';

fetch('<?= APP_URL ?>/api/verify-token.php', {
    method: 'POST',
    headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json'
    }
})
.then(res => res.json())
.then(data => console.log('API Response:', data))
.catch(err => console.error(err));</code>
                </div>

                <!-- PHP -->
                <div id="snippet-php" class="code-snippet-box d-none">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-secondary small"># Gọi API bằng PHP (cURL):</span>
                        <button class="btn-copy btn-sm text-white bg-dark border-secondary" onclick="copySnippet('codePhpText')"><i class="fa-solid fa-copy"></i> Sao chép</button>
                    </div>
                    <code id="codePhpText">&lt;?php
$token = 'tqt_live_your_token_here';
$ch = curl_init('<?= APP_URL ?>/api/verify-token.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]
]);
$response = curl_exec($ch);
curl_close($ch);
$result = json_decode($response, true);
print_r($result);
?&gt;</code>
                </div>
            </div>

        </div>

        <!-- FOOTER -->
        <footer class="app-footer">
            <div>
                © 2026 <strong>ThanhQuyTech</strong>. Bản quyền thuộc về hệ sinh thái Tool & Cloud.
            </div>
            <div class="d-flex gap-3">
                <a href="support.php" class="text-decoration-none text-muted">Hỗ trợ 24/7</a>
                <a href="settings.php" class="text-decoration-none text-muted">Cấu hình</a>
            </div>
        </footer>
    </main>

    <!-- ==========================================================
     * 4. MODAL TẠO ACCESS TOKEN MỚI
     * ========================================================== -->
    <div class="modal fade" id="createTokenModal" tabindex="-1" aria-labelledby="createTokenModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: var(--radius-lg);">
                <form action="token.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                    <input type="hidden" name="action" value="create_token">

                    <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                        <h5 class="modal-title fw-bold text-dark" id="createTokenModalLabel">
                            <i class="fa-solid fa-plus-circle text-primary me-2"></i> Khởi Tạo Personal Access Token
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label for="token_name" class="form-label fw-bold text-dark">Tên gợi nhớ / Mục đích sử dụng <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="token_name" name="token_name" required placeholder="VD: Tool Golike VPS Cần Thơ, Python Worker Bot..." maxlength="100">
                            <div class="form-text text-muted">Đặt tên giúp bạn dễ phân biệt khi tích hợp nhiều máy chủ hoặc công cụ.</div>
                        </div>

                        <div class="mb-3">
                            <label for="expiry" class="form-label fw-bold text-dark">Thời hạn hiệu lực của Token</label>
                            <select class="form-select" id="expiry" name="expiry">
                                <option value="7d">7 ngày</option>
                                <option value="30d" selected>30 ngày (Khuyên dùng)</option>
                                <option value="90d">90 ngày (3 tháng)</option>
                                <option value="180d">180 ngày (6 tháng)</option>
                                <option value="365d">1 năm (365 ngày)</option>
                                <option value="never">Không giới hạn (Vĩnh viễn)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Phạm vi quyền hạn (Scopes / Abilities)</label>
                            <div class="border rounded-3 p-3 bg-light d-flex flex-column gap-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="abilities[]" value="all" id="scopeAll" checked onchange="toggleAllScopes(this)">
                                    <label class="form-check-label fw-bold text-dark" for="scopeAll">
                                        Toàn quyền (Full Access - Khuyên dùng)
                                    </label>
                                    <div class="small text-muted">Cho phép thực hiện tất cả tác vụ API gồm kiếm tiền, xem số dư và quản lý máy chủ.</div>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input scope-child" type="checkbox" name="abilities[]" value="jobs" id="scopeJobs" disabled>
                                    <label class="form-check-label text-dark" for="scopeJobs">
                                        Chạy tác vụ kiếm tiền (Jobs: Golike, Threads, Instagram)
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input scope-child" type="checkbox" name="abilities[]" value="cloud_keys" id="scopeKeys" disabled>
                                    <label class="form-check-label text-dark" for="scopeKeys">
                                        Đọc thông tin Key & Cloud Server
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input scope-child" type="checkbox" name="abilities[]" value="read" id="scopeRead" disabled>
                                    <label class="form-check-label text-dark" for="scopeRead">
                                        Chỉ đọc dữ liệu (Read Only: số dư, thứ hạng)
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-warning py-2 px-3 small mb-0 rounded-3">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i> Sau khi tạo, mã Token sẽ chỉ được hiển thị đầy đủ một lần duy nhất để bạn sao chép.
                        </div>
                    </div>

                    <div class="modal-footer border-top-0 pt-0 pb-4 px-4">
                        <button type="button" class="btn btn-light fw-bold px-3 py-2 rounded-3" data-bs-dismiss="modal">Hủy bỏ</button>
                        <button type="submit" class="btn-gradient-primary px-4 py-2">
                            <i class="fa-solid fa-shield-halved"></i> Xác Nhận Tạo Token
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // 1. Điều khiển Sidebar Mobile & Desktop Collapse
        function toggleAppSidebar(e) {
            if (e) e.stopPropagation();
            const sidebar = document.getElementById('appSidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            if (window.innerWidth >= 992) {
                document.body.classList.toggle('sidebar-collapsed');
            } else {
                if (sidebar) sidebar.classList.toggle('sidebar-open');
                if (backdrop) backdrop.classList.toggle('active');
            }
        }

        function closeAppSidebar() {
            var sb = document.getElementById('appSidebar');
            var bd = document.getElementById('sidebarBackdrop');
            if (sb) sb.classList.remove('sidebar-open');
            if (bd) bd.classList.remove('active');
        }

        // 2. User Popup Menu
        function toggleUserPopup(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            const popup = document.getElementById('userProfilePopup');
            const toggle = document.getElementById('userProfileToggle');
            if (!popup) return;
            const isOpen = popup.classList.contains('active');
            if (isOpen) {
                popup.classList.remove('active');
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
            } else {
                popup.classList.add('active');
                if (toggle) toggle.setAttribute('aria-expanded', 'true');
            }
        }

        function closeUserPopup() {
            const popup = document.getElementById('userProfilePopup');
            const toggle = document.getElementById('userProfileToggle');
            if (popup) popup.classList.remove('active');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        }

        document.addEventListener('click', function (e) {
            const container = document.getElementById('userDropdownContainer');
            if (container && !container.contains(e.target)) {
                closeUserPopup();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeUserPopup();
            }
        });

        // 3. Sao chép chuỗi vào Clipboard
        function copyText(text, btnElement) {
            if (!text) return;
            navigator.clipboard.writeText(text).then(function() {
                if (btnElement) {
                    var originalHtml = btnElement.innerHTML;
                    btnElement.innerHTML = '<i class="fa-solid fa-check text-success"></i> <span>Đã chép!</span>';
                    setTimeout(function() {
                        btnElement.innerHTML = originalHtml;
                    }, 2000);
                }
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Đã sao chép vào Clipboard!',
                    showConfirmButton: false,
                    timer: 1800
                });
            }).catch(function(err) {
                prompt('Sao chép thủ công:', text);
            });
        }

        function copySnippet(elementId) {
            var el = document.getElementById(elementId);
            if (el) {
                copyText(el.innerText || el.textContent);
            }
        }

        // 4. Chuyển đổi tab code snippets
        function switchSnippet(lang, btn) {
            document.querySelectorAll('.code-nav-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            ['curl', 'python', 'node', 'php'].forEach(l => {
                var el = document.getElementById('snippet-' + l);
                if (el) {
                    if (l === lang) {
                        el.classList.remove('d-none');
                    } else {
                        el.classList.add('d-none');
                    }
                }
            });
        }

        // 5. Checkbox Scopes trong Modal
        function toggleAllScopes(checkbox) {
            var children = document.querySelectorAll('.scope-child');
            children.forEach(c => {
                c.disabled = checkbox.checked;
                if (checkbox.checked) {
                    c.checked = false;
                }
            });
        }

        // 6. Test Token API Sandbox
        function testToken(tokenStr) {
            var input = document.getElementById('testTokenInput');
            if (input) {
                input.value = tokenStr;
                input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                runTokenTest();
            }
        }

        function runTokenTest() {
            var token = document.getElementById('testTokenInput').value.trim();
            var resBox = document.getElementById('testResultBox');
            if (!token) {
                Swal.fire('Lỗi', 'Vui lòng nhập hoặc chọn mã Token cần kiểm tra.', 'warning');
                return;
            }

            resBox.textContent = 'Đang gửi yêu cầu xác thực API...';

            fetch('api/verify-token.php', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + token,
                    'Content-Type': 'application/json'
                }
            })
            .then(res => res.json())
            .then(data => {
                resBox.textContent = JSON.stringify(data, null, 2);
                if (data.success) {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: 'Token hợp lệ và hoạt động tốt!',
                        showConfirmButton: false,
                        timer: 2000
                    });
                } else {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'error',
                        title: data.message || 'Token không hợp lệ!',
                        showConfirmButton: false,
                        timer: 2500
                    });
                }
            })
            .catch(err => {
                resBox.textContent = 'Lỗi kết nối tới API: ' + err.message;
            });
        }

        // 7. Xác nhận Thu hồi / Xóa
        function confirmRevoke(form) {
            event.preventDefault();
            Swal.fire({
                title: 'Thu hồi Access Token?',
                text: 'Các script và máy chủ đang sử dụng Token này sẽ không thể gọi API được nữa.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f59e0b',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Đồng ý thu hồi',
                cancelButtonText: 'Hủy'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
            return false;
        }

        function confirmDelete(form) {
            event.preventDefault();
            Swal.fire({
                title: 'Xóa vĩnh viễn Token?',
                text: 'Hành động này không thể hoàn tác. Dữ liệu token sẽ bị xóa khỏi cơ sở dữ liệu.',
                icon: 'error',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Xóa vĩnh viễn',
                cancelButtonText: 'Hủy'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
            return false;
        }

        function confirmLogout() {
            Swal.fire({
                title: 'Đăng xuất tài khoản?',
                text: 'Bạn có chắc chắn muốn kết thúc phiên đăng nhập?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Đăng xuất',
                cancelButtonText: 'Ở lại'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'logout.php';
                }
            });
        }
    </script>
</body>
</html>
