<?php
/**
 * ==========================================================
 * TRANG CẤU HÌNH & THIẾT LẬP VẬN HÀNH TOOL & CLOUD ĐA NỀN TẢNG
 * File: settings.php
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
    // Bỏ qua nếu bảng đã sẵn sàng
}

// 3. Hàm trợ giúp lưu cài đặt cá nhân vào bảng settings
function save_user_setting(PDO $pdo, string $userUuid, string $key, string $value, string $group = 'bot_config', ?string $desc = null): void {
    $stmt = $pdo->prepare("
        INSERT INTO `settings` (`user_uuid`, `setting_key`, `setting_value`, `setting_group`, `description`)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            `setting_value` = VALUES(`setting_value`),
            `setting_group` = VALUES(`setting_group`),
            `description`   = COALESCE(VALUES(`description`), `description`),
            `updated_at`    = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$userUuid, $key, $value, $group, $desc]);
}

// 4. Lấy danh sách tài khoản đã liên kết từ bảng `tokens`
$tokens = [];
try {
    $stmtTokens = $pdo->prepare("
        SELECT id, platform, account_id, name, username, coin, status 
        FROM `tokens` 
        WHERE `user_uuid` = ? 
        ORDER BY id DESC
    ");
    $stmtTokens->execute([$user['uuid']]);
    $tokens = $stmtTokens->fetchAll();
} catch (Exception $e) {
    $tokens = [];
}

// 5. Lấy danh sách logo nền tảng từ bảng `platforms`
$platformLogos = [
    'golike' => 'https://cdn.jsdelivr.net/gh/thanhquytech-stack/images@main/golike.png'
];
try {
    $stmtPlat = $pdo->query("SELECT `code`, `image` FROM `platforms` WHERE `image` IS NOT NULL AND `image` != ''");
    if ($stmtPlat) {
        while ($pRow = $stmtPlat->fetch()) {
            $platformLogos[strtolower(trim($pRow['code']))] = $pRow['image'];
        }
    }
} catch (Exception $e) {
    // Dùng fallback
}
$golikeLogo = $platformLogos['golike'] ?? 'https://cdn.jsdelivr.net/gh/thanhquytech-stack/images@main/golike.png';

// 6. XỬ LÝ LƯU CẤU HÌNH (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Mã xác thực bảo mật CSRF không hợp lệ. Vui lòng tải lại trang.', 'Lỗi xác thực');
        header("Location: settings.php");
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_bot_settings') {
        $scope = strtolower(trim($_POST['target_scope'] ?? 'general'));
        if (!in_array($scope, ['general', 'golike', 'tuongtaccheo', 'traodoisub', 'cloud'])) {
            $scope = 'general';
        }

        // Đọc các giá trị cấu hình
        $configData = [
            'scope'                => $scope,
            'social_platforms'     => $_POST['social_platforms'] ?? ['all'],
            'selected_accounts'    => array_map('intval', $_POST['selected_accounts'] ?? []),
            'job_limit'            => (int)($_POST['job_limit'] ?? 10),
            'job_unlimited'        => isset($_POST['job_unlimited']) ? 1 : 0,
            'delay_seconds'        => (int)($_POST['delay_seconds'] ?? 15),
            'switch_account_jobs'  => (int)($_POST['switch_account_jobs'] ?? 5),
            'switch_never'         => isset($_POST['switch_never']) ? 1 : 0,
            'price_filter_enabled' => isset($_POST['price_filter_enabled']) ? 1 : 0,
            'min_price'            => (int)($_POST['min_price'] ?? 35),
            'cloud_custom_enabled' => isset($_POST['cloud_custom_enabled']) ? 1 : 0,
            'auto_skip_error'      => isset($_POST['auto_skip_error']) ? 1 : 0,
            'anti_block_safe'      => isset($_POST['anti_block_safe']) ? 1 : 0,
            'notify_telegram'      => isset($_POST['notify_telegram']) ? 1 : 0,
            'updated_at'           => date('Y-m-d H:i:s')
        ];

        // Chuẩn hóa giá trị
        if ($configData['job_limit'] < 1) $configData['job_limit'] = 10;
        if ($configData['delay_seconds'] < 0) $configData['delay_seconds'] = 15;
        if ($configData['switch_account_jobs'] < 1) $configData['switch_account_jobs'] = 5;
        if ($configData['min_price'] < 0) $configData['min_price'] = 0;

        $settingKey = 'bot_config_' . $scope;
        $settingDesc = 'Cấu hình vận hành ' . ($scope === 'general' ? 'chung toàn hệ thống' : "nền tảng {$scope}");

        try {
            save_user_setting($pdo, $user['uuid'], $settingKey, json_encode($configData, JSON_UNESCAPED_UNICODE), 'bot_config', $settingDesc);
            set_flash('success', 'Đã lưu cấu hình vận hành thành công cho [' . strtoupper($scope) . ']!', 'Đã cập nhật');
        } catch (Exception $e) {
            set_flash('error', 'Không thể lưu cấu hình vào cơ sở dữ liệu: ' . $e->getMessage(), 'Lỗi lưu trữ');
        }

        header("Location: settings.php?scope=" . urlencode($scope));
        exit;
    }
}

// 7. NẠP TOÀN BỘ CẤU HÌNH TỪ BẢNG `settings`
$allConfigs = [];
try {
    $stmtSets = $pdo->prepare("
        SELECT setting_key, setting_value 
        FROM `settings` 
        WHERE user_uuid = ? AND setting_group = 'bot_config'
    ");
    $stmtSets->execute([$user['uuid']]);
    while ($r = $stmtSets->fetch()) {
        $decoded = json_decode($r['setting_value'], true);
        if (is_array($decoded)) {
            $allConfigs[$r['setting_key']] = $decoded;
        }
    }
} catch (Exception $e) {
    //
}

// Cấu hình mặc định nếu chưa lưu
$defaultGeneralConfig = [
    'scope'                => 'general',
    'social_platforms'     => ['all'],
    'selected_accounts'    => [],
    'job_limit'            => 10,
    'job_unlimited'        => 0,
    'delay_seconds'        => 15,
    'switch_account_jobs'  => 5,
    'switch_never'         => 0,
    'price_filter_enabled' => 0,
    'min_price'            => 35,
    'cloud_custom_enabled' => 0,
    'auto_skip_error'      => 1,
    'anti_block_safe'      => 1,
    'notify_telegram'      => 0
];

$scopeParam = strtolower(trim($_GET['scope'] ?? 'general'));
if (!in_array($scopeParam, ['general', 'golike', 'tuongtaccheo', 'traodoisub', 'cloud'])) {
    $scopeParam = 'general';
}

$currentScopeKey = 'bot_config_' . $scopeParam;
$activeConfig = $allConfigs[$currentScopeKey] ?? ($allConfigs['bot_config_general'] ?? $defaultGeneralConfig);

$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Cấu Hình Vận Hành Tool & Đa Nền Tảng - ThanhQuyTech</title>
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
            --primary-hover: #4338ca;
            --accent: #06b6d4;
            --gradient-primary: linear-gradient(135deg, #4f46e5 0%, #6366f1 50%, #06b6d4 100%);
            --radius-md: 14px;
            --radius-lg: 20px;
            --shadow-card: 0 10px 30px -10px rgba(0, 0, 0, 0.06), 0 2px 8px rgba(0, 0, 0, 0.03);
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
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
            font-size: 1.2rem;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
            flex-shrink: 0;
        }

        .brand-name {
            font-size: 1.18rem;
            font-weight: 800;
            color: var(--text-heading);
            letter-spacing: -0.4px;
            white-space: nowrap;
        }

        .brand-name span { color: var(--primary); }

        .header-right {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-shrink: 0;
        }

        .header-balance-card {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 14px;
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 50px;
            text-decoration: none;
            color: inherit;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
            transition: var(--transition);
        }

        .header-balance-card:hover {
            border-color: #10b981;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
            transform: translateY(-1px);
        }

        .balance-wallet-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #ecfdf5;
            color: #10b981;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .balance-text-group {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
            text-align: left;
        }

        .balance-title {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
        }

        .balance-val {
            font-size: 0.92rem;
            font-weight: 800;
            color: #059669;
        }

        .user-profile-toggle {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
        }

        .user-avatar-small {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e0e7ff;
        }

        /* SIDEBAR */
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
            padding: 20px 14px 40px;
            transition: var(--transition);
        }

        .sidebar-category {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #94a3b8;
            margin: 18px 12px 6px;
        }

        .sidebar-nav-list {
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
        }

        .sidebar-link:hover {
            background: #f1f5f9;
            color: var(--primary);
        }

        .sidebar-link.active {
            background: #eef2ff;
            color: var(--primary);
            font-weight: 700;
        }

        .sidebar-icon {
            width: 24px;
            height: 24px;
            font-size: 1.05rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
        }

        .sidebar-link.active .sidebar-icon { color: var(--primary); }

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
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
        }

        /* HERO CARD */
        .settings-hero {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
            border-radius: var(--radius-lg);
            padding: 28px 32px;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 35px -10px rgba(30, 27, 75, 0.35);
            margin-bottom: 24px;
        }

        .settings-hero::before {
            content: "";
            position: absolute;
            top: -60px;
            right: -60px;
            width: 260px;
            height: 260px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.4) 0%, transparent 70%);
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
            margin-bottom: 12px;
        }

        .hero-title {
            font-size: 1.75rem;
            font-weight: 800;
            margin-bottom: 6px;
            letter-spacing: -0.5px;
        }

        .hero-subtitle {
            font-size: 0.92rem;
            color: #cbd5e1;
            max-width: 750px;
            line-height: 1.55;
            margin-bottom: 0;
        }

        /* CARD GIAO DIỆN CHÍNH */
        .config-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 26px 30px;
            box-shadow: var(--shadow-card);
            margin-bottom: 24px;
        }

        .config-section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f1f5f9;
            flex-wrap: wrap;
            gap: 10px;
        }

        .config-title-text {
            font-size: 1.12rem;
            font-weight: 800;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .config-title-badge {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 50px;
            background: #f1f5f9;
            color: #475569;
        }

        /* 1. CHỌN CHẾ ĐỘ NỀN TẢNG (SCOPE SELECTOR) */
        .scope-selector-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }

        .scope-card-item {
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px 16px;
            background: #ffffff;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 12px;
            user-select: none;
            position: relative;
        }

        .scope-card-item:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
            transform: translateY(-2px);
        }

        .scope-card-item.active {
            border-color: #4f46e5;
            background: #eef2ff;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .scope-icon-wrap {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
            background: #f1f5f9;
        }

        .scope-card-item.active .scope-icon-wrap {
            background: #ffffff;
        }

        .scope-text-wrap {
            min-width: 0;
            flex-grow: 1;
        }

        .scope-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.25;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .scope-sub {
            font-size: 0.72rem;
            color: #64748b;
            margin-top: 2px;
        }

        .scope-check-dot {
            font-size: 1rem;
            color: #10b981;
            opacity: 0;
            transition: var(--transition);
        }

        .scope-card-item.active .scope-check-dot {
            opacity: 1;
        }

        /* 2. CHỌN MẠNG XÃ HỘI (SOCIAL CHIPS) */
        .social-chips-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 24px;
        }

        .social-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 50px;
            border: 1.5px solid #e2e8f0;
            background: #ffffff;
            font-size: 0.88rem;
            font-weight: 700;
            color: #334155;
            cursor: pointer;
            transition: var(--transition);
            user-select: none;
        }

        .social-chip:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
        }

        .social-chip.active {
            border-color: #6366f1;
            background: #eef2ff;
            color: #4338ca;
            box-shadow: 0 2px 6px rgba(99, 102, 241, 0.12);
        }

        .social-chip input[type="checkbox"] {
            display: none;
        }

        .social-chip-check {
            font-size: 0.9rem;
            color: #10b981;
            display: none;
        }

        .social-chip.active .social-chip-check {
            display: inline-block;
        }

        /* 3. CHỌN TÀI KHOẢN ĐA LUỒNG */
        .accounts-grid-wrapper {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
            max-height: 380px;
            overflow-y: auto;
            padding: 4px;
        }

        .account-select-card {
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 14px;
            background: #ffffff;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 12px;
            user-select: none;
        }

        .account-select-card:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
        }

        .account-select-card.active {
            border-color: #10b981;
            background: #f0fdf4;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.15);
        }

        .account-avatar-box {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #4361ee;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .account-info-box {
            min-width: 0;
            flex-grow: 1;
        }

        .account-name-row {
            font-size: 0.92rem;
            font-weight: 700;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .account-sub-row {
            font-size: 0.75rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 2px;
        }

        .account-coin-tag {
            font-weight: 700;
            color: #d97706;
        }

        .multi-thread-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 5px 14px;
            border-radius: 50px;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }

        /* 4. SLIDERS VÀ NÚT CHỌN NHANH (QUICK PILLS) */
        .slider-control-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 18px;
        }

        .slider-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            gap: 10px;
            flex-wrap: wrap;
        }

        .slider-label {
            font-size: 0.92rem;
            font-weight: 800;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .slider-value-badge {
            background: #4f46e5;
            color: #ffffff;
            font-size: 0.9rem;
            font-weight: 800;
            padding: 4px 14px;
            border-radius: 50px;
            box-shadow: 0 2px 6px rgba(79, 70, 229, 0.25);
            min-width: 65px;
            text-align: center;
        }

        .custom-range-slider {
            width: 100%;
            height: 8px;
            border-radius: 6px;
            background: #cbd5e1;
            outline: none;
            -webkit-appearance: none;
            appearance: none;
            cursor: pointer;
            transition: var(--transition);
        }

        .custom-range-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #4f46e5;
            border: 3px solid #ffffff;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
            cursor: pointer;
            transition: transform 0.15s ease;
        }

        .custom-range-slider::-webkit-slider-thumb:hover {
            transform: scale(1.18);
        }

        .custom-range-slider:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .quick-pills-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        .quick-pill-btn {
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 50px;
            cursor: pointer;
            transition: var(--transition);
            user-select: none;
        }

        .quick-pill-btn:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
            color: #1e293b;
        }

        .quick-pill-btn.active {
            background: #4f46e5;
            color: #ffffff;
            border-color: #4f46e5;
            box-shadow: 0 2px 6px rgba(79, 70, 229, 0.2);
        }

        /* 5. KHUNG LỌC GIÁ TIỀN & CLOUD */
        .filter-price-box {
            border: 1.5px dashed #cbd5e1;
            border-radius: 14px;
            padding: 16px 20px;
            background: #ffffff;
            margin-bottom: 20px;
            transition: var(--transition);
        }

        .filter-price-box.enabled {
            border-style: solid;
            border-color: #10b981;
            background: #f0fdf4;
        }

        .cloud-config-box {
            border: 1.5px solid #c7d2fe;
            border-radius: 14px;
            padding: 18px 20px;
            background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
            margin-bottom: 24px;
        }

        .cloud-config-box.active {
            border-color: #6366f1;
            box-shadow: 0 4px 16px rgba(99, 102, 241, 0.12);
        }

        /* NÚT LƯU */
        .btn-action-save {
            background: var(--gradient-primary);
            color: #ffffff;
            font-weight: 800;
            font-size: 1.05rem;
            border: none;
            padding: 14px 32px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.35);
            transition: var(--transition);
            width: 100%;
        }

        .btn-action-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(79, 70, 229, 0.45);
            color: #ffffff;
        }

        /* RESPONSIVE MOBILE */
        @media (max-width: 991.98px) {
            .app-sidebar {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                bottom: 0 !important;
                height: 100vh !important;
                width: 280px !important;
                max-width: 85vw !important;
                transform: translateX(-100%) !important;
                transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1) !important;
                z-index: 1060 !important;
                padding: 16px 14px 30px !important;
            }
            .app-sidebar.sidebar-open {
                transform: translateX(0) !important;
                box-shadow: 4px 0 30px rgba(0, 0, 0, 0.25) !important;
            }
            .app-main {
                margin-left: 0 !important;
                padding-top: 70px !important;
            }
            .content-container {
                padding: 18px 14px !important;
            }
        }

        @media (max-width: 576px) {
            .settings-hero {
                padding: 20px 16px !important;
                border-radius: 16px !important;
            }
            .hero-title {
                font-size: 1.35rem !important;
            }
            .config-card {
                padding: 18px 14px !important;
                border-radius: 16px !important;
            }
            .scope-selector-grid {
                grid-template-columns: 1fr 1fr !important;
                gap: 8px !important;
            }
            .scope-card-item {
                padding: 10px 12px !important;
                gap: 8px !important;
            }
            .scope-icon-wrap {
                width: 30px !important;
                height: 30px !important;
                font-size: 0.95rem !important;
            }
            .scope-title {
                font-size: 0.85rem !important;
            }
            .slider-control-card {
                padding: 14px 12px !important;
            }
            .slider-label {
                font-size: 0.85rem !important;
            }
            .quick-pill-btn {
                padding: 3px 9px !important;
                font-size: 0.72rem !important;
            }
            .btn-action-save {
                padding: 12px 20px !important;
                font-size: 0.95rem !important;
            }
        }
    </style>
</head>
<body>

    <!-- 1. THANH ĐIỀU HƯỚNG CỐ ĐỊNH TRÊN ĐẦU (HEADER) -->
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
            <!-- Số dư tài khoản -->
            <a href="/payments/deposit" class="header-balance-card" title="Nạp tiền vào tài khoản">
                <div class="balance-wallet-icon">
                    <i class="fa-solid fa-wallet"></i>
                </div>
                <div class="balance-text-group">
                    <span class="balance-title">Số dư</span>
                    <span class="balance-val"><?= format_currency($currentUser['balance']) ?></span>
                </div>
            </a>

            <!-- Avatar -->
            <a href="profile.php" class="user-profile-toggle" title="<?= htmlspecialchars($currentUser['name']) ?>">
                <img src="<?= htmlspecialchars($currentUser['avatar']) ?>" 
                     alt="Avatar" 
                     class="user-avatar-small"
                     onerror="this.onerror=null; this.src='assets/images/default-avatar.svg';">
            </a>
        </div>
    </header>

    <!-- 2. MENU SIDEBAR CỐ ĐỊNH TRÁI -->
    <aside class="app-sidebar" id="appSidebar">
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
            <li>
                <a href="token.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-fingerprint"></i></span>
                    <span class="sidebar-title">Access Token</span>
                </a>
            </li>
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
                <a href="token.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-robot"></i></span>
                    <span class="sidebar-title">Đồng bộ tài khoản</span>
                </a>
            </li>
            <li>
                <a href="/payments/deposit" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-circle-arrow-down text-success"></i></span>
                    <span class="sidebar-title">Nạp tiền</span>
                </a>
            </li>
            <li>
                <a href="/payments/withdraw" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-circle-arrow-up text-warning"></i></span>
                    <span class="sidebar-title">Rút tiền</span>
                </a>
            </li>
        </ul>

        <div class="sidebar-category">TIỆN ÍCH</div>
        <ul class="sidebar-nav-list">
            <li>
                <a href="profile.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-user"></i></span>
                    <span class="sidebar-title">Hồ sơ cá nhân</span>
                </a>
            </li>
            <li>
                <a href="support.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-headset"></i></span>
                    <span class="sidebar-title">Hỗ trợ</span>
                </a>
            </li>
            <?php if ($isAdmin): ?>
            <li>
                <a href="/admin/dashboard" class="sidebar-link text-danger fw-bold">
                    <span class="sidebar-icon text-danger"><i class="fa-solid fa-fw fa-shield-halved"></i></span>
                    <span class="sidebar-title">Admin Panel</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </aside>

    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeAppSidebar()"></div>

    <!-- 3. NỘI DUNG CHÍNH (APP MAIN) -->
    <main class="app-main">
        <div class="content-container">

            <!-- BREADCRUMB -->
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb mb-0" style="font-size: 0.85rem; font-weight: 600;">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted"><i class="fa-solid fa-house me-1"></i> Trang chủ</a></li>
                    <li class="breadcrumb-item active text-primary" aria-current="page">Cấu hình vận hành</li>
                </ol>
            </nav>

            <!-- HERO CARD -->
            <div class="settings-hero">
                <div class="hero-badge">
                    <i class="fa-solid fa-sliders"></i>
                    <span>TRUNG TÂM CẤU HÌNH TỰ ĐỘNG HÓA</span>
                </div>
                <h1 class="hero-title">Cấu Hình Vận Hành Tool & Đa Luồng</h1>
                <p class="hero-subtitle">
                    Thiết lập tham số chạy tự động, chọn tài khoản đa luồng, chọn mạng xã hội, kéo thanh trượt số lượng jobs, điều chỉnh delay an toàn và cấu hình treo ngầm trên Cloud VPS.
                </p>
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

            <!-- FORM CẤU HÌNH CHÍNH DUY NHẤT (FINTECH SINGLE PAGE) -->
            <form action="settings.php" method="POST" id="formBotSettings">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                <input type="hidden" name="action" value="save_bot_settings">
                <input type="hidden" name="target_scope" id="targetScopeInput" value="<?= htmlspecialchars($scopeParam) ?>">

                <div class="config-card">
                    <!-- 1. BỘ CHỌN NỀN TẢNG (CHUNG HOẶC RIÊNG TỪNG NỀN TẢNG) -->
                    <div class="config-section-title">
                        <div class="config-title-text">
                            <i class="fa-solid fa-layer-group text-primary"></i>
                            <span>1. Chọn Chế Độ Cấu Hình</span>
                        </div>
                        <span class="config-title-badge" id="activeScopeLabel">Đang chỉnh: <?= strtoupper($scopeParam) ?></span>
                    </div>

                    <div class="scope-selector-grid">
                        <!-- Chung toàn hệ thống -->
                        <div class="scope-card-item <?= ($scopeParam === 'general') ? 'active' : '' ?>" onclick="switchScope('general')">
                            <div class="scope-icon-wrap text-primary">
                                <i class="fa-solid fa-sliders"></i>
                            </div>
                            <div class="scope-text-wrap">
                                <div class="scope-title">Cấu hình chung</div>
                                <div class="scope-sub">Toàn hệ thống (Mặc định)</div>
                            </div>
                            <i class="fa-solid fa-circle-check scope-check-dot"></i>
                        </div>

                        <!-- Golike -->
                        <div class="scope-card-item <?= ($scopeParam === 'golike') ? 'active' : '' ?>" onclick="switchScope('golike')">
                            <div class="scope-icon-wrap">
                                <img src="<?= htmlspecialchars($golikeLogo) ?>" alt="Golike" style="width: 25px; height: 25px; border-radius: 50%; object-fit: cover;">
                            </div>
                            <div class="scope-text-wrap">
                                <div class="scope-title">Golike</div>
                                <div class="scope-sub">Cấu hình riêng Golike</div>
                            </div>
                            <i class="fa-solid fa-circle-check scope-check-dot"></i>
                        </div>

                        <!-- Tương Tác Chéo -->
                        <div class="scope-card-item <?= ($scopeParam === 'tuongtaccheo') ? 'active' : '' ?>" onclick="switchScope('tuongtaccheo')">
                            <div class="scope-icon-wrap text-success">
                                <i class="fa-solid fa-arrows-rotate"></i>
                            </div>
                            <div class="scope-text-wrap">
                                <div class="scope-title">Tương Tác Chéo</div>
                                <div class="scope-sub">Cấu hình riêng TTC</div>
                            </div>
                            <i class="fa-solid fa-circle-check scope-check-dot"></i>
                        </div>

                        <!-- Trao Đổi Sub -->
                        <div class="scope-card-item <?= ($scopeParam === 'traodoisub') ? 'active' : '' ?>" onclick="switchScope('traodoisub')">
                            <div class="scope-icon-wrap text-info">
                                <i class="fa-solid fa-bolt"></i>
                            </div>
                            <div class="scope-text-wrap">
                                <div class="scope-title">Trao Đổi Sub</div>
                                <div class="scope-sub">Cấu hình riêng TDS</div>
                            </div>
                            <i class="fa-solid fa-circle-check scope-check-dot"></i>
                        </div>
                    </div>

                    <!-- 2. CHỌN MẠNG XÃ HỘI (MXH) -->
                    <div class="config-section-title mt-4">
                        <div class="config-title-text">
                            <i class="fa-solid fa-share-nodes text-primary"></i>
                            <span>2. Chọn Nền Tảng Mạng Xã Hội (MXH)</span>
                        </div>
                        <span class="text-muted small">Tích chọn các MXH muốn nhận việc</span>
                    </div>

                    <?php 
                        $savedSocials = $activeConfig['social_platforms'] ?? ['all'];
                        $isAllSocial = in_array('all', $savedSocials);
                    ?>
                    <div class="social-chips-group">
                        <!-- Toàn bộ -->
                        <label class="social-chip <?= $isAllSocial ? 'active' : '' ?>" id="chipSocialAll" onclick="toggleSocialAll(this)">
                            <input type="checkbox" name="social_platforms[]" value="all" <?= $isAllSocial ? 'checked' : '' ?>>
                            <i class="fa-solid fa-asterisk"></i>
                            <span>Toàn bộ MXH</span>
                            <i class="fa-solid fa-circle-check social-chip-check"></i>
                        </label>

                        <!-- Instagram -->
                        <label class="social-chip <?= in_array('instagram', $savedSocials) ? 'active' : '' ?>" onclick="toggleSocialSingle(this)">
                            <input type="checkbox" name="social_platforms[]" value="instagram" <?= in_array('instagram', $savedSocials) ? 'checked' : '' ?>>
                            <i class="fa-brands fa-instagram text-danger"></i>
                            <span>Instagram (IG)</span>
                            <i class="fa-solid fa-circle-check social-chip-check"></i>
                        </label>

                        <!-- Threads -->
                        <label class="social-chip <?= in_array('threads', $savedSocials) ? 'active' : '' ?>" onclick="toggleSocialSingle(this)">
                            <input type="checkbox" name="social_platforms[]" value="threads" <?= in_array('threads', $savedSocials) ? 'checked' : '' ?>>
                            <i class="fa-brands fa-threads text-dark"></i>
                            <span>Threads</span>
                            <i class="fa-solid fa-circle-check social-chip-check"></i>
                        </label>

                        <!-- Pinterest -->
                        <label class="social-chip <?= in_array('pinterest', $savedSocials) ? 'active' : '' ?>" onclick="toggleSocialSingle(this)">
                            <input type="checkbox" name="social_platforms[]" value="pinterest" <?= in_array('pinterest', $savedSocials) ? 'checked' : '' ?>>
                            <i class="fa-brands fa-pinterest text-danger"></i>
                            <span>Pinterest</span>
                            <i class="fa-solid fa-circle-check social-chip-check"></i>
                        </label>

                        <!-- TikTok (Mở rộng) -->
                        <label class="social-chip <?= in_array('tiktok', $savedSocials) ? 'active' : '' ?>" onclick="toggleSocialSingle(this)">
                            <input type="checkbox" name="social_platforms[]" value="tiktok" <?= in_array('tiktok', $savedSocials) ? 'checked' : '' ?>>
                            <i class="fa-brands fa-tiktok text-dark"></i>
                            <span>TikTok</span>
                            <i class="fa-solid fa-circle-check social-chip-check"></i>
                        </label>
                    </div>

                    <!-- 3. CHỌN TÀI KHOẢN CHẠY ĐA LUỒNG -->
                    <div class="config-section-title mt-4">
                        <div class="config-title-text">
                            <i class="fa-solid fa-users-gear text-primary"></i>
                            <span>3. Chọn Tài Khoản Vận Hành (Đa Luồng Multi-Account)</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="multi-thread-badge d-none" id="multiThreadBadge">
                                <i class="fa-solid fa-bolt"></i> Chạy Đa Luồng (<span id="selectedAccCount">0</span> acc)
                            </span>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="selectAllAccounts()">Chọn tất cả</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="deselectAllAccounts()">Bỏ chọn</button>
                        </div>
                    </div>

                    <?php if (empty($tokens)): ?>
                        <div class="p-4 text-center bg-light rounded-3 border mb-4">
                            <i class="fa-solid fa-user-slash text-muted fs-2 mb-2 d-block"></i>
                            <div class="fw-bold text-dark mb-1">Chưa có tài khoản nào được liên kết</div>
                            <p class="text-muted small mb-3">Vui lòng thêm mã Token tại trang Quản lý Token để chọn tài khoản chạy tự động.</p>
                            <a href="token.php" class="btn btn-sm btn-primary rounded-pill px-4 fw-bold">
                                <i class="fa-solid fa-key me-1"></i> Thêm Token Ngay
                            </a>
                        </div>
                    <?php else: ?>
                        <?php $savedAccs = $activeConfig['selected_accounts'] ?? []; ?>
                        <div class="accounts-grid-wrapper mb-4">
                            <?php foreach ($tokens as $t): 
                                $isSelected = in_array((int)$t['id'], $savedAccs);
                                $pCode = strtolower($t['platform'] ?? 'golike');
                            ?>
                            <div class="account-select-card <?= $isSelected ? 'active' : '' ?>" data-plat="<?= htmlspecialchars($pCode) ?>" onclick="toggleAccountCard(this)">
                                <input type="checkbox" name="selected_accounts[]" value="<?= $t['id'] ?>" class="account-checkbox d-none" <?= $isSelected ? 'checked' : '' ?>>
                                <div class="account-avatar-box">
                                    <?php if ($pCode === 'golike'): ?>
                                        <img src="<?= htmlspecialchars($golikeLogo) ?>" alt="Golike" style="width: 25px; height: 25px; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <i class="fa-solid fa-user"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="account-info-box">
                                    <div class="account-name-row"><?= htmlspecialchars($t['name']) ?></div>
                                    <div class="account-sub-row">
                                        <span class="badge bg-secondary-subtle text-dark border px-2 py-0" style="font-size: 0.68rem;"><?= strtoupper($pCode) ?></span>
                                        <span>ID: <?= htmlspecialchars($t['account_id']) ?></span>
                                        <span class="account-coin-tag ms-auto"><?= number_format($t['coin'], 0, ',', '.') ?> xu</span>
                                    </div>
                                </div>
                                <i class="fa-solid fa-circle-check scope-check-dot"></i>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- 4. THAM SỐ VẬN HÀNH (SLIDERS & QUICK PILLS) -->
                    <div class="config-section-title mt-4">
                        <div class="config-title-text">
                            <i class="fa-solid fa-sliders text-primary"></i>
                            <span>4. Thiết Lập Tham Số Nhiệm Vụ & Thời Gian</span>
                        </div>
                    </div>

                    <!-- 4.1 SỐ LƯỢNG JOBS -->
                    <div class="slider-control-card">
                        <div class="slider-header-row">
                            <label class="slider-label" for="sliderJobLimit">
                                <i class="fa-solid fa-list-check text-primary"></i>
                                <span>Số lượng Jobs mục tiêu:</span>
                            </label>
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="checkJobUnlimited" name="job_unlimited" value="1" <?= !empty($activeConfig['job_unlimited']) ? 'checked' : '' ?> onchange="toggleJobUnlimited(this.checked)">
                                    <label class="form-check-label fw-bold text-muted small" for="checkJobUnlimited">Chạy vô hạn ($\infty$)</label>
                                </div>
                                <span class="slider-value-badge" id="badgeJobLimit"><?= !empty($activeConfig['job_unlimited']) ? 'Vô hạn' : ($activeConfig['job_limit'] ?? 10) . ' jobs' ?></span>
                            </div>
                        </div>
                        <input type="range" class="custom-range-slider" id="sliderJobLimit" name="job_limit" min="1" max="300" step="1" value="<?= (int)($activeConfig['job_limit'] ?? 10) ?>" <?= !empty($activeConfig['job_unlimited']) ? 'disabled' : '' ?> oninput="updateJobLimit(this.value)">
                        
                        <div class="quick-pills-row">
                            <span class="text-muted small fw-bold me-1">Chọn nhanh:</span>
                            <button type="button" class="quick-pill-btn" onclick="setJobLimit(3)">3 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setJobLimit(5)">5 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setJobLimit(10)">10 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setJobLimit(20)">20 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setJobLimit(50)">50 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setJobLimit(100)">100 jobs</button>
                        </div>
                    </div>

                    <!-- 4.2 THỜI GIAN DELAY (NGHỈ GIỮA CÁC JOBS) -->
                    <div class="slider-control-card">
                        <div class="slider-header-row">
                            <label class="slider-label" for="sliderDelay">
                                <i class="fa-solid fa-stopwatch text-warning"></i>
                                <span>Thời gian Delay giữa các Jobs:</span>
                            </label>
                            <span class="slider-value-badge bg-warning text-dark" id="badgeDelay"><?= (int)($activeConfig['delay_seconds'] ?? 15) ?> giây</span>
                        </div>
                        <input type="range" class="custom-range-slider" id="sliderDelay" name="delay_seconds" min="3" max="120" step="1" value="<?= (int)($activeConfig['delay_seconds'] ?? 15) ?>" oninput="updateDelay(this.value)">
                        
                        <div class="quick-pills-row">
                            <span class="text-muted small fw-bold me-1">Chọn nhanh:</span>
                            <button type="button" class="quick-pill-btn" onclick="setDelay(5)">5s</button>
                            <button type="button" class="quick-pill-btn" onclick="setDelay(10)">10s</button>
                            <button type="button" class="quick-pill-btn border-primary text-primary active" onclick="setDelay(15)">15s (Khuyên dùng)</button>
                            <button type="button" class="quick-pill-btn" onclick="setDelay(20)">20s</button>
                            <button type="button" class="quick-pill-btn border-success text-success" onclick="setDelay(30)">30s (An toàn)</button>
                            <button type="button" class="quick-pill-btn" onclick="setDelay(60)">60s</button>
                        </div>
                        <div class="form-text text-muted mt-2 small">
                            <i class="fa-solid fa-lightbulb text-warning me-1"></i> Khuyên dùng <strong>15s</strong> hoặc <strong>30s</strong> để tài khoản mạng xã hội không bị checkpoint hoặc chặn tính năng.
                        </div>
                    </div>

                    <!-- 4.3 SAU BAO NHIÊU JOBS THÌ ĐỔI ACC -->
                    <div class="slider-control-card">
                        <div class="slider-header-row">
                            <label class="slider-label" for="sliderSwitchAcc">
                                <i class="fa-solid fa-arrows-rotate text-info"></i>
                                <span>Sau bao nhiêu Jobs thì đổi tài khoản (Xoay vòng):</span>
                            </label>
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="checkSwitchNever" name="switch_never" value="1" <?= !empty($activeConfig['switch_never']) ? 'checked' : '' ?> onchange="toggleSwitchNever(this.checked)">
                                    <label class="form-check-label fw-bold text-muted small" for="checkSwitchNever">Không đổi acc</label>
                                </div>
                                <span class="slider-value-badge bg-info text-white" id="badgeSwitchAcc"><?= !empty($activeConfig['switch_never']) ? 'Không đổi' : ($activeConfig['switch_account_jobs'] ?? 5) . ' jobs' ?></span>
                            </div>
                        </div>
                        <input type="range" class="custom-range-slider" id="sliderSwitchAcc" name="switch_account_jobs" min="1" max="50" step="1" value="<?= (int)($activeConfig['switch_account_jobs'] ?? 5) ?>" <?= !empty($activeConfig['switch_never']) ? 'disabled' : '' ?> oninput="updateSwitchAcc(this.value)">
                        
                        <div class="quick-pills-row">
                            <span class="text-muted small fw-bold me-1">Chọn nhanh:</span>
                            <button type="button" class="quick-pill-btn" onclick="setSwitchAcc(3)">3 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setSwitchAcc(5)">5 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setSwitchAcc(10)">10 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setSwitchAcc(15)">15 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setSwitchAcc(20)">20 jobs</button>
                        </div>
                    </div>

                    <!-- 5. LỌC ĐƠN GIÁ TỐI THIỂU (MIN REWARD FILTER) -->
                    <?php $priceFilterOn = !empty($activeConfig['price_filter_enabled']); ?>
                    <div class="filter-price-box <?= $priceFilterOn ? 'enabled' : '' ?>" id="filterPriceBox">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="checkPriceFilter" name="price_filter_enabled" value="1" <?= $priceFilterOn ? 'checked' : '' ?> onchange="togglePriceFilter(this.checked)">
                                </div>
                                <label class="fw-bold text-dark mb-0 cursor-pointer" for="checkPriceFilter">
                                    <i class="fa-solid fa-coins text-warning me-1"></i> Bật Lọc Nhiệm Vụ Theo Đơn Giá Tối Thiểu
                                </label>
                            </div>
                            <span class="badge bg-light text-secondary border px-3 py-1" style="font-size: 0.75rem;">Chỉ nhận job có tiền thưởng cao hơn</span>
                        </div>

                        <div id="priceFilterContent" class="<?= $priceFilterOn ? '' : 'd-none' ?> pt-2">
                            <div class="row g-2 align-items-center">
                                <div class="col-sm-5">
                                    <div class="input-group">
                                        <span class="input-group-text bg-white fw-bold text-muted">>=</span>
                                        <input type="number" class="form-control fw-bold text-primary" id="inputMinPrice" name="min_price" value="<?= (int)($activeConfig['min_price'] ?? 35) ?>" min="0" step="5" placeholder="Nhập giá tối thiểu">
                                        <span class="input-group-text bg-white text-muted">VNĐ / Xu</span>
                                    </div>
                                </div>
                                <div class="col-sm-7">
                                    <div class="quick-pills-row mt-0">
                                        <span class="text-muted small fw-bold">Chọn nhanh:</span>
                                        <button type="button" class="quick-pill-btn" onclick="setMinPrice(25)">25đ</button>
                                        <button type="button" class="quick-pill-btn" onclick="setMinPrice(35)">35đ</button>
                                        <button type="button" class="quick-pill-btn" onclick="setMinPrice(50)">50đ</button>
                                        <button type="button" class="quick-pill-btn" onclick="setMinPrice(70)">70đ</button>
                                        <button type="button" class="quick-pill-btn" onclick="setMinPrice(100)">100đ</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 6. CẤU HÌNH CHO MÁY CHỦ CLOUD VPS -->
                    <?php $cloudCustomOn = !empty($activeConfig['cloud_custom_enabled']); ?>
                    <div class="cloud-config-box <?= $cloudCustomOn ? 'active' : '' ?>" id="cloudConfigBox">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="checkCloudCustom" name="cloud_custom_enabled" value="1" <?= $cloudCustomOn ? 'checked' : '' ?> onchange="toggleCloudConfig(this.checked)">
                                </div>
                                <div>
                                    <label class="fw-bold text-dark mb-0 cursor-pointer" for="checkCloudCustom">
                                        <i class="fa-solid fa-cloud text-primary me-1"></i> Bật Cấu Hình Riêng Khi Treo Trên Cloud VPS
                                    </label>
                                    <div class="text-muted small">Nếu không tích, hệ thống sẽ tự động dùng cấu hình mặc định ở trên để chạy ngầm.</div>
                                </div>
                            </div>
                            <span class="badge <?= $cloudCustomOn ? 'bg-primary text-white' : 'bg-secondary-subtle text-muted' ?> rounded-pill px-3 py-1" id="cloudStatusBadge">
                                <?= $cloudCustomOn ? 'Đang cấu hình riêng' : 'Dùng chung mặc định' ?>
                            </span>
                        </div>
                    </div>

                    <!-- 7. TÍNH NĂNG BẢO VỆ & AN TOÀN NÂNG CAO -->
                    <div class="p-3 bg-light rounded-3 border mb-4">
                        <div class="fw-bold text-dark mb-2">
                            <i class="fa-solid fa-shield-halved text-success me-1"></i> Tính Năng An Toàn & Bảo Vệ Tài Khoản Tự Động
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="checkAutoSkip" name="auto_skip_error" value="1" <?= !empty($activeConfig['auto_skip_error']) ? 'checked' : '' ?>>
                                    <label class="form-check-label text-dark small" for="checkAutoSkip">
                                        <strong>Tự động bỏ qua</strong> job bị lỗi hoặc hết hạn sau 3 lần thử lại
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="checkAntiBlock" name="anti_block_safe" value="1" <?= !empty($activeConfig['anti_block_safe']) ? 'checked' : '' ?>>
                                    <label class="form-check-label text-dark small" for="checkAntiBlock">
                                        <strong>Chống Checkpoint:</strong> Tạm nghỉ 5 phút nếu nghi ngờ bị nhả like/follow
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-12 mt-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="checkNotifyTele" name="notify_telegram" value="1" <?= !empty($activeConfig['notify_telegram']) ? 'checked' : '' ?>>
                                    <label class="form-check-label text-dark small" for="checkNotifyTele">
                                        <strong>Thông báo Telegram Bot:</strong> Báo tin khi hoàn thành đủ số jobs hoặc acc cần kiểm tra
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- NÚT LƯU CẤU HÌNH -->
                    <div class="mt-4 pt-1">
                        <button type="submit" class="btn-action-save">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Lưu Toàn Bộ Cấu Hình Ngay
                        </button>
                    </div>
                </div>
            </form>

        </div>
    </main>

    <!-- JAVASCRIPT ĐIỀU KHIỂN THÔNG MINH -->
    <script>
        // Dữ liệu cấu hình các scope lưu sẵn từ Server
        const ALL_CONFIGS = <?= json_encode($allConfigs, JSON_UNESCAPED_UNICODE) ?>;
        const DEFAULT_CONFIG = <?= json_encode($defaultGeneralConfig, JSON_UNESCAPED_UNICODE) ?>;

        // 1. Chuyển đổi Scope (Chung, Golike, TTC, TDS)
        function switchScope(scope) {
            const input = document.getElementById('targetScopeInput');
            if (input) input.value = scope;

            // Highlight card
            document.querySelectorAll('.scope-card-item').forEach(card => {
                card.classList.remove('active');
            });
            event.currentTarget.classList.add('active');

            const label = document.getElementById('activeScopeLabel');
            if (label) label.textContent = 'Đang chỉnh: ' + scope.toUpperCase();

            // Nếu người dùng muốn chuyển qua scope khác, tự động load giá trị của scope đó
            const configKey = 'bot_config_' + scope;
            const config = ALL_CONFIGS[configKey] || ALL_CONFIGS['bot_config_general'] || DEFAULT_CONFIG;
            applyConfigToForm(config);

            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'info',
                title: 'Đã chuyển sang cấu hình: ' + scope.toUpperCase(),
                showConfirmButton: false,
                timer: 1500
            });
        }

        // Áp dụng bộ cấu hình vào Form
        function applyConfigToForm(cfg) {
            // Slider Jobs
            if (cfg.job_unlimited) {
                document.getElementById('checkJobUnlimited').checked = true;
                toggleJobUnlimited(true);
            } else {
                document.getElementById('checkJobUnlimited').checked = false;
                toggleJobUnlimited(false);
                setJobLimit(cfg.job_limit || 10);
            }

            // Slider Delay
            setDelay(cfg.delay_seconds || 15);

            // Slider Switch Acc
            if (cfg.switch_never) {
                document.getElementById('checkSwitchNever').checked = true;
                toggleSwitchNever(true);
            } else {
                document.getElementById('checkSwitchNever').checked = false;
                toggleSwitchNever(false);
                setSwitchAcc(cfg.switch_account_jobs || 5);
            }

            // Lọc giá
            const priceOn = !!cfg.price_filter_enabled;
            document.getElementById('checkPriceFilter').checked = priceOn;
            togglePriceFilter(priceOn);
            if (cfg.min_price) {
                setMinPrice(cfg.min_price);
            }

            // Cloud custom
            const cloudOn = !!cfg.cloud_custom_enabled;
            document.getElementById('checkCloudCustom').checked = cloudOn;
            toggleCloudConfig(cloudOn);

            // Accounts selection
            const accList = cfg.selected_accounts || [];
            document.querySelectorAll('.account-select-card').forEach(card => {
                const chk = card.querySelector('.account-checkbox');
                if (chk) {
                    const isCheck = accList.includes(parseInt(chk.value));
                    chk.checked = isCheck;
                    if (isCheck) card.classList.add('active');
                    else card.classList.remove('active');
                }
            });
            updateMultiThreadStatus();
        }

        // 2. Điều khiển Chọn MXH
        function toggleSocialAll(chip) {
            const chk = chip.querySelector('input');
            chk.checked = !chk.checked;
            chip.classList.toggle('active', chk.checked);

            if (chk.checked) {
                // Bỏ chọn các nút lẻ
                document.querySelectorAll('.social-chips-group .social-chip').forEach(c => {
                    if (c !== chip) {
                        c.classList.remove('active');
                        c.querySelector('input').checked = false;
                    }
                });
            }
        }

        function toggleSocialSingle(chip) {
            const chk = chip.querySelector('input');
            chk.checked = !chk.checked;
            chip.classList.toggle('active', chk.checked);

            // Bỏ chọn nút 'Tất cả'
            const allChip = document.getElementById('chipSocialAll');
            if (allChip) {
                allChip.classList.remove('active');
                allChip.querySelector('input').checked = false;
            }
        }

        // 3. Điều khiển Chọn Tài khoản đa luồng
        function toggleAccountCard(card) {
            const chk = card.querySelector('.account-checkbox');
            if (chk) {
                chk.checked = !chk.checked;
                card.classList.toggle('active', chk.checked);
                updateMultiThreadStatus();
            }
        }

        function selectAllAccounts() {
            document.querySelectorAll('.account-select-card').forEach(card => {
                const chk = card.querySelector('.account-checkbox');
                if (chk) {
                    chk.checked = true;
                    card.classList.add('active');
                }
            });
            updateMultiThreadStatus();
        }

        function deselectAllAccounts() {
            document.querySelectorAll('.account-select-card').forEach(card => {
                const chk = card.querySelector('.account-checkbox');
                if (chk) {
                    chk.checked = false;
                    card.classList.remove('active');
                }
            });
            updateMultiThreadStatus();
        }

        function updateMultiThreadStatus() {
            const checked = document.querySelectorAll('.account-checkbox:checked').length;
            const badge = document.getElementById('multiThreadBadge');
            const countEl = document.getElementById('selectedAccCount');
            if (countEl) countEl.textContent = checked;

            if (badge) {
                if (checked >= 2) {
                    badge.classList.remove('d-none');
                } else {
                    badge.classList.add('d-none');
                }
            }
        }

        // 4. Sliders & Quick Buttons Handlers
        function updateJobLimit(val) {
            document.getElementById('badgeJobLimit').textContent = val + ' jobs';
        }

        function setJobLimit(val) {
            const slider = document.getElementById('sliderJobLimit');
            if (slider) {
                slider.value = val;
                updateJobLimit(val);
            }
            document.getElementById('checkJobUnlimited').checked = false;
            toggleJobUnlimited(false);
        }

        function toggleJobUnlimited(isUnlimited) {
            const slider = document.getElementById('sliderJobLimit');
            const badge = document.getElementById('badgeJobLimit');
            if (isUnlimited) {
                slider.disabled = true;
                badge.textContent = 'Vô hạn';
            } else {
                slider.disabled = false;
                badge.textContent = slider.value + ' jobs';
            }
        }

        function updateDelay(val) {
            document.getElementById('badgeDelay').textContent = val + ' giây';
        }

        function setDelay(val) {
            const slider = document.getElementById('sliderDelay');
            if (slider) {
                slider.value = val;
                updateDelay(val);
            }
        }

        function updateSwitchAcc(val) {
            document.getElementById('badgeSwitchAcc').textContent = val + ' jobs';
        }

        function setSwitchAcc(val) {
            const slider = document.getElementById('sliderSwitchAcc');
            if (slider) {
                slider.value = val;
                updateSwitchAcc(val);
            }
            document.getElementById('checkSwitchNever').checked = false;
            toggleSwitchNever(false);
        }

        function toggleSwitchNever(isNever) {
            const slider = document.getElementById('sliderSwitchAcc');
            const badge = document.getElementById('badgeSwitchAcc');
            if (isNever) {
                slider.disabled = true;
                badge.textContent = 'Không đổi';
            } else {
                slider.disabled = false;
                badge.textContent = slider.value + ' jobs';
            }
        }

        function togglePriceFilter(isEnabled) {
            const box = document.getElementById('filterPriceBox');
            const content = document.getElementById('priceFilterContent');
            if (box) box.classList.toggle('enabled', isEnabled);
            if (content) content.classList.toggle('d-none', !isEnabled);
        }

        function setMinPrice(val) {
            const input = document.getElementById('inputMinPrice');
            if (input) input.value = val;
        }

        function toggleCloudConfig(isEnabled) {
            const box = document.getElementById('cloudConfigBox');
            const badge = document.getElementById('cloudStatusBadge');
            if (box) box.classList.toggle('active', isEnabled);
            if (badge) {
                badge.className = isEnabled ? 'badge bg-primary text-white rounded-pill px-3 py-1' : 'badge bg-secondary-subtle text-muted rounded-pill px-3 py-1';
                badge.textContent = isEnabled ? 'Đang cấu hình riêng' : 'Dùng chung mặc định';
            }
        }

        // 5. Sidebar Toggle trên Mobile
        function toggleAppSidebar(e) {
            if (e) e.stopPropagation();
            const sb = document.getElementById('appSidebar');
            const bd = document.getElementById('sidebarBackdrop');
            if (sb && bd) {
                sb.classList.toggle('sidebar-open');
                bd.classList.toggle('sidebar-open');
            }
        }

        function closeAppSidebar() {
            const sb = document.getElementById('appSidebar');
            const bd = document.getElementById('sidebarBackdrop');
            if (sb) sb.classList.remove('sidebar-open');
            if (bd) bd.classList.remove('sidebar-open');
        }

        // Khởi tạo trạng thái đa luồng ban đầu
        document.addEventListener('DOMContentLoaded', function() {
            updateMultiThreadStatus();
        });
    </script>
</body>
</html>
