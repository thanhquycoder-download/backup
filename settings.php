<?php
/**
 * ==========================================================
 * TRANG CẤU HÌNH & THIẾT LẬP HỆ THỐNG - THANHQUYTECH
 * File: settings.php
 * Website: ThanhQuyTech
 * ==========================================================
 */

require_once __DIR__ . '/config/config.php';

require_login();

// 1. Lấy thông tin tài khoản người dùng hiện tại
$stmt = $pdo->prepare("
    SELECT id, uid, uuid, name, username, email, balance, avatar, role, status, password, created_at, updated_at
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

// 2. Tự động kiểm tra và khởi tạo bảng `settings` nếu chưa có
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `settings` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_uuid` CHAR(36) DEFAULT NULL,
            `setting_key` VARCHAR(100) NOT NULL,
            `setting_value` LONGTEXT DEFAULT NULL,
            `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general',
            `description` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_settings_user_uuid` (`user_uuid`),
            INDEX `idx_settings_key` (`setting_key`),
            INDEX `idx_settings_group` (`setting_group`),
            UNIQUE KEY `uq_user_setting_key` (`user_uuid`, `setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {
    // Bỏ qua nếu bảng đã tồn tại
}

// 3. Hàm trợ giúp lưu cài đặt cá nhân hoặc hệ thống
function save_setting(PDO $pdo, ?string $userUuid, string $key, string $value, string $group = 'general', ?string $desc = null) {
    $stmt = $pdo->prepare("
        INSERT INTO `settings` (`user_uuid`, `setting_key`, `setting_value`, `setting_group`, `description`)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            `setting_value` = VALUES(`setting_value`),
            `setting_group` = VALUES(`setting_group`),
            `description`   = COALESCE(VALUES(`description`), `description`)
    ");
    $stmt->execute([$userUuid, $key, $value, $group, $desc]);
}

// 4. XỬ LÝ CÁC HÀNH ĐỘNG CẬP NHẬT (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Mã xác thực CSRF không hợp lệ hoặc đã hết hạn. Vui lòng thử lại.', 'Lỗi bảo mật');
        header("Location: settings.php");
        exit;
    }

    $action = $_POST['action'] ?? '';

    // A. CẬP NHẬT CẤU HÌNH TÀI KHOẢN & GIAO DIỆN
    if ($action === 'update_general') {
        $displayName   = trim($_POST['name'] ?? '');
        $defaultPlatform = trim($_POST['default_platform'] ?? 'all');
        $hideBalance   = isset($_POST['hide_balance_header']) ? '1' : '0';
        $autoRenew     = isset($_POST['auto_renew_key']) ? '1' : '0';

        if (empty($displayName)) {
            set_flash('error', 'Họ và tên hiển thị không được để trống.', 'Dữ liệu thiếu');
            header("Location: settings.php?tab=general");
            exit;
        }

        try {
            // Cập nhật tên trong bảng users
            $stmtUser = $pdo->prepare("UPDATE users SET name = ? WHERE id = ?");
            $stmtUser->execute([$displayName, $user['id']]);

            // Cập nhật các tuỳ chọn vào bảng settings
            save_setting($pdo, $user['uuid'], 'default_platform', $defaultPlatform, 'general', 'Nền tảng mặc định');
            save_setting($pdo, $user['uuid'], 'hide_balance_header', $hideBalance, 'general', 'Ẩn hiển thị số dư trên header');
            save_setting($pdo, $user['uuid'], 'auto_renew_key', $autoRenew, 'general', 'Tự động gia hạn Key khi hết hạn');

            $_SESSION['user']['name'] = $displayName;
            set_flash('success', 'Đã lưu cấu hình tài khoản và tùy chọn giao diện thành công!', 'Thành Công');
        } catch (Exception $e) {
            set_flash('error', 'Không thể lưu cài đặt: ' . $e->getMessage(), 'Lỗi');
        }
        header("Location: settings.php?tab=general");
        exit;
    }

    // B. CẬP NHẬT THÔNG BÁO & TELEGRAM / WEBHOOK
    if ($action === 'update_notifications') {
        $notifEmail    = isset($_POST['notification_email']) ? '1' : '0';
        $notifTelegram = isset($_POST['notification_telegram']) ? '1' : '0';
        $telegramChatId = trim($_POST['telegram_chat_id'] ?? '');
        $webhookUrl    = trim($_POST['webhook_url'] ?? '');
        $webhookEvents = $_POST['webhook_events'] ?? [];

        if ($notifTelegram === '1' && empty($telegramChatId)) {
            set_flash('error', 'Bạn đã bật thông báo Telegram nhưng chưa nhập Telegram Chat ID.', 'Lỗi cấu hình');
            header("Location: settings.php?tab=notifications");
            exit;
        }

        try {
            save_setting($pdo, $user['uuid'], 'notification_email', $notifEmail, 'notifications', 'Nhận email thông báo');
            save_setting($pdo, $user['uuid'], 'notification_telegram', $notifTelegram, 'notifications', 'Nhận tin Telegram');
            save_setting($pdo, $user['uuid'], 'telegram_chat_id', $telegramChatId, 'notifications', 'ID Chat Telegram');
            save_setting($pdo, $user['uuid'], 'webhook_url', $webhookUrl, 'notifications', 'URL Webhook tự động');
            save_setting($pdo, $user['uuid'], 'webhook_events', json_encode($webhookEvents), 'notifications', 'Danh sách sự kiện Webhook');

            set_flash('success', 'Đã cập nhật kênh thông báo Telegram và Webhook thành công!', 'Thành Công');
        } catch (Exception $e) {
            set_flash('error', 'Không thể lưu cài đặt thông báo: ' . $e->getMessage(), 'Lỗi');
        }
        header("Location: settings.php?tab=notifications");
        exit;
    }

    // C. ĐỔI MẬT KHẨU BẢO MẬT
    if ($action === 'change_password') {
        $oldPassword = $_POST['old_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($oldPassword) || empty($newPassword) || empty($confirmPassword)) {
            set_flash('error', 'Vui lòng điền đầy đủ mật khẩu cũ và mật khẩu mới.', 'Thiếu thông tin');
            header("Location: settings.php?tab=security");
            exit;
        }

        if (!verify_password_stretched($oldPassword, $user['password'])) {
            set_flash('error', 'Mật khẩu hiện tại không chính xác. Vui lòng kiểm tra lại.', 'Sai mật khẩu');
            header("Location: settings.php?tab=security");
            exit;
        }

        if (strlen($newPassword) < 6) {
            set_flash('error', 'Mật khẩu mới phải có độ dài từ 6 ký tự trở lên.', 'Mật khẩu yếu');
            header("Location: settings.php?tab=security");
            exit;
        }

        if ($newPassword !== $confirmPassword) {
            set_flash('error', 'Mật khẩu xác nhận nhập lại không khớp với mật khẩu mới.', 'Không khớp');
            header("Location: settings.php?tab=security");
            exit;
        }

        try {
            $hashedPassword = hash_password_stretched($newPassword);
            $stmtUpdatePass = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmtUpdatePass->execute([$hashedPassword, $user['id']]);

            set_flash('success', 'Đổi mật khẩu tài khoản thành công! Mật khẩu mới đã được băm bảo mật.', 'Thành Công');
        } catch (Exception $e) {
            set_flash('error', 'Không thể đổi mật khẩu: ' . $e->getMessage(), 'Lỗi');
        }
        header("Location: settings.php?tab=security");
        exit;
    }

    // D. CẬP NHẬT CẤU HÌNH HỆ THỐNG (CHỈ DÀNH CHO ADMIN)
    if ($action === 'update_system') {
        if (!$isAdmin) {
            set_flash('error', 'Bạn không có quyền quản trị để thực hiện thao tác này.', 'Truy cập bị từ chối');
            header("Location: settings.php");
            exit;
        }

        $siteName        = trim($_POST['site_name'] ?? 'ThanhQuyTech');
        $siteTelegram    = trim($_POST['site_contact_telegram'] ?? '');
        $siteZalo        = trim($_POST['site_contact_zalo'] ?? '');
        $maintenance     = isset($_POST['maintenance_mode']) ? '1' : '0';
        $announcement    = trim($_POST['announcement_marquee'] ?? '');
        $referralDays    = max(1, (int)($_POST['referral_bonus_days'] ?? 1));

        try {
            save_setting($pdo, null, 'site_name', $siteName, 'system', 'Tên thương hiệu website');
            save_setting($pdo, null, 'site_contact_telegram', $siteTelegram, 'system', 'Telegram hỗ trợ admin');
            save_setting($pdo, null, 'site_contact_zalo', $siteZalo, 'system', 'Zalo hỗ trợ admin');
            save_setting($pdo, null, 'maintenance_mode', $maintenance, 'system', 'Chế độ bảo trì');
            save_setting($pdo, null, 'announcement_marquee', $announcement, 'system', 'Thông báo chạy chữ');
            save_setting($pdo, null, 'referral_bonus_days', (string)$referralDays, 'system', 'Số ngày thưởng Key VIP giới thiệu');

            set_flash('success', 'Cập nhật cấu hình toàn hệ thống ThanhQuyTech thành công!', 'Quản Trị Hệ Thống');
        } catch (Exception $e) {
            set_flash('error', 'Lỗi khi lưu cấu hình hệ thống: ' . $e->getMessage(), 'Lỗi');
        }
        header("Location: settings.php?tab=system");
        exit;
    }
}

// 5. NẠP DỮ LIỆU CẤU HÌNH TỪ CƠ SỞ DỮ LIỆU
$userSettings = [];
try {
    $stmtUserSet = $pdo->prepare("SELECT setting_key, setting_value FROM `settings` WHERE user_uuid = ?");
    $stmtUserSet->execute([$user['uuid']]);
    while ($row = $stmtUserSet->fetch()) {
        $userSettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    //
}

$systemSettings = [];
try {
    $stmtSysSet = $pdo->query("SELECT setting_key, setting_value FROM `settings` WHERE user_uuid IS NULL");
    while ($row = $stmtSysSet->fetch()) {
        $systemSettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    //
}

// Giá trị mặc định
$defaultPlatform = $userSettings['default_platform'] ?? 'all';
$hideBalance     = ($userSettings['hide_balance_header'] ?? '0') === '1';
$autoRenew       = ($userSettings['auto_renew_key'] ?? '0') === '1';
$notifEmail      = ($userSettings['notification_email'] ?? '1') === '1';
$notifTelegram   = ($userSettings['notification_telegram'] ?? '0') === '1';
$telegramChatId  = $userSettings['telegram_chat_id'] ?? '';
$webhookUrl      = $userSettings['webhook_url'] ?? '';
$webhookEvents   = json_decode($userSettings['webhook_events'] ?? '[]', true) ?: ['deposit', 'key'];

$sysSiteName     = $systemSettings['site_name'] ?? 'ThanhQuyTech - Nền Tảng Tool & Cloud Bản Quyền';
$sysTelegram     = $systemSettings['site_contact_telegram'] ?? 'https://t.me/thanhquytech_support';
$sysZalo         = $systemSettings['site_contact_zalo'] ?? '0987654321';
$sysMaintenance  = ($systemSettings['maintenance_mode'] ?? '0') === '1';
$sysAnnouncement = $systemSettings['announcement_marquee'] ?? '🎉 Chào mừng đến với ThanhQuyTech! Hệ thống tự động kích hoạt Key và Cloud 24/7 siêu tốc.';
$sysRefDays      = (int)($systemSettings['referral_bonus_days'] ?? 1);

$activeTab = $_GET['tab'] ?? 'general';
if (!in_array($activeTab, ['general', 'notifications', 'security', 'system'])) {
    $activeTab = 'general';
}

$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Cấu Hình & Thiết Lập - ThanhQuyTech</title>
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
        .settings-hero {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
            border-radius: var(--radius-lg);
            padding: 30px 36px;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 35px -10px rgba(30, 27, 75, 0.35);
            margin-bottom: 28px;
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

        /* NAV TABS */
        .settings-tabs {
            display: flex;
            gap: 8px;
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            padding: 6px;
            margin-bottom: 24px;
            overflow-x: auto;
            scrollbar-width: none;
        }

        .settings-tabs::-webkit-scrollbar { display: none; }

        .settings-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 18px;
            border-radius: 10px;
            border: none;
            background: transparent;
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 700;
            white-space: nowrap;
            text-decoration: none;
            transition: var(--transition);
        }

        .settings-tab-btn:hover {
            background: #f8fafc;
            color: var(--primary);
        }

        .settings-tab-btn.active {
            background: #eef2ff;
            color: var(--primary);
        }

        /* SECTION CARD */
        .settings-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 28px;
            box-shadow: var(--shadow-card);
            margin-bottom: 24px;
        }

        .card-header-clean {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--card-border);
        }

        .card-title-clean {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        .form-label {
            font-weight: 700;
            font-size: 0.88rem;
            color: var(--text-heading);
            margin-bottom: 6px;
        }

        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid var(--card-border);
            padding: 10px 14px;
            font-size: 0.92rem;
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.12);
        }

        /* SWITCH TOGGLE */
        .form-check-input {
            width: 2.8em;
            height: 1.5em;
            cursor: pointer;
        }

        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .switch-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            background: #f8fafc;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            margin-bottom: 12px;
            transition: var(--transition);
        }

        .switch-container:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        .switch-title {
            font-size: 0.92rem;
            font-weight: 700;
            color: var(--text-heading);
            margin-bottom: 2px;
        }

        .switch-desc {
            font-size: 0.82rem;
            color: var(--text-muted);
            margin: 0;
        }

        /* BUTTONS */
        .btn-gradient-primary {
            background: var(--gradient-primary);
            color: #ffffff;
            font-weight: 700;
            border: none;
            padding: 10px 24px;
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
            .settings-hero {
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
                    <span class="balance-val"><?= !empty($hideBalance) ? '****** đ' : format_currency($currentUser['balance']) ?></span>
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
                            <div class="fw-bold" style="color: #15803d; font-size: 0.95rem;"><?= !empty($hideBalance) ? '****** đ' : format_currency($currentUser['balance']) ?></div>
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
                        <a href="token.php" class="popup-menu-item">
                            <i class="fa-solid fa-fingerprint text-primary me-2"></i> Access Token
                        </a>
                        <a href="settings.php" class="popup-menu-item text-primary fw-bold">
                            <i class="fa-solid fa-gear text-primary me-2"></i> Cấu hình
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
            <!-- Access Token -->
            <li>
                <a href="token.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-fingerprint"></i></span>
                    <span class="sidebar-title">Access Token</span>
                </a>
            </li>
            <!-- Cấu hình (Active) -->
            <li>
                <a href="settings.php" class="sidebar-link active">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-gear"></i></span>
                    <span class="sidebar-title">Cấu hình</span>
                </a>
            </li>
        </ul>

        <div class="sidebar-category">CÔNG CỤ & DỊCH VỤ</div>
        <ul class="sidebar-nav-list">
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
            <li>
                <a href="referral.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-share-nodes"></i></span>
                    <span class="sidebar-title">Giới thiệu</span>
                    <span class="badge-history ms-auto"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                </a>
            </li>
            <li>
                <a href="support.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-headset"></i></span>
                    <span class="sidebar-title">Hỗ trợ</span>
                    <span class="badge-history ms-auto"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                </a>
            </li>
            <?php if ($isAdmin): ?>
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
                    <li class="breadcrumb-item active text-primary" aria-current="page">Cấu hình</li>
                </ol>
            </nav>

            <!-- HERO CARD -->
            <div class="settings-hero">
                <div class="hero-badge">
                    <i class="fa-solid fa-sliders"></i>
                    <span>TÙY BIẾN & THIẾT LẬP</span>
                </div>
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                    <div>
                        <h1 class="hero-title">Trung Tâm Cấu Hình Hệ Thống</h1>
                        <p class="hero-subtitle">
                            Tùy chỉnh thông tin hiển thị cá nhân, kết nối nhận thông báo đơn hàng qua Telegram Bot tự động, quản trị chính sách bảo mật và tham số vận hành nền tảng.
                        </p>
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

            <!-- TABS MENU -->
            <div class="settings-tabs">
                <a href="settings.php?tab=general" class="settings-tab-btn <?= ($activeTab === 'general') ? 'active' : '' ?>">
                    <i class="fa-solid fa-user-gear"></i>
                    <span>Cấu hình tài khoản</span>
                </a>
                <a href="settings.php?tab=notifications" class="settings-tab-btn <?= ($activeTab === 'notifications') ? 'active' : '' ?>">
                    <i class="fa-solid fa-bell"></i>
                    <span>Thông báo & Webhook</span>
                </a>
                <a href="settings.php?tab=security" class="settings-tab-btn <?= ($activeTab === 'security') ? 'active' : '' ?>">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span>Bảo mật & Mật khẩu</span>
                </a>
                <?php if ($isAdmin): ?>
                <a href="settings.php?tab=system" class="settings-tab-btn <?= ($activeTab === 'system') ? 'active' : '' ?>">
                    <i class="fa-solid fa-sliders text-danger"></i>
                    <span class="text-danger fw-bold">Cấu hình hệ thống (Admin)</span>
                </a>
                <?php endif; ?>
            </div>

            <!-- ======================================================
             * TAB 1: CẤU HÌNH TÀI KHOẢN & GIAO DIỆN
             * ====================================================== -->
            <?php if ($activeTab === 'general'): ?>
            <div class="settings-card">
                <div class="card-header-clean">
                    <h2 class="card-title-clean">
                        <i class="fa-solid fa-user-pen text-primary"></i>
                        <span>Thông Tin Tài Khoản & Tùy Chọn Giao Diện</span>
                    </h2>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1">Tài khoản UID: <?= htmlspecialchars($user['uid']) ?></span>
                </div>

                <form action="settings.php?tab=general" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                    <input type="hidden" name="action" value="update_general">

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="name" class="form-label">Tên hiển thị <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($user['name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="username" class="form-label">Tên đăng nhập (@username)</label>
                            <input type="text" class="form-control bg-light" id="username" value="<?= htmlspecialchars($user['username']) ?>" readonly disabled>
                            <div class="form-text text-muted">Tên đăng nhập là định danh cố định không thể thay đổi.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">Địa chỉ Email liên hệ</label>
                            <input type="email" class="form-control bg-light" id="email" value="<?= htmlspecialchars($user['email']) ?>" readonly disabled>
                            <div class="form-text text-muted">Để đổi email, vui lòng liên hệ bộ phận Hỗ trợ khách hàng.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="default_platform" class="form-label">Nền tảng mặc định khi vào Bảng điều khiển</label>
                            <select class="form-select" id="default_platform" name="default_platform">
                                <option value="all" <?= ($defaultPlatform === 'all') ? 'selected' : '' ?>>Tổng quan (Tất cả nền tảng)</option>
                                <option value="golike" <?= ($defaultPlatform === 'golike') ? 'selected' : '' ?>>Golike</option>
                                <option value="tuongtaccheo" <?= ($defaultPlatform === 'tuongtaccheo') ? 'selected' : '' ?>>Tương Tác Chéo (TTC)</option>
                                <option value="traodoisub" <?= ($defaultPlatform === 'traodoisub') ? 'selected' : '' ?>>Trao Đổi Sub (TDS)</option>
                            </select>
                        </div>
                    </div>

                    <h5 class="fw-bold text-dark mb-3 mt-4">
                        <i class="fa-solid fa-palette text-primary me-2"></i> Tùy Chọn Trải Nghiệm
                    </h5>

                    <!-- Switch ẩn số dư -->
                    <div class="switch-container">
                        <div>
                            <div class="switch-title">Ẩn số dư tài khoản trên thanh điều hướng Header</div>
                            <p class="switch-desc">Hữu ích khi bạn quay màn hình, chụp ảnh chia sẻ thành tích hoặc làm việc nơi đông người.</p>
                        </div>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" name="hide_balance_header" id="switchHideBalance" <?= $hideBalance ? 'checked' : '' ?>>
                        </div>
                    </div>

                    <!-- Switch tự động gia hạn -->
                    <div class="switch-container">
                        <div>
                            <div class="switch-title">Tự động gia hạn Key bản quyền khi gần hết hạn</div>
                            <p class="switch-desc">Nếu số dư ví đủ tiền, hệ thống sẽ tự động gia hạn thêm chu kỳ tương ứng để bot không bị gián đoạn.</p>
                        </div>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" name="auto_renew_key" id="switchAutoRenew" <?= $autoRenew ? 'checked' : '' ?>>
                        </div>
                    </div>

                    <div class="text-end mt-4">
                        <button type="submit" class="btn-gradient-primary">
                            <i class="fa-solid fa-floppy-disk"></i> Lưu Thay Đổi
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- ======================================================
             * TAB 2: THÔNG BÁO & TELEGRAM BOT / WEBHOOK
             * ====================================================== -->
            <?php if ($activeTab === 'notifications'): ?>
            <div class="settings-card">
                <div class="card-header-clean">
                    <h2 class="card-title-clean">
                        <i class="fa-solid fa-bell text-warning"></i>
                        <span>Cấu Hình Thông Báo & Tích Hợp Webhook</span>
                    </h2>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-1">Tự động hóa 24/7</span>
                </div>

                <form action="settings.php?tab=notifications" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                    <input type="hidden" name="action" value="update_notifications">

                    <!-- Switch Email -->
                    <div class="switch-container mb-3">
                        <div>
                            <div class="switch-title"><i class="fa-solid fa-envelope text-primary me-2"></i> Nhận email thông báo giao dịch</div>
                            <p class="switch-desc">Gửi thông báo về hộp thư <?= htmlspecialchars($user['email']) ?> khi có nạp tiền hoặc kích hoạt key.</p>
                        </div>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" name="notification_email" id="switchEmail" <?= $notifEmail ? 'checked' : '' ?>>
                        </div>
                    </div>

                    <!-- Switch Telegram -->
                    <div class="switch-container mb-3">
                        <div>
                            <div class="switch-title"><i class="fa-brands fa-telegram text-info me-2"></i> Nhận tin nhắn thông báo tức thì qua Telegram Bot</div>
                            <p class="switch-desc">Nhận biến động số dư, thông báo tool gặp sự cố hoặc key sắp hết hạn trực tiếp trên điện thoại.</p>
                        </div>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" name="notification_telegram" id="switchTelegram" <?= $notifTelegram ? 'checked' : '' ?>>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="telegram_chat_id" class="form-label">Telegram Chat ID Của Bạn</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-hashtag"></i></span>
                            <input type="text" class="form-control" id="telegram_chat_id" name="telegram_chat_id" value="<?= htmlspecialchars($telegramChatId) ?>" placeholder="VD: 589214782">
                            <a href="https://t.me/userinfobot" target="_blank" class="btn btn-outline-secondary">
                                <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Lấy Chat ID qua @userinfobot
                            </a>
                        </div>
                        <div class="form-text text-muted">
                            Mở ứng dụng Telegram, tìm bot <strong>@userinfobot</strong> và nhấn Start để xem mã Chat ID số của bạn, sau đó dán vào đây.
                        </div>
                    </div>

                    <hr class="my-4 border-secondary-subtle">

                    <h5 class="fw-bold text-dark mb-3">
                        <i class="fa-solid fa-network-wired text-success me-2"></i> Webhook Endpoint Cho Nhà Phát Triển
                    </h5>

                    <div class="mb-3">
                        <label for="webhook_url" class="form-label">Webhook URL (Nhận HTTP POST JSON khi có sự kiện)</label>
                        <input type="url" class="form-control font-monospace" id="webhook_url" name="webhook_url" value="<?= htmlspecialchars($webhookUrl) ?>" placeholder="https://yourserver.com/api/tqt-webhook">
                        <div class="form-text text-muted">Server của bạn cần phản hồi mã trạng thái HTTP 200 OK khi nhận dữ liệu webhook.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Các sự kiện kích hoạt Webhook:</label>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="webhook_events[]" value="deposit" id="evDeposit" <?= in_array('deposit', $webhookEvents) ? 'checked' : '' ?>>
                                <label class="form-check-label text-dark" for="evDeposit">Nạp tiền thành công (Deposit)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="webhook_events[]" value="key" id="evKey" <?= in_array('key', $webhookEvents) ? 'checked' : '' ?>>
                                <label class="form-check-label text-dark" for="evKey">Mua / Kích hoạt Key mới</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="webhook_events[]" value="ticket" id="evTicket" <?= in_array('ticket', $webhookEvents) ? 'checked' : '' ?>>
                                <label class="form-check-label text-dark" for="evTicket">Ticket hỗ trợ có phản hồi mới</label>
                            </div>
                        </div>
                    </div>

                    <div class="text-end mt-4">
                        <button type="submit" class="btn-gradient-primary">
                            <i class="fa-solid fa-floppy-disk"></i> Lưu Cấu Hình Thông Báo
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- ======================================================
             * TAB 3: BẢO MẬT & ĐỔI MẬT KHẨU
             * ====================================================== -->
            <?php if ($activeTab === 'security'): ?>
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="settings-card">
                        <div class="card-header-clean">
                            <h2 class="card-title-clean">
                                <i class="fa-solid fa-lock text-danger"></i>
                                <span>Đổi Mật Khẩu Tài Khoản</span>
                            </h2>
                        </div>

                        <form action="settings.php?tab=security" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                            <input type="hidden" name="action" value="change_password">

                            <div class="mb-3">
                                <label for="old_password" class="form-label">Mật khẩu hiện tại <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="old_password" name="old_password" required placeholder="Nhập mật khẩu hiện tại của bạn">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility('old_password')"><i class="fa-solid fa-eye"></i></button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="new_password" class="form-label">Mật khẩu mới <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="new_password" name="new_password" required minlength="6" placeholder="Tối thiểu 6 ký tự" oninput="checkStrength(this.value)">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility('new_password')"><i class="fa-solid fa-eye"></i></button>
                                </div>
                                <div class="progress mt-2" style="height: 5px;">
                                    <div id="strengthBar" class="progress-bar bg-danger" role="progressbar" style="width: 0%"></div>
                                </div>
                                <div id="strengthText" class="form-text text-muted small mt-1">Độ mạnh mật khẩu: Chưa nhập</div>
                            </div>

                            <div class="mb-4">
                                <label for="confirm_password" class="form-label">Xác nhận lại mật khẩu mới <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="6" placeholder="Nhập lại mật khẩu mới">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassVisibility('confirm_password')"><i class="fa-solid fa-eye"></i></button>
                                </div>
                            </div>

                            <div class="text-end">
                                <button type="submit" class="btn-gradient-primary">
                                    <i class="fa-solid fa-key"></i> Cập Nhật Mật Khẩu
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="col-lg-5">
                    <!-- THÔNG TIN PHIÊN ĐĂNG NHẬP -->
                    <div class="settings-card">
                        <div class="card-header-clean">
                            <h2 class="card-title-clean">
                                <i class="fa-solid fa-shield-check text-success"></i>
                                <span>Trạng Thái Bảo Mật</span>
                            </h2>
                        </div>

                        <ul class="list-group list-group-flush mb-3">
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <span class="text-muted">Phương thức mã hóa:</span>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">BCrypt + Pepper Stretching</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <span class="text-muted">Định danh chuẩn:</span>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">UUIDv7 RFC 9562</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <span class="text-muted">Trạng thái tài khoản:</span>
                                <span class="badge bg-success-subtle text-success">Đang hoạt động (Active)</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <span class="text-muted">Thời điểm tham gia:</span>
                                <span class="fw-bold text-dark"><?= date('d/m/Y H:i', strtotime($user['created_at'])) ?></span>
                            </li>
                        </ul>

                        <div class="border rounded-3 p-3 bg-light">
                            <div class="fw-bold text-dark mb-1"><i class="fa-solid fa-laptop me-1 text-primary"></i> Phiên đăng nhập hiện tại</div>
                            <div class="small text-muted mb-2">Trình duyệt: <?= htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? 'Web Browser') ?></div>
                            <div class="small text-muted">Địa chỉ IP: <?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') ?></div>
                        </div>

                        <div class="mt-4">
                            <button type="button" class="btn btn-outline-danger w-100 fw-bold" onclick="confirmLogout()">
                                <i class="fa-solid fa-right-from-bracket me-1"></i> Đăng Xuất Khỏi Tài Khoản
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ======================================================
             * TAB 4: CẤU HÌNH HỆ THỐNG (ADMIN ONLY)
             * ====================================================== -->
            <?php if ($activeTab === 'system' && $isAdmin): ?>
            <div class="settings-card">
                <div class="card-header-clean">
                    <h2 class="card-title-clean">
                        <i class="fa-solid fa-shield-halved text-danger"></i>
                        <span>Cấu Hình Vận Hành Toàn Hệ Thống (Dành Riêng Quản Trị Viên)</span>
                    </h2>
                    <span class="badge bg-danger text-white px-3 py-1">Quyền Admin Cấp Cao</span>
                </div>

                <form action="settings.php?tab=system" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                    <input type="hidden" name="action" value="update_system">

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="site_name" class="form-label">Tên thương hiệu Website <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="site_name" name="site_name" value="<?= htmlspecialchars($sysSiteName) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label for="referral_bonus_days" class="form-label">Số ngày Key VIP thưởng khi giới thiệu thành công</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="referral_bonus_days" name="referral_bonus_days" min="1" max="365" value="<?= $sysRefDays ?>" required>
                                <span class="input-group-text">ngày / bạn</span>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="site_contact_telegram" class="form-label">Link Telegram Hỗ Trợ Khách Hàng</label>
                            <input type="url" class="form-control" id="site_contact_telegram" name="site_contact_telegram" value="<?= htmlspecialchars($sysTelegram) ?>" placeholder="https://t.me/...">
                        </div>

                        <div class="col-md-6">
                            <label for="site_contact_zalo" class="form-label">Số Zalo Hỗ Trợ Hotline</label>
                            <input type="text" class="form-control" id="site_contact_zalo" name="site_contact_zalo" value="<?= htmlspecialchars($sysZalo) ?>" placeholder="0987654321">
                        </div>

                        <div class="col-12">
                            <label for="announcement_marquee" class="form-label">Nội dung chạy chữ đầu trang (Marquee Announcement)</label>
                            <textarea class="form-control" id="announcement_marquee" name="announcement_marquee" rows="2"><?= htmlspecialchars($sysAnnouncement) ?></textarea>
                        </div>
                    </div>

                    <!-- Switch bảo trì -->
                    <div class="switch-container mb-4 border-danger border-opacity-25 bg-danger bg-opacity-10">
                        <div>
                            <div class="switch-title text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i> Chế độ bảo trì hệ thống (Maintenance Mode)</div>
                            <p class="switch-desc text-danger text-opacity-75">Khi bật, chỉ Admin mới có thể truy cập các trang mua hàng và nạp tiền. Thành viên sẽ nhận thông báo hệ thống đang bảo trì.</p>
                        </div>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" name="maintenance_mode" id="switchMaintenance" <?= $sysMaintenance ? 'checked' : '' ?>>
                        </div>
                    </div>

                    <div class="text-end mt-4">
                        <button type="submit" class="btn btn-danger fw-bold px-4 py-2 shadow-sm rounded-3">
                            <i class="fa-solid fa-shield-check me-2"></i> Lưu Cấu Hình Toàn Hệ Thống
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

        </div>

        <!-- FOOTER -->
        <footer class="app-footer">
            <div>
                © 2026 <strong>ThanhQuyTech</strong>. Bản quyền thuộc về hệ sinh thái Tool & Cloud.
            </div>
            <div class="d-flex gap-3">
                <a href="support.php" class="text-decoration-none text-muted">Hỗ trợ 24/7</a>
                <a href="token.php" class="text-decoration-none text-muted">Access Token</a>
            </div>
        </footer>
    </main>

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

        // 3. Ẩn/Hiện mật khẩu
        function togglePassVisibility(inputId) {
            var input = document.getElementById(inputId);
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
            } else {
                input.type = 'password';
            }
        }

        // 4. Đo độ mạnh mật khẩu
        function checkStrength(password) {
            var bar = document.getElementById('strengthBar');
            var text = document.getElementById('strengthText');
            if (!bar || !text) return;

            var score = 0;
            if (password.length >= 6) score += 25;
            if (password.length >= 10) score += 25;
            if (/[A-Z]/.test(password)) score += 25;
            if (/[0-9!@#$%^&*()]/.test(password)) score += 25;

            bar.style.width = score + '%';
            if (score <= 25) {
                bar.className = 'progress-bar bg-danger';
                text.textContent = 'Độ mạnh mật khẩu: Yếu';
                text.className = 'form-text text-danger small mt-1';
            } else if (score <= 50) {
                bar.className = 'progress-bar bg-warning';
                text.textContent = 'Độ mạnh mật khẩu: Trung bình';
                text.className = 'form-text text-warning small mt-1';
            } else if (score <= 75) {
                bar.className = 'progress-bar bg-info';
                text.textContent = 'Độ mạnh mật khẩu: Khá tốt';
                text.className = 'form-text text-info small mt-1';
            } else {
                bar.className = 'progress-bar bg-success';
                text.textContent = 'Độ mạnh mật khẩu: Rất mạnh và an toàn';
                text.className = 'form-text text-success small mt-1';
            }
        }

        // 5. Đăng xuất
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
