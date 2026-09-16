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
$currentUser = $stmt->fetch();

if (!$currentUser) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$isAdmin = ($currentUser['role'] === 'Admin');

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

// Hàm kiểm tra nền tảng tài khoản có khớp với scope đang chọn không
function is_platform_matching_scope(string $plat, string $scope): bool {
    $plat = strtolower(trim($plat));
    $scope = strtolower(trim($scope));
    if ($scope === 'general' || $scope === 'all' || $scope === 'cloud' || empty($scope)) {
        return true;
    }
    if ($scope === 'golike') {
        return ($plat === 'golike' || $plat === 'go_like' || strpos($plat, 'golike') !== false);
    }
    if ($scope === 'tuongtaccheo' || $scope === 'ttc') {
        return ($plat === 'tuongtaccheo' || $plat === 'ttc' || $plat === 'tuong_tac_cheo' || strpos($plat, 'ttc') !== false || strpos($plat, 'tuongtac') !== false);
    }
    if ($scope === 'traodoisub' || $scope === 'tds') {
        return ($plat === 'traodoisub' || $plat === 'tds' || $plat === 'trao_doi_sub' || strpos($plat, 'tds') !== false || strpos($plat, 'traodoi') !== false);
    }
    return ($plat === $scope);
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
    $stmtTokens->execute([$currentUser['uuid']]);
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

        $cloudCustomEnabled = isset($_POST['cloud_custom_enabled']) ? 1 : 0;

        // Xử lý tài khoản đã chọn (Lọc chỉ giữ tài khoản khớp scope nếu chọn scope riêng)
        $selectedAccs = array_map('intval', $_POST['selected_accounts'] ?? []);
        if ($scope !== 'general' && $scope !== 'cloud') {
            $validScopeAccs = [];
            foreach ($tokens as $t) {
                $tp = strtolower($t['platform'] ?? 'golike');
                if (is_platform_matching_scope($tp, $scope)) {
                    $validScopeAccs[] = (int)$t['id'];
                }
            }
            $selectedAccs = array_values(array_intersect($selectedAccs, $validScopeAccs));
        }

        // Tự động chọn acc đầu tiên phù hợp với scope nếu để trống
        if (empty($selectedAccs) && !empty($tokens)) {
            $firstPlatAcc = null;
            foreach ($tokens as $t) {
                $tp = strtolower($t['platform'] ?? 'golike');
                if (is_platform_matching_scope($tp, $scope)) {
                    $firstPlatAcc = (int)$t['id'];
                    break;
                }
            }
            $selectedAccs = $firstPlatAcc ? [$firstPlatAcc] : [(int)$tokens[0]['id']];
        }

        $cloudSelectedAccs = array_map('intval', $_POST['cloud_selected_accounts'] ?? []);
        if ($scope !== 'general' && $scope !== 'cloud') {
            $validCloudScopeAccs = [];
            foreach ($tokens as $t) {
                $tp = strtolower($t['platform'] ?? 'golike');
                if (is_platform_matching_scope($tp, $scope)) {
                    $validCloudScopeAccs[] = (int)$t['id'];
                }
            }
            $cloudSelectedAccs = array_values(array_intersect($cloudSelectedAccs, $validCloudScopeAccs));
        }
        if (empty($cloudSelectedAccs) && !empty($tokens)) {
            $firstPlatAcc = null;
            foreach ($tokens as $t) {
                $tp = strtolower($t['platform'] ?? 'golike');
                if (is_platform_matching_scope($tp, $scope)) {
                    $firstPlatAcc = (int)$t['id'];
                    break;
                }
            }
            $cloudSelectedAccs = $firstPlatAcc ? [$firstPlatAcc] : [(int)$tokens[0]['id']];
        }

        // Cấu hình chuyên biệt cho Cloud VPS
        $cloudSettings = [
            'social_platforms'     => $_POST['cloud_social_platforms'] ?? ['all'],
            'selected_accounts'    => $cloudSelectedAccs,
            'job_limit'            => max(1, (int)($_POST['cloud_job_limit'] ?? 10)),
            'job_unlimited'        => isset($_POST['cloud_job_unlimited']) ? 1 : 0,
            'delay_seconds'        => max(1, (int)($_POST['cloud_delay_seconds'] ?? 15)),
            'switch_account_jobs'  => max(1, (int)($_POST['cloud_switch_account_jobs'] ?? 5)),
            'switch_never'         => isset($_POST['cloud_switch_never']) ? 1 : 0,
            'price_filter_enabled' => isset($_POST['cloud_price_filter_enabled']) ? 1 : 0,
            'min_price'            => max(0, (int)($_POST['cloud_min_price'] ?? 35)),
            'auto_skip_error'      => isset($_POST['cloud_auto_skip_error']) ? 1 : 0,
            'anti_block_safe'      => isset($_POST['cloud_anti_block_safe']) ? 1 : 0,
        ];

        // Cấu hình thông thường
        $configData = [
            'scope'                => $scope,
            'social_platforms'     => $_POST['social_platforms'] ?? ['all'],
            'selected_accounts'    => $selectedAccs,
            'job_limit'            => max(1, (int)($_POST['job_limit'] ?? 10)),
            'job_unlimited'        => isset($_POST['job_unlimited']) ? 1 : 0,
            'delay_seconds'        => max(1, (int)($_POST['delay_seconds'] ?? 15)),
            'switch_account_jobs'  => max(1, (int)($_POST['switch_account_jobs'] ?? 5)),
            'switch_never'         => isset($_POST['switch_never']) ? 1 : 0,
            'price_filter_enabled' => isset($_POST['price_filter_enabled']) ? 1 : 0,
            'min_price'            => max(0, (int)($_POST['min_price'] ?? 35)),
            'cloud_custom_enabled' => $cloudCustomEnabled,
            'cloud_settings'       => $cloudSettings,
            'auto_skip_error'      => isset($_POST['auto_skip_error']) ? 1 : 0,
            'anti_block_safe'      => isset($_POST['anti_block_safe']) ? 1 : 0,
            'updated_at'           => date('Y-m-d H:i:s')
        ];

        $settingKey = 'bot_config_' . $scope;
        $settingDesc = 'Cấu hình vận hành ' . ($scope === 'general' ? 'chung toàn hệ thống' : "nền tảng {$scope}");

        try {
            save_user_setting($pdo, $currentUser['uuid'], $settingKey, json_encode($configData, JSON_UNESCAPED_UNICODE), 'bot_config', $settingDesc);

            // Đồng bộ sang cấu hình riêng cho cloud nếu có bật
            if ($cloudCustomEnabled) {
                $cloudData = array_merge($cloudSettings, [
                    'scope'                => 'cloud',
                    'cloud_custom_enabled' => 1,
                    'updated_at'           => date('Y-m-d H:i:s')
                ]);
                save_user_setting($pdo, $currentUser['uuid'], 'bot_config_cloud', json_encode($cloudData, JSON_UNESCAPED_UNICODE), 'bot_config', 'Cấu hình vận hành riêng cho Cloud VPS');
            }

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
    $stmtSets->execute([$currentUser['uuid']]);
    while ($r = $stmtSets->fetch()) {
        $decoded = json_decode($r['setting_value'], true);
        if (is_array($decoded)) {
            $allConfigs[$r['setting_key']] = $decoded;
        }
    }
} catch (Exception $e) {
    //
}

$scopeParam = strtolower(trim($_GET['scope'] ?? 'general'));
if (!in_array($scopeParam, ['general', 'golike', 'tuongtaccheo', 'traodoisub', 'cloud'])) {
    $scopeParam = 'general';
}

// Lấy acc đầu tiên phù hợp với scope hiện tại
$defaultFirstAcc = [];
if (!empty($tokens)) {
    foreach ($tokens as $t) {
        $tp = strtolower($t['platform'] ?? 'golike');
        if (is_platform_matching_scope($tp, $scopeParam)) {
            $defaultFirstAcc = [(int)$t['id']];
            break;
        }
    }
    if (empty($defaultFirstAcc)) {
        $defaultFirstAcc = [(int)$tokens[0]['id']];
    }
}

$defaultGeneralConfig = [
    'scope'                => 'general',
    'social_platforms'     => ['all'],
    'selected_accounts'    => $defaultFirstAcc,
    'job_limit'            => 10,
    'job_unlimited'        => 0,
    'delay_seconds'        => 15,
    'switch_account_jobs'  => 5,
    'switch_never'         => 0,
    'price_filter_enabled' => 0,
    'min_price'            => 35,
    'cloud_custom_enabled' => 0,
    'cloud_settings'       => [
        'social_platforms'     => ['all'],
        'selected_accounts'    => $defaultFirstAcc,
        'job_limit'            => 10,
        'job_unlimited'        => 0,
        'delay_seconds'        => 15,
        'switch_account_jobs'  => 5,
        'switch_never'         => 0,
        'price_filter_enabled' => 0,
        'min_price'            => 35,
        'auto_skip_error'      => 1,
        'anti_block_safe'      => 1
    ],
    'auto_skip_error'      => 1,
    'anti_block_safe'      => 1
];

$currentScopeKey = 'bot_config_' . $scopeParam;
$activeConfig = $allConfigs[$currentScopeKey] ?? ($allConfigs['bot_config_general'] ?? $defaultGeneralConfig);

if (!empty($activeConfig['selected_accounts']) && $scopeParam !== 'general' && $scopeParam !== 'cloud') {
    $validIds = [];
    foreach ($tokens as $t) {
        $tp = strtolower($t['platform'] ?? 'golike');
        if (is_platform_matching_scope($tp, $scopeParam)) {
            $validIds[] = (int)$t['id'];
        }
    }
    $filteredAccs = array_values(array_intersect($activeConfig['selected_accounts'], $validIds));
    $activeConfig['selected_accounts'] = !empty($filteredAccs) ? $filteredAccs : $defaultFirstAcc;
} elseif (empty($activeConfig['selected_accounts']) && !empty($tokens)) {
    $activeConfig['selected_accounts'] = $defaultFirstAcc;
}

$cloudSaved = $activeConfig['cloud_settings'] ?? ($allConfigs['bot_config_cloud'] ?? $defaultGeneralConfig['cloud_settings']);
if (!empty($cloudSaved['selected_accounts']) && $scopeParam !== 'general' && $scopeParam !== 'cloud') {
    $validIdsCloud = [];
    foreach ($tokens as $t) {
        $tp = strtolower($t['platform'] ?? 'golike');
        if (is_platform_matching_scope($tp, $scopeParam)) {
            $validIdsCloud[] = (int)$t['id'];
        }
    }
    $filteredCloudAccs = array_values(array_intersect($cloudSaved['selected_accounts'], $validIdsCloud));
    $cloudSaved['selected_accounts'] = !empty($filteredCloudAccs) ? $filteredCloudAccs : $defaultFirstAcc;
} elseif (empty($cloudSaved['selected_accounts']) && !empty($tokens)) {
    $cloudSaved['selected_accounts'] = $defaultFirstAcc;
}

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

        /* ==========================================================
         * 1. HEADER CỐ ĐỊNH CHUẨN 1:1 THEO INDEX.PHP
         * ========================================================== */
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
            from { opacity: 0; transform: translateY(-8px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
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
         * 2. SIDEBAR CHUẨN 1:1 THEO INDEX.PHP
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

        .app-sidebar::-webkit-scrollbar { width: 5px; }
        .app-sidebar::-webkit-scrollbar-track { background: transparent; }
        .app-sidebar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        .app-sidebar::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }

        body.sidebar-collapsed .app-sidebar {
            transform: translateX(-100%);
        }
        body.sidebar-collapsed .app-main {
            margin-left: 0 !important;
            width: 100% !important;
        }

        .sidebar-mobile-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .btn-close-sidebar {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: #64748b;
            cursor: pointer;
            padding: 4px;
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

        /* ==========================================================
         * 3. KHU VỰC NỘI DUNG CHÍNH (APP MAIN - CHỐNG TRÀN VIỀN)
         * ========================================================== */
        .app-main {
            margin-left: 260px;
            margin-top: 70px;
            padding: 28px 24px 60px;
            min-height: calc(100vh - 70px);
            background-color: var(--bg-body);
            transition: margin-left 0.25s ease;
            overflow-x: hidden !important;
            width: calc(100% - 260px);
            max-width: 100vw;
            box-sizing: border-box;
        }

        .content-container {
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
            overflow-x: hidden !important;
        }

        /* HERO BANNER */
        .settings-hero {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
            border-radius: var(--radius-lg);
            padding: 28px 32px;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 35px -10px rgba(30, 27, 75, 0.35);
            margin-bottom: 24px;
            box-sizing: border-box;
            width: 100%;
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
            max-width: 780px;
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
            box-sizing: border-box;
            width: 100%;
            min-width: 0;
            overflow: hidden;
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
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .config-title-badge {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 50px;
            background: #f1f5f9;
            color: #475569;
        }

        /* 1. CHỌN CHẾ ĐỘ NỀN TẢNG (SCOPE SELECTOR - FIX TRÀN VIỀN 100%) */
        .scope-selector-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 24px;
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }

        .scope-card-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: var(--radius-md);
            border: 2px solid var(--card-border);
            background: #ffffff;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            user-select: none;
            min-width: 0;
            width: 100%;
            box-sizing: border-box;
            overflow: hidden;
        }

        .scope-card-item:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
            transform: translateY(-2px);
        }

        .scope-card-item.active {
            border-color: var(--primary);
            background: #f5f3ff;
            box-shadow: 0 4px 16px rgba(79, 70, 229, 0.12);
        }

        .scope-icon-wrap {
            width: 36px;
            height: 36px;
            min-width: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            background: #ffffff;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
            flex-shrink: 0;
            overflow: hidden;
        }

        .scope-icon-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
        }

        .scope-text-wrap {
            flex: 1 1 0%;
            min-width: 0;
            overflow: hidden;
        }

        .scope-title {
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--text-heading);
            line-height: 1.25;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .scope-sub {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .scope-check-dot {
            font-size: 1.15rem;
            color: #cbd5e1;
            transition: var(--transition);
            flex-shrink: 0;
        }

        .scope-card-item.active .scope-check-dot {
            color: #10b981;
        }

        /* 2. CHỌN MẠNG XÃ HỘI (CHIPS) */
        .social-chips-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 24px;
            width: 100%;
        }

        .social-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 50px;
            border: 1.5px solid var(--card-border);
            background: #ffffff;
            color: var(--text-body);
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            user-select: none;
            max-width: 100%;
        }

        .social-chip input { display: none; }

        .social-chip:hover {
            border-color: #94a3b8;
            background: #f8fafc;
        }

        .social-chip.active {
            border-color: var(--primary);
            background: #eef2ff;
            color: var(--primary);
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.15);
        }

        .social-chip .social-chip-check {
            font-size: 0.9rem;
            color: transparent;
            transition: var(--transition);
        }

        .social-chip.active .social-chip-check {
            color: #10b981;
        }

        /* 3. CHỌN TÀI KHOẢN ĐA LUỒNG - THIẾT KẾ ĐẸP & CHỐNG TRÀN CHỮ */
        .accounts-grid-wrapper {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }

        .col-span-full {
            grid-column: 1 / -1;
        }

        .account-select-card {
            border: 1.5px solid var(--card-border);
            border-radius: var(--radius-md);
            padding: 12px 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            background: #ffffff;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            user-select: none;
            min-width: 0;
            width: 100%;
            box-sizing: border-box;
            overflow: hidden;
        }

        .account-select-card:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
        }

        .account-select-card.active {
            border-color: #10b981;
            background: #f0fdf4;
            box-shadow: 0 3px 10px rgba(16, 185, 129, 0.12);
        }

        .account-avatar-box {
            width: 40px;
            height: 40px;
            min-width: 40px;
            border-radius: 50%;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
            border: 1.5px solid #e2e8f0;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
        }

        .account-avatar-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }

        .account-info-box {
            flex: 1 1 0%;
            min-width: 0;
            overflow: hidden;
        }

        .account-name-row {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text-heading);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.25;
        }

        .account-sub-row {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 4px;
            min-width: 0;
        }

        .account-badge-plat {
            font-size: 0.65rem;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 4px;
            background: #e0e7ff;
            color: #4338ca;
            border: 1px solid #c7d2fe;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .account-id-tag {
            font-size: 0.72rem;
            color: var(--text-muted);
            font-family: 'Fira Code', monospace;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex-shrink: 1;
        }

        .account-coin-tag {
            font-size: 0.78rem;
            font-weight: 800;
            color: #059669;
            white-space: nowrap !important;
            flex-shrink: 0;
            margin-left: auto;
        }

        .account-select-card.active .scope-check-dot {
            color: #10b981;
        }

        .multi-thread-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 50px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            font-size: 0.76rem;
            font-weight: 800;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
            animation: pulseSubtle 2s infinite;
        }

        @keyframes pulseSubtle {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.04); }
        }

        /* 4. THANH TRƯỢT (SLIDERS & PILLS) */
        .slider-control-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius-md);
            padding: 18px 20px;
            margin-bottom: 16px;
            box-sizing: border-box;
            width: 100%;
            min-width: 0;
        }

        .slider-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .slider-label {
            font-size: 0.92rem;
            font-weight: 700;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 0;
        }

        .slider-value-badge {
            font-size: 0.85rem;
            font-weight: 800;
            color: #ffffff;
            background: var(--primary);
            padding: 4px 14px;
            border-radius: 50px;
            min-width: 80px;
            text-align: center;
            box-shadow: 0 2px 6px rgba(79, 70, 229, 0.25);
            white-space: nowrap;
        }

        .custom-range-slider {
            width: 100%;
            height: 7px;
            border-radius: 10px;
            background: #e2e8f0;
            outline: none;
            accent-color: var(--primary);
            cursor: pointer;
            margin-bottom: 12px;
        }

        .quick-pills-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            width: 100%;
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
            white-space: nowrap;
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
            width: 100%;
            box-sizing: border-box;
        }

        .filter-price-box.enabled {
            border-style: solid;
            border-color: #10b981;
            background: #f0fdf4;
        }

        .cloud-config-box {
            border: 1.5px solid #c7d2fe;
            border-radius: 16px;
            padding: 20px 22px;
            background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
            margin-bottom: 24px;
            transition: var(--transition);
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
            overflow: hidden;
        }

        .cloud-config-box.active {
            border-color: #6366f1;
            background: #ffffff;
            box-shadow: 0 8px 24px rgba(99, 102, 241, 0.12);
        }

        .cloud-sub-settings-panel {
            background: #f8fafc;
            border: 1.5px dashed #c7d2fe;
            border-radius: 14px;
            padding: 20px;
            margin-top: 16px;
            animation: fadeInPanel 0.3s ease;
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }

        @keyframes fadeInPanel {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
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

        /* DIALOG XÁC NHẬN SVG THEO INDEX.PHP */
        .svg-dialog-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(4px);
            z-index: 1090;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }

        .svg-dialog-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .svg-dialog-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 28px 24px;
            width: 100%;
            max-width: 420px;
            text-align: center;
            box-shadow: 0 20px 45px -10px rgba(15, 23, 42, 0.25);
            transform: scale(0.92);
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .svg-dialog-overlay.active .svg-dialog-card {
            transform: scale(1);
        }

        .svg-draw-icon {
            width: 70px;
            height: 70px;
        }

        /* ==========================================================
         * 4. RESPONSIVE MOBILE & CHỐNG TRÀN HOÀN TOÀN
         * ========================================================== */
        @media (max-width: 991.98px) {
            .app-sidebar {
                transform: translateX(-100%) !important;
                z-index: 1060 !important;
                padding-top: 14px !important;
            }
            .app-sidebar.sidebar-open {
                transform: translateX(0) !important;
                box-shadow: 4px 0 30px rgba(0, 0, 0, 0.25) !important;
            }
            .sidebar-backdrop {
                z-index: 1055 !important;
            }
            .app-main {
                margin-left: 0 !important;
                margin-top: 56px !important;
                width: 100% !important;
                padding: 16px 12px 50px !important;
            }
            .content-container {
                padding: 0 !important;
                width: 100% !important;
            }
        }

        @media (max-width: 767.98px) {
            .app-header {
                height: 56px !important;
                padding: 0 12px !important;
            }
            .brand-name {
                font-size: 0.95rem !important;
            }
            .brand-tech-suffix {
                display: none !important;
            }
            .user-avatar-small {
                width: 32px !important;
                height: 32px !important;
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
            .settings-hero {
                padding: 18px 14px !important;
                border-radius: 14px !important;
                margin-bottom: 16px !important;
            }
            .hero-title {
                font-size: 1.25rem !important;
            }
            .config-card {
                padding: 16px 12px !important;
                border-radius: 14px !important;
                margin-bottom: 16px !important;
            }

            /* Fix tràn 4 card Scope trên Mobile */
            .scope-selector-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: 8px !important;
                width: 100% !important;
            }
            .scope-card-item {
                padding: 8px 10px !important;
                gap: 6px !important;
            }
            .scope-icon-wrap {
                width: 28px !important;
                height: 28px !important;
                min-width: 28px !important;
                font-size: 0.85rem !important;
            }
            .scope-icon-wrap img {
                width: 24px !important;
                height: 24px !important;
            }
            .scope-title {
                font-size: 0.78rem !important;
            }
            .scope-sub {
                display: none !important;
            }
            .scope-check-dot {
                font-size: 0.95rem !important;
            }

            /* Fix tràn tài khoản trên Mobile */
            .accounts-grid-wrapper {
                grid-template-columns: 1fr !important;
                gap: 8px !important;
            }
            .account-select-card {
                padding: 10px 12px !important;
                gap: 10px !important;
            }
            .account-avatar-box {
                width: 34px !important;
                height: 34px !important;
                min-width: 34px !important;
            }
            .account-name-row {
                font-size: 0.85rem !important;
            }

            /* Fix Slider & Pills trên Mobile */
            .slider-control-card {
                padding: 12px 10px !important;
            }
            .slider-label {
                font-size: 0.84rem !important;
            }
            .slider-value-badge {
                font-size: 0.78rem !important;
                padding: 3px 10px !important;
                min-width: 65px !important;
            }
            .quick-pill-btn {
                padding: 3px 8px !important;
                font-size: 0.72rem !important;
            }

            /* Fix Cloud Panel trên Mobile */
            .cloud-config-box {
                padding: 14px 10px !important;
                border-radius: 14px !important;
            }
            .cloud-sub-settings-panel {
                padding: 12px 8px !important;
                border-radius: 12px !important;
            }

            .btn-action-save {
                padding: 12px 18px !important;
                font-size: 0.95rem !important;
            }
        }
    </style>
    <script>
        function toggleAppSidebar(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            var sb = document.getElementById('appSidebar');
            var bd = document.getElementById('sidebarBackdrop');
            if (window.innerWidth >= 992) {
                document.body.classList.toggle('sidebar-collapsed');
            } else {
                if (sb) {
                    var isOpen = sb.classList.toggle('sidebar-open');
                    if (bd) {
                        if (isOpen) {
                            bd.classList.add('active');
                        } else {
                            bd.classList.remove('active');
                        }
                    }
                }
            }
        }

        function closeAppSidebar() {
            var sb = document.getElementById('appSidebar');
            var bd = document.getElementById('sidebarBackdrop');
            if (sb) sb.classList.remove('sidebar-open');
            if (bd) bd.classList.remove('active');
        }
    </script>
</head>
<body>

    <!-- Hộp thoại SVG Stroke Draw -->
    <div id="svgDialogOverlay" class="svg-dialog-overlay">
        <div class="svg-dialog-card">
            <div class="mb-3" id="svgDialogIcon" style="display: flex; justify-content: center;"></div>
            <h3 class="fw-bold mb-2 text-dark" id="svgDialogTitle">Thông báo</h3>
            <div class="text-muted mb-4 small" id="svgDialogMessage"></div>
            <div id="svgDialogActions" class="d-flex gap-2 justify-content-center"></div>
        </div>
    </div>

    <!-- ==========================================================
     * 1. HEADER CỐ ĐỊNH CHUẨN 1:1 THEO INDEX.PHP
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
                    ThanhQuy<span class="brand-tech-suffix">Tech</span>
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

                    <div class="p-2 px-3 rounded-3 mb-3 d-flex justify-content-between align-items-center" style="background: #f0fdf4; border: 1px solid #bbf7d0;">
                        <div>
                            <div class="text-muted" style="font-size: 0.68rem; font-weight: 700; text-transform: uppercase;">Số dư khả dụng</div>
                            <div class="fw-bold" style="color: #15803d; font-size: 0.95rem;"><?= format_currency($currentUser['balance']) ?></div>
                        </div>
                        <a href="/payments/deposit" class="btn btn-sm btn-success rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;">
                            <i class="fa-solid fa-circle-arrow-down me-1"></i> Nạp tiền
                        </a>
                    </div>

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
                        <a href="settings.php" class="popup-menu-item">
                            <i class="fa-solid fa-gear text-secondary me-2"></i> Cấu hình
                        </a>
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
     * 2. MENU SIDEBAR CHUẨN 1:1 THEO INDEX.PHP
     * ========================================================== -->
    <aside class="app-sidebar" id="appSidebar">
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
                <button class="sidebar-link collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#submenuGolike" aria-expanded="false">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-robot"></i></span>
                    <span class="sidebar-title">Tool Golike</span>
                    <i class="fa-solid fa-chevron-down sidebar-arrow"></i>
                </button>
                <div class="collapse" id="submenuGolike">
                    <ul class="sidebar-submenu">
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

    <!-- Lớp nền mờ cho Sidebar trên điện thoại -->
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

            <!-- FORM CẤU HÌNH DUY NHẤT -->
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
                        <span class="config-title-badge" id="activeScopeLabel">
                            Đang chỉnh: <?= ($scopeParam === 'general') ? 'CHUNG TOÀN HỆ THỐNG' : strtoupper($scopeParam) ?>
                        </span>
                    </div>

                    <div class="scope-selector-grid">
                        <!-- Toàn bộ hệ thống -->
                        <div class="scope-card-item <?= ($scopeParam === 'general') ? 'active' : '' ?>" data-scope="general" onclick="switchScope('general', this)">
                            <div class="scope-icon-wrap text-primary">
                                <i class="fa-solid fa-globe"></i>
                            </div>
                            <div class="scope-text-wrap">
                                <div class="scope-title">Toàn bộ hệ thống</div>
                                <div class="scope-sub">Hiện all acc (Mọi nền tảng)</div>
                            </div>
                            <i class="fa-solid fa-circle-check scope-check-dot"></i>
                        </div>

                        <!-- Golike -->
                        <div class="scope-card-item <?= ($scopeParam === 'golike') ? 'active' : '' ?>" data-scope="golike" onclick="switchScope('golike', this)">
                            <div class="scope-icon-wrap">
                                <img src="<?= htmlspecialchars($golikeLogo) ?>" alt="Golike">
                            </div>
                            <div class="scope-text-wrap">
                                <div class="scope-title">Golike</div>
                                <div class="scope-sub">Chỉ hiện acc Golike</div>
                            </div>
                            <i class="fa-solid fa-circle-check scope-check-dot"></i>
                        </div>

                        <!-- Tương Tác Chéo -->
                        <div class="scope-card-item <?= ($scopeParam === 'tuongtaccheo') ? 'active' : '' ?>" data-scope="tuongtaccheo" onclick="switchScope('tuongtaccheo', this)">
                            <div class="scope-icon-wrap text-success">
                                <i class="fa-solid fa-arrows-rotate"></i>
                            </div>
                            <div class="scope-text-wrap">
                                <div class="scope-title">Tương Tác Chéo</div>
                                <div class="scope-sub">Chỉ hiện acc TTC</div>
                            </div>
                            <i class="fa-solid fa-circle-check scope-check-dot"></i>
                        </div>

                        <!-- Trao Đổi Sub -->
                        <div class="scope-card-item <?= ($scopeParam === 'traodoisub') ? 'active' : '' ?>" data-scope="traodoisub" onclick="switchScope('traodoisub', this)">
                            <div class="scope-icon-wrap text-info">
                                <i class="fa-solid fa-bolt"></i>
                            </div>
                            <div class="scope-text-wrap">
                                <div class="scope-title">Trao Đổi Sub</div>
                                <div class="scope-sub">Chỉ hiện acc TDS</div>
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
                    <div class="social-chips-group" id="groupSocialPlatforms">
                        <label class="social-chip <?= $isAllSocial ? 'active' : '' ?>" id="chipSocialAll" onclick="toggleSocialAll(this, 'groupSocialPlatforms')">
                            <input type="checkbox" name="social_platforms[]" value="all" <?= $isAllSocial ? 'checked' : '' ?>>
                            <i class="fa-solid fa-asterisk"></i>
                            <span>Toàn bộ MXH</span>
                            <i class="fa-solid fa-circle-check social-chip-check"></i>
                        </label>

                        <label class="social-chip <?= in_array('instagram', $savedSocials) ? 'active' : '' ?>" onclick="toggleSocialSingle(this, 'chipSocialAll')">
                            <input type="checkbox" name="social_platforms[]" value="instagram" <?= in_array('instagram', $savedSocials) ? 'checked' : '' ?>>
                            <i class="fa-brands fa-instagram text-danger"></i>
                            <span>Instagram (IG)</span>
                            <i class="fa-solid fa-circle-check social-chip-check"></i>
                        </label>

                        <label class="social-chip <?= in_array('threads', $savedSocials) ? 'active' : '' ?>" onclick="toggleSocialSingle(this, 'chipSocialAll')">
                            <input type="checkbox" name="social_platforms[]" value="threads" <?= in_array('threads', $savedSocials) ? 'checked' : '' ?>>
                            <i class="fa-brands fa-threads text-dark"></i>
                            <span>Threads</span>
                            <i class="fa-solid fa-circle-check social-chip-check"></i>
                        </label>

                        <label class="social-chip <?= in_array('pinterest', $savedSocials) ? 'active' : '' ?>" onclick="toggleSocialSingle(this, 'chipSocialAll')">
                            <input type="checkbox" name="social_platforms[]" value="pinterest" <?= in_array('pinterest', $savedSocials) ? 'checked' : '' ?>>
                            <i class="fa-brands fa-pinterest text-danger"></i>
                            <span>Pinterest</span>
                            <i class="fa-solid fa-circle-check social-chip-check"></i>
                        </label>

                        <label class="social-chip <?= in_array('tiktok', $savedSocials) ? 'active' : '' ?>" onclick="toggleSocialSingle(this, 'chipSocialAll')">
                            <input type="checkbox" name="social_platforms[]" value="tiktok" <?= in_array('tiktok', $savedSocials) ? 'checked' : '' ?>>
                            <i class="fa-brands fa-tiktok text-dark"></i>
                            <span>TikTok</span>
                            <i class="fa-solid fa-circle-check social-chip-check"></i>
                        </label>
                    </div>

                    <!-- 3. CHỌN TÀI KHOẢN CHẠY ĐA LUỒNG (LỌC THEO SCOPE NỀN TẢNG ĐANG CHỌN) -->
                    <div class="config-section-title mt-4">
                        <div>
                            <div class="config-title-text">
                                <i class="fa-solid fa-users-gear text-primary"></i>
                                <span>3. Chọn Tài Khoản Vận Hành (Đa Luồng) <span class="text-danger fw-bold">*</span></span>
                            </div>
                            <div class="text-muted small mt-1" id="accountSectionHint">
                                (Đang hiển thị tài khoản theo chế độ: <strong><?= ($scopeParam === 'general') ? 'Toàn bộ' : strtoupper($scopeParam) ?></strong>. Tự động chọn tài khoản đầu tiên nếu bạn không tích chọn)
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2 mt-2 mt-sm-0">
                            <span class="multi-thread-badge d-none" id="multiThreadBadge">
                                <i class="fa-solid fa-bolt"></i> Chạy Đa Luồng (<span id="selectedAccCount">0</span> acc)
                            </span>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="selectAllAccounts('normal')">Chọn tất cả</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="deselectAllAccounts('normal')">Bỏ chọn</button>
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
                        <div class="accounts-grid-wrapper mb-4" id="accountsGridNormal">
                            <?php 
                            $visibleCountNormal = 0;
                            foreach ($tokens as $t): 
                                $pCode = strtolower($t['platform'] ?? 'golike');
                                $isMatch = is_platform_matching_scope($pCode, $scopeParam);
                                $isSelected = in_array((int)$t['id'], $savedAccs) && $isMatch;
                                if ($isMatch) $visibleCountNormal++;

                                $badgeColor = '#eef2ff';
                                $badgeTextColor = '#4f46e5';
                                $pDisplay = strtoupper($pCode);
                                if ($pCode === 'tuongtaccheo' || $pCode === 'ttc') {
                                    $badgeColor = '#ecfdf5';
                                    $badgeTextColor = '#059669';
                                    $pDisplay = 'TTC';
                                } elseif ($pCode === 'traodoisub' || $pCode === 'tds') {
                                    $badgeColor = '#eff6ff';
                                    $badgeTextColor = '#2563eb';
                                    $pDisplay = 'TDS';
                                }
                            ?>
                            <div class="account-select-card <?= $isSelected ? 'active' : '' ?>" data-acc-id="<?= $t['id'] ?>" data-plat="<?= htmlspecialchars($pCode) ?>" style="display: <?= $isMatch ? 'flex' : 'none' ?>;" onclick="toggleAccountCard(this, 'normal')">
                                <input type="checkbox" name="selected_accounts[]" value="<?= $t['id'] ?>" class="account-checkbox d-none" <?= $isSelected ? 'checked' : '' ?> <?= !$isMatch ? 'disabled' : '' ?>>
                                <div class="account-avatar-box">
                                    <?php if ($pCode === 'golike'): ?>
                                        <img src="<?= htmlspecialchars($golikeLogo) ?>" alt="Golike">
                                    <?php elseif ($pCode === 'tuongtaccheo' || $pCode === 'ttc'): ?>
                                        <?php if (!empty($platformLogos['ttc'])): ?>
                                            <img src="<?= htmlspecialchars($platformLogos['ttc']) ?>" alt="TTC">
                                        <?php else: ?>
                                            <div class="avatar-platform-icon" style="background:#ecfdf5; color:#059669; width:100%; height:100%; display:flex; align-items:center; justify-content:center; border-radius:50%; font-weight:800; font-size:0.75rem;">TTC</div>
                                        <?php endif; ?>
                                    <?php elseif ($pCode === 'traodoisub' || $pCode === 'tds'): ?>
                                        <?php if (!empty($platformLogos['tds'])): ?>
                                            <img src="<?= htmlspecialchars($platformLogos['tds']) ?>" alt="TDS">
                                        <?php else: ?>
                                            <div class="avatar-platform-icon" style="background:#eff6ff; color:#2563eb; width:100%; height:100%; display:flex; align-items:center; justify-content:center; border-radius:50%; font-weight:800; font-size:0.75rem;">TDS</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <i class="fa-solid fa-user text-muted"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="account-info-box">
                                    <div class="account-name-row"><?= htmlspecialchars($t['name']) ?></div>
                                    <div class="account-sub-row">
                                        <span class="account-badge-plat" style="background: <?= $badgeColor ?>; color: <?= $badgeTextColor ?>;"><?= $pDisplay ?></span>
                                        <span class="account-id-tag">ID: <?= htmlspecialchars($t['account_id']) ?></span>
                                        <span class="account-coin-tag" style="white-space: nowrap !important;"><?= number_format($t['coin'], 0, ',', '.') ?> xu</span>
                                    </div>
                                </div>
                                <i class="fa-solid fa-circle-check scope-check-dot"></i>
                            </div>
                            <?php endforeach; ?>
                            <div class="empty-plat-notice text-center p-3 bg-light rounded-3 border w-100 col-span-full" style="display: <?= ($visibleCountNormal === 0 && !empty($tokens)) ? 'block' : 'none' ?>;">
                                <i class="fa-solid fa-circle-info text-primary me-1"></i> Chưa có tài khoản nào thuộc nền tảng này. <a href="token.php" class="fw-bold ms-1 text-primary"><i class="fa-solid fa-plus me-1"></i>Thêm token ngay</a>
                            </div>
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
                                    <input class="form-check-input" type="checkbox" role="switch" id="checkJobUnlimited" name="job_unlimited" value="1" <?= !empty($activeConfig['job_unlimited']) ? 'checked' : '' ?> onchange="toggleJobUnlimited(this.checked, '')">
                                    <label class="form-check-label fw-bold text-muted small" for="checkJobUnlimited">Chạy vô hạn (∞)</label>
                                </div>
                                <span class="slider-value-badge" id="badgeJobLimit"><?= !empty($activeConfig['job_unlimited']) ? 'Vô hạn (∞)' : ($activeConfig['job_limit'] ?? 10) . ' jobs' ?></span>
                            </div>
                        </div>
                        <input type="range" class="custom-range-slider" id="sliderJobLimit" name="job_limit" min="1" max="300" step="1" value="<?= (int)($activeConfig['job_limit'] ?? 10) ?>" <?= !empty($activeConfig['job_unlimited']) ? 'disabled' : '' ?> oninput="updateJobLimit(this.value, '')">
                        
                        <div class="quick-pills-row">
                            <span class="text-muted small fw-bold me-1">Chọn nhanh:</span>
                            <button type="button" class="quick-pill-btn" onclick="setJobLimit(3, '')">3 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setJobLimit(5, '')">5 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setJobLimit(10, '')">10 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setJobLimit(20, '')">20 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setJobLimit(50, '')">50 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setJobLimit(100, '')">100 jobs</button>
                        </div>
                    </div>

                    <!-- 4.2 THỜI GIAN DELAY -->
                    <div class="slider-control-card">
                        <div class="slider-header-row">
                            <label class="slider-label" for="sliderDelay">
                                <i class="fa-solid fa-stopwatch text-warning"></i>
                                <span>Thời gian Delay giữa các Jobs:</span>
                            </label>
                            <span class="slider-value-badge bg-warning text-dark" id="badgeDelay"><?= (int)($activeConfig['delay_seconds'] ?? 15) ?> giây</span>
                        </div>
                        <input type="range" class="custom-range-slider" id="sliderDelay" name="delay_seconds" min="3" max="120" step="1" value="<?= (int)($activeConfig['delay_seconds'] ?? 15) ?>" oninput="updateDelay(this.value, '')">
                        
                        <div class="quick-pills-row">
                            <span class="text-muted small fw-bold me-1">Chọn nhanh:</span>
                            <button type="button" class="quick-pill-btn" onclick="setDelay(5, '')">5s</button>
                            <button type="button" class="quick-pill-btn" onclick="setDelay(10, '')">10s</button>
                            <button type="button" class="quick-pill-btn border-primary text-primary active" onclick="setDelay(15, '')">15s (Khuyên dùng)</button>
                            <button type="button" class="quick-pill-btn" onclick="setDelay(20, '')">20s</button>
                            <button type="button" class="quick-pill-btn border-success text-success" onclick="setDelay(30, '')">30s (An toàn)</button>
                            <button type="button" class="quick-pill-btn" onclick="setDelay(60, '')">60s</button>
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
                                    <input class="form-check-input" type="checkbox" role="switch" id="checkSwitchNever" name="switch_never" value="1" <?= !empty($activeConfig['switch_never']) ? 'checked' : '' ?> onchange="toggleSwitchNever(this.checked, '')">
                                    <label class="form-check-label fw-bold text-muted small" for="checkSwitchNever">Không đổi acc</label>
                                </div>
                                <span class="slider-value-badge bg-info text-white" id="badgeSwitchAcc"><?= !empty($activeConfig['switch_never']) ? 'Không đổi' : ($activeConfig['switch_account_jobs'] ?? 5) . ' jobs' ?></span>
                            </div>
                        </div>
                        <input type="range" class="custom-range-slider" id="sliderSwitchAcc" name="switch_account_jobs" min="1" max="50" step="1" value="<?= (int)($activeConfig['switch_account_jobs'] ?? 5) ?>" <?= !empty($activeConfig['switch_never']) ? 'disabled' : '' ?> oninput="updateSwitchAcc(this.value, '')">
                        
                        <div class="quick-pills-row">
                            <span class="text-muted small fw-bold me-1">Chọn nhanh:</span>
                            <button type="button" class="quick-pill-btn" onclick="setSwitchAcc(3, '')">3 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setSwitchAcc(5, '')">5 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setSwitchAcc(10, '')">10 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setSwitchAcc(15, '')">15 jobs</button>
                            <button type="button" class="quick-pill-btn" onclick="setSwitchAcc(20, '')">20 jobs</button>
                        </div>
                    </div>

                    <!-- 5. LỌC ĐƠN GIÁ TỐI THIỂU -->
                    <?php $priceFilterOn = !empty($activeConfig['price_filter_enabled']); ?>
                    <div class="filter-price-box <?= $priceFilterOn ? 'enabled' : '' ?>" id="filterPriceBox">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="checkPriceFilter" name="price_filter_enabled" value="1" <?= $priceFilterOn ? 'checked' : '' ?> onchange="togglePriceFilter(this.checked, '')">
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
                                        <button type="button" class="quick-pill-btn" onclick="setMinPrice(25, '')">25đ</button>
                                        <button type="button" class="quick-pill-btn" onclick="setMinPrice(35, '')">35đ</button>
                                        <button type="button" class="quick-pill-btn" onclick="setMinPrice(50, '')">50đ</button>
                                        <button type="button" class="quick-pill-btn" onclick="setMinPrice(70, '')">70đ</button>
                                        <button type="button" class="quick-pill-btn" onclick="setMinPrice(100, '')">100đ</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 6. CẤU HÌNH CHO MÁY CHỦ CLOUD VPS (TỐI ƯU GIAO DIỆN & LỌC TÀI KHOẢN) -->
                    <?php $cloudCustomOn = !empty($activeConfig['cloud_custom_enabled']); ?>
                    <div class="cloud-config-box <?= $cloudCustomOn ? 'active' : '' ?>" id="cloudConfigBox">
                        <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2">
                            <div class="d-flex align-items-start gap-2">
                                <div class="form-check form-switch mb-0 pt-1">
                                    <input class="form-check-input" type="checkbox" role="switch" id="checkCloudCustom" name="cloud_custom_enabled" value="1" <?= $cloudCustomOn ? 'checked' : '' ?> onchange="toggleCloudConfig(this.checked)">
                                </div>
                                <div>
                                    <label class="fw-bold text-dark mb-0 cursor-pointer" for="checkCloudCustom" style="font-size: 0.95rem;">
                                        <i class="fa-solid fa-cloud text-primary me-1"></i> Bật Cấu Hình Riêng Khi Treo Trên Cloud VPS
                                    </label>
                                    <div class="text-muted small" id="cloudDescText" style="font-size: 0.76rem; line-height: 1.35; margin-top: 2px;">
                                        <?= $cloudCustomOn ? 'Đang bật thiết lập riêng cho máy chủ Cloud VPS 24/24 bên dưới.' : 'Nếu không bật, hệ thống sẽ sử dụng cấu hình mặc định ở trên để treo ngầm.' ?>
                                    </div>
                                </div>
                            </div>
                            <span class="badge <?= $cloudCustomOn ? 'bg-primary text-white' : 'bg-secondary-subtle text-muted' ?> rounded-pill px-3 py-1 align-self-start align-self-sm-center text-nowrap" id="cloudStatusBadge" style="font-size: 0.72rem;">
                                <?= $cloudCustomOn ? 'Đang cấu hình riêng' : 'Dùng chung mặc định' ?>
                            </span>
                        </div>

                        <!-- KHUNG CẤU HÌNH MỞ RỘNG DÀNH RIÊNG CHO CLOUD VPS -->
                        <div class="cloud-sub-settings-panel <?= $cloudCustomOn ? '' : 'd-none' ?>" id="cloudSubPanel">
                            <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-1 pb-2 mb-3 border-bottom border-primary-subtle">
                                <span class="badge bg-primary text-white rounded-pill px-3 py-1 text-nowrap" style="font-size: 0.72rem;">
                                    <i class="fa-solid fa-server me-1"></i> THIẾT LẬP CHUYÊN BIỆT CHO CLOUD VPS
                                </span>
                                <span class="text-muted small" style="font-size: 0.76rem;">Cấu hình độc lập khi chạy ngầm 24/24</span>
                            </div>

                            <!-- 6.1 Cloud MXH -->
                            <div class="fw-bold text-dark mb-2 small">
                                <i class="fa-solid fa-share-nodes text-primary me-1"></i> Chọn Nền Tảng Mạng Xã Hội Treo Cloud:
                            </div>
                            <?php 
                                $cSocials = $cloudSaved['social_platforms'] ?? ['all'];
                                $cIsAllSocial = in_array('all', $cSocials);
                            ?>
                            <div class="social-chips-group" id="groupCloudSocial">
                                <label class="social-chip <?= $cIsAllSocial ? 'active' : '' ?>" id="chipCloudSocialAll" onclick="toggleSocialAll(this, 'groupCloudSocial')">
                                    <input type="checkbox" name="cloud_social_platforms[]" value="all" <?= $cIsAllSocial ? 'checked' : '' ?>>
                                    <i class="fa-solid fa-asterisk"></i>
                                    <span>Toàn bộ MXH</span>
                                    <i class="fa-solid fa-circle-check social-chip-check"></i>
                                </label>
                                <label class="social-chip <?= in_array('instagram', $cSocials) ? 'active' : '' ?>" onclick="toggleSocialSingle(this, 'chipCloudSocialAll')">
                                    <input type="checkbox" name="cloud_social_platforms[]" value="instagram" <?= in_array('instagram', $cSocials) ? 'checked' : '' ?>>
                                    <i class="fa-brands fa-instagram text-danger"></i>
                                    <span>Instagram</span>
                                    <i class="fa-solid fa-circle-check social-chip-check"></i>
                                </label>
                                <label class="social-chip <?= in_array('threads', $cSocials) ? 'active' : '' ?>" onclick="toggleSocialSingle(this, 'chipCloudSocialAll')">
                                    <input type="checkbox" name="cloud_social_platforms[]" value="threads" <?= in_array('threads', $cSocials) ? 'checked' : '' ?>>
                                    <i class="fa-brands fa-threads text-dark"></i>
                                    <span>Threads</span>
                                    <i class="fa-solid fa-circle-check social-chip-check"></i>
                                </label>
                                <label class="social-chip <?= in_array('pinterest', $cSocials) ? 'active' : '' ?>" onclick="toggleSocialSingle(this, 'chipCloudSocialAll')">
                                    <input type="checkbox" name="cloud_social_platforms[]" value="pinterest" <?= in_array('pinterest', $cSocials) ? 'checked' : '' ?>>
                                    <i class="fa-brands fa-pinterest text-danger"></i>
                                    <span>Pinterest</span>
                                    <i class="fa-solid fa-circle-check social-chip-check"></i>
                                </label>
                                <label class="social-chip <?= in_array('tiktok', $cSocials) ? 'active' : '' ?>" onclick="toggleSocialSingle(this, 'chipCloudSocialAll')">
                                    <input type="checkbox" name="cloud_social_platforms[]" value="tiktok" <?= in_array('tiktok', $cSocials) ? 'checked' : '' ?>>
                                    <i class="fa-brands fa-tiktok text-dark"></i>
                                    <span>TikTok</span>
                                    <i class="fa-solid fa-circle-check social-chip-check"></i>
                                </label>
                            </div>

                            <!-- 6.2 Cloud Accounts (LỌC THEO SCOPE NỀN TẢNG) -->
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-2">
                                <div class="fw-bold text-dark small">
                                    <i class="fa-solid fa-users-gear text-primary me-1"></i> Tài Khoản Vận Hành Trên Cloud <span class="text-danger fw-bold">*</span>:
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="multi-thread-badge d-none" id="cloudMultiThreadBadge" style="font-size: 0.7rem; padding: 2px 8px;">
                                        <i class="fa-solid fa-bolt"></i> Đa Luồng (<span id="cloudSelectedAccCount">0</span> acc)
                                    </span>
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0" style="font-size: 0.72rem;" onclick="selectAllAccounts('cloud')">Chọn hết</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0" style="font-size: 0.72rem;" onclick="deselectAllAccounts('cloud')">Bỏ chọn</button>
                                </div>
                            </div>
                            <?php if (!empty($tokens)): ?>
                                <?php $cSavedAccs = $cloudSaved['selected_accounts'] ?? []; ?>
                                <div class="accounts-grid-wrapper mb-3" id="accountsGridCloud">
                                    <?php 
                                    $visibleCountCloud = 0;
                                    foreach ($tokens as $t): 
                                        $pCode = strtolower($t['platform'] ?? 'golike');
                                        $isMatch = is_platform_matching_scope($pCode, $scopeParam);
                                        $isCSelected = in_array((int)$t['id'], $cSavedAccs) && $isMatch;
                                        if ($isMatch) $visibleCountCloud++;

                                        $badgeColor = '#eef2ff';
                                        $badgeTextColor = '#4f46e5';
                                        $pDisplay = strtoupper($pCode);
                                        if ($pCode === 'tuongtaccheo' || $pCode === 'ttc') {
                                            $badgeColor = '#ecfdf5';
                                            $badgeTextColor = '#059669';
                                            $pDisplay = 'TTC';
                                        } elseif ($pCode === 'traodoisub' || $pCode === 'tds') {
                                            $badgeColor = '#eff6ff';
                                            $badgeTextColor = '#2563eb';
                                            $pDisplay = 'TDS';
                                        }
                                    ?>
                                    <div class="account-select-card <?= $isCSelected ? 'active' : '' ?>" data-acc-id="<?= $t['id'] ?>" data-plat="<?= htmlspecialchars($pCode) ?>" style="display: <?= $isMatch ? 'flex' : 'none' ?>;" onclick="toggleAccountCard(this, 'cloud')">
                                        <input type="checkbox" name="cloud_selected_accounts[]" value="<?= $t['id'] ?>" class="account-checkbox d-none" <?= $isCSelected ? 'checked' : '' ?> <?= !$isMatch ? 'disabled' : '' ?>>
                                        <div class="account-avatar-box">
                                            <?php if ($pCode === 'golike'): ?>
                                                <img src="<?= htmlspecialchars($golikeLogo) ?>" alt="Golike">
                                            <?php elseif ($pCode === 'tuongtaccheo' || $pCode === 'ttc'): ?>
                                                <?php if (!empty($platformLogos['ttc'])): ?>
                                                    <img src="<?= htmlspecialchars($platformLogos['ttc']) ?>" alt="TTC">
                                                <?php else: ?>
                                                    <div class="avatar-platform-icon" style="background:#ecfdf5; color:#059669; width:100%; height:100%; display:flex; align-items:center; justify-content:center; border-radius:50%; font-weight:800; font-size:0.75rem;">TTC</div>
                                                <?php endif; ?>
                                            <?php elseif ($pCode === 'traodoisub' || $pCode === 'tds'): ?>
                                                <?php if (!empty($platformLogos['tds'])): ?>
                                                    <img src="<?= htmlspecialchars($platformLogos['tds']) ?>" alt="TDS">
                                                <?php else: ?>
                                                    <div class="avatar-platform-icon" style="background:#eff6ff; color:#2563eb; width:100%; height:100%; display:flex; align-items:center; justify-content:center; border-radius:50%; font-weight:800; font-size:0.75rem;">TDS</div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <i class="fa-solid fa-user text-muted"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="account-info-box">
                                            <div class="account-name-row"><?= htmlspecialchars($t['name']) ?></div>
                                            <div class="account-sub-row">
                                                <span class="account-badge-plat" style="background: <?= $badgeColor ?>; color: <?= $badgeTextColor ?>;"><?= $pDisplay ?></span>
                                                <span class="account-id-tag">ID: <?= htmlspecialchars($t['account_id']) ?></span>
                                                <span class="account-coin-tag" style="white-space: nowrap !important;"><?= number_format($t['coin'], 0, ',', '.') ?> xu</span>
                                            </div>
                                        </div>
                                        <i class="fa-solid fa-circle-check scope-check-dot"></i>
                                    </div>
                                    <?php endforeach; ?>
                                    <div class="empty-plat-notice text-center p-3 bg-light rounded-3 border w-100 col-span-full" style="display: <?= ($visibleCountCloud === 0 && !empty($tokens)) ? 'block' : 'none' ?>;">
                                        <i class="fa-solid fa-circle-info text-primary me-1"></i> Chưa có tài khoản nào thuộc nền tảng này. <a href="token.php" class="fw-bold ms-1 text-primary"><i class="fa-solid fa-plus me-1"></i>Thêm token ngay</a>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- 6.3 Cloud Jobs Limit Slider -->
                            <div class="slider-control-card bg-white mb-2">
                                <div class="slider-header-row">
                                    <label class="slider-label" for="sliderCloudJobLimit">
                                        <i class="fa-solid fa-list-check text-primary"></i>
                                        <span>Số lượng Jobs Cloud:</span>
                                    </label>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" role="switch" id="checkCloudJobUnlimited" name="cloud_job_unlimited" value="1" <?= !empty($cloudSaved['job_unlimited']) ? 'checked' : '' ?> onchange="toggleJobUnlimited(this.checked, 'Cloud')">
                                            <label class="form-check-label fw-bold text-muted small" for="checkCloudJobUnlimited">Chạy vô hạn (∞)</label>
                                        </div>
                                        <span class="slider-value-badge" id="badgeCloudJobLimit"><?= !empty($cloudSaved['job_unlimited']) ? 'Vô hạn (∞)' : ($cloudSaved['job_limit'] ?? 10) . ' jobs' ?></span>
                                    </div>
                                </div>
                                <input type="range" class="custom-range-slider" id="sliderCloudJobLimit" name="cloud_job_limit" min="1" max="300" step="1" value="<?= (int)($cloudSaved['job_limit'] ?? 10) ?>" <?= !empty($cloudSaved['job_unlimited']) ? 'disabled' : '' ?> oninput="updateJobLimit(this.value, 'Cloud')">
                                <div class="quick-pills-row">
                                    <span class="text-muted small fw-bold me-1">Chọn nhanh:</span>
                                    <button type="button" class="quick-pill-btn" onclick="setJobLimit(5, 'Cloud')">5 jobs</button>
                                    <button type="button" class="quick-pill-btn" onclick="setJobLimit(10, 'Cloud')">10 jobs</button>
                                    <button type="button" class="quick-pill-btn" onclick="setJobLimit(20, 'Cloud')">20 jobs</button>
                                    <button type="button" class="quick-pill-btn" onclick="setJobLimit(50, 'Cloud')">50 jobs</button>
                                    <button type="button" class="quick-pill-btn" onclick="setJobLimit(100, 'Cloud')">100 jobs</button>
                                </div>
                            </div>

                            <!-- 6.4 Cloud Delay Slider -->
                            <div class="slider-control-card bg-white mb-2">
                                <div class="slider-header-row">
                                    <label class="slider-label" for="sliderCloudDelay">
                                        <i class="fa-solid fa-stopwatch text-warning"></i>
                                        <span>Delay Cloud giữa các Jobs:</span>
                                    </label>
                                    <span class="slider-value-badge bg-warning text-dark" id="badgeCloudDelay"><?= (int)($cloudSaved['delay_seconds'] ?? 15) ?> giây</span>
                                </div>
                                <input type="range" class="custom-range-slider" id="sliderCloudDelay" name="cloud_delay_seconds" min="3" max="120" step="1" value="<?= (int)($cloudSaved['delay_seconds'] ?? 15) ?>" oninput="updateDelay(this.value, 'Cloud')">
                                <div class="quick-pills-row">
                                    <span class="text-muted small fw-bold me-1">Chọn nhanh:</span>
                                    <button type="button" class="quick-pill-btn" onclick="setDelay(5, 'Cloud')">5s</button>
                                    <button type="button" class="quick-pill-btn" onclick="setDelay(10, 'Cloud')">10s</button>
                                    <button type="button" class="quick-pill-btn border-primary text-primary active" onclick="setDelay(15, 'Cloud')">15s (Khuyên dùng)</button>
                                    <button type="button" class="quick-pill-btn" onclick="setDelay(20, 'Cloud')">20s</button>
                                    <button type="button" class="quick-pill-btn border-success text-success" onclick="setDelay(30, 'Cloud')">30s (An toàn)</button>
                                    <button type="button" class="quick-pill-btn" onclick="setDelay(60, 'Cloud')">60s</button>
                                </div>
                            </div>

                            <!-- 6.5 Cloud Switch Acc Slider -->
                            <div class="slider-control-card bg-white mb-2">
                                <div class="slider-header-row">
                                    <label class="slider-label" for="sliderCloudSwitchAcc">
                                        <i class="fa-solid fa-arrows-rotate text-info"></i>
                                        <span>Đổi tài khoản Cloud sau bao nhiêu Jobs:</span>
                                    </label>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" role="switch" id="checkCloudSwitchNever" name="cloud_switch_never" value="1" <?= !empty($cloudSaved['switch_never']) ? 'checked' : '' ?> onchange="toggleSwitchNever(this.checked, 'Cloud')">
                                            <label class="form-check-label fw-bold text-muted small" for="checkCloudSwitchNever">Không đổi acc</label>
                                        </div>
                                        <span class="slider-value-badge bg-info text-white" id="badgeCloudSwitchAcc"><?= !empty($cloudSaved['switch_never']) ? 'Không đổi' : ($cloudSaved['switch_account_jobs'] ?? 5) . ' jobs' ?></span>
                                    </div>
                                </div>
                                <input type="range" class="custom-range-slider" id="sliderCloudSwitchAcc" name="cloud_switch_account_jobs" min="1" max="50" step="1" value="<?= (int)($cloudSaved['switch_account_jobs'] ?? 5) ?>" <?= !empty($cloudSaved['switch_never']) ? 'disabled' : '' ?> oninput="updateSwitchAcc(this.value, 'Cloud')">
                                <div class="quick-pills-row">
                                    <span class="text-muted small fw-bold me-1">Chọn nhanh:</span>
                                    <button type="button" class="quick-pill-btn" onclick="setSwitchAcc(3, 'Cloud')">3 jobs</button>
                                    <button type="button" class="quick-pill-btn" onclick="setSwitchAcc(5, 'Cloud')">5 jobs</button>
                                    <button type="button" class="quick-pill-btn" onclick="setSwitchAcc(10, 'Cloud')">10 jobs</button>
                                    <button type="button" class="quick-pill-btn" onclick="setSwitchAcc(15, 'Cloud')">15 jobs</button>
                                    <button type="button" class="quick-pill-btn" onclick="setSwitchAcc(20, 'Cloud')">20 jobs</button>
                                </div>
                            </div>

                            <!-- 6.6 Cloud Lọc giá tiền -->
                            <?php $cPriceOn = !empty($cloudSaved['price_filter_enabled']); ?>
                            <div class="filter-price-box bg-white mb-2 <?= $cPriceOn ? 'enabled' : '' ?>" id="filterCloudPriceBox">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" role="switch" id="checkCloudPriceFilter" name="cloud_price_filter_enabled" value="1" <?= $cPriceOn ? 'checked' : '' ?> onchange="togglePriceFilter(this.checked, 'Cloud')">
                                        </div>
                                        <label class="fw-bold text-dark mb-0 cursor-pointer small" for="checkCloudPriceFilter">
                                            <i class="fa-solid fa-coins text-warning me-1"></i> Bật Lọc Đơn Giá Tối Thiểu Trên Cloud
                                        </label>
                                    </div>
                                </div>
                                <div id="priceFilterContentCloud" class="<?= $cPriceOn ? '' : 'd-none' ?> pt-2">
                                    <div class="row g-2 align-items-center">
                                        <div class="col-sm-5">
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text bg-white fw-bold text-muted">>=</span>
                                                <input type="number" class="form-control fw-bold text-primary" id="inputMinPriceCloud" name="cloud_min_price" value="<?= (int)($cloudSaved['min_price'] ?? 35) ?>" min="0" step="5" placeholder="Giá tối thiểu">
                                                <span class="input-group-text bg-white text-muted">đ/xu</span>
                                            </div>
                                        </div>
                                        <div class="col-sm-7">
                                            <div class="quick-pills-row mt-0">
                                                <span class="text-muted small fw-bold">Chọn:</span>
                                                <button type="button" class="quick-pill-btn" onclick="setMinPrice(25, 'Cloud')">25đ</button>
                                                <button type="button" class="quick-pill-btn" onclick="setMinPrice(35, 'Cloud')">35đ</button>
                                                <button type="button" class="quick-pill-btn" onclick="setMinPrice(50, 'Cloud')">50đ</button>
                                                <button type="button" class="quick-pill-btn" onclick="setMinPrice(100, 'Cloud')">100đ</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 6.7 Cloud An Toàn -->
                            <div class="row g-2 pt-2">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="checkCloudAutoSkip" name="cloud_auto_skip_error" value="1" <?= !empty($cloudSaved['auto_skip_error']) ? 'checked' : '' ?>>
                                        <label class="form-check-label text-dark small" for="checkCloudAutoSkip">
                                            <strong>Tự bỏ qua</strong> job lỗi trên Cloud sau 3 lần thử
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="checkCloudAntiBlock" name="cloud_anti_block_safe" value="1" <?= !empty($cloudSaved['anti_block_safe']) ? 'checked' : '' ?>>
                                        <label class="form-check-label text-dark small" for="checkCloudAntiBlock">
                                            <strong>Chống Checkpoint:</strong> Tạm dừng 5 phút trên Cloud nếu bị nhả like
                                        </label>
                                    </div>
                                </div>
                            </div>
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

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- JAVASCRIPT ĐIỀU KHIỂN THÔNG MINH -->
    <script>
        const ALL_CONFIGS = <?= json_encode($allConfigs, JSON_UNESCAPED_UNICODE) ?>;
        const DEFAULT_CONFIG = <?= json_encode($defaultGeneralConfig, JSON_UNESCAPED_UNICODE) ?>;

        // Chuẩn hóa mã nền tảng
        function normalizePlatform(plat) {
            plat = (plat || '').toLowerCase().trim();
            if (plat === 'ttc' || plat === 'tuongtaccheo' || plat === 'tuong_tac_cheo' || plat.indexOf('ttc') !== -1 || plat.indexOf('tuongtac') !== -1) return 'tuongtaccheo';
            if (plat === 'tds' || plat === 'traodoisub' || plat === 'trao_doi_sub' || plat.indexOf('tds') !== -1 || plat.indexOf('traodoi') !== -1) return 'traodoisub';
            if (plat === 'golike' || plat === 'go_like' || plat.indexOf('golike') !== -1) return 'golike';
            return plat;
        }

        // Kiểm tra tài khoản có khớp với scope đang chọn hay không
        function matchScopePlatform(scope, plat) {
            scope = (scope || 'general').toLowerCase().trim();
            if (scope === 'general' || scope === 'all' || scope === 'cloud' || !scope) return true;
            const normPlat = normalizePlatform(plat);
            const normScope = normalizePlatform(scope);
            return normPlat === normScope;
        }

        // Lọc hiển thị danh sách tài khoản theo chế độ cấu hình đang chọn
        function filterAccountsByScope(scope) {
            ['normal', 'cloud'].forEach(mode => {
                const gridId = (mode === 'cloud') ? 'accountsGridCloud' : 'accountsGridNormal';
                const grid = document.getElementById(gridId);
                if (!grid) return;

                let visibleCount = 0;
                const cards = grid.querySelectorAll('.account-select-card');
                cards.forEach(card => {
                    const plat = card.getAttribute('data-plat');
                    const isMatch = matchScopePlatform(scope, plat);
                    const chk = card.querySelector('.account-checkbox');
                    if (isMatch) {
                        card.style.display = 'flex';
                        if (chk) chk.disabled = false;
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                        if (chk) {
                            chk.disabled = true;
                            chk.checked = false;
                        }
                        card.classList.remove('active');
                    }
                });

                // Thông báo khi không có tài khoản cho nền tảng đang chọn
                let emptyEl = grid.querySelector('.empty-plat-notice');
                if (visibleCount === 0 && cards.length > 0) {
                    if (!emptyEl) {
                        emptyEl = document.createElement('div');
                        emptyEl.className = 'empty-plat-notice text-center p-3 bg-light rounded-3 border w-100 col-span-full';
                        grid.appendChild(emptyEl);
                    }
                    emptyEl.style.display = 'block';
                    let platName = 'nền tảng này';
                    if (scope === 'golike') platName = 'Golike';
                    else if (scope === 'tuongtaccheo') platName = 'Tương Tác Chéo (TTC)';
                    else if (scope === 'traodoisub') platName = 'Trao Đổi Sub (TDS)';
                    emptyEl.innerHTML = `<i class="fa-solid fa-circle-info text-primary me-1"></i> Chưa có tài khoản nào thuộc <strong>${platName}</strong>. <a href="token.php" class="fw-bold ms-1 text-primary"><i class="fa-solid fa-plus me-1"></i>Thêm token ngay</a>`;
                } else if (emptyEl) {
                    emptyEl.style.display = 'none';
                }

                ensureDefaultAccount(mode, scope);
            });

            // Cập nhật gợi ý tiêu đề mục 3
            const hintEl = document.getElementById('accountSectionHint');
            if (hintEl) {
                let textMode = 'Toàn bộ tài khoản (All acc)';
                if (scope === 'golike') textMode = 'Chỉ tài khoản Golike';
                else if (scope === 'tuongtaccheo') textMode = 'Chỉ tài khoản Tương Tác Chéo (TTC)';
                else if (scope === 'traodoisub') textMode = 'Chỉ tài khoản Trao Đổi Sub (TDS)';
                hintEl.innerHTML = `(Chế độ lọc: <strong class="text-primary">${textMode}</strong>. Tự động chọn tài khoản đầu tiên nếu chưa chọn)`;
            }
        }

        // 1. Chuyển đổi Scope (Chung, Golike, TTC, TDS)
        function switchScope(scope, cardEl) {
            const input = document.getElementById('targetScopeInput');
            if (input) input.value = scope;

            document.querySelectorAll('.scope-card-item').forEach(card => {
                card.classList.remove('active');
            });
            if (cardEl) {
                cardEl.classList.add('active');
            } else {
                const targetCard = document.querySelector(`.scope-card-item[data-scope="${scope}"]`);
                if (targetCard) targetCard.classList.add('active');
            }

            const label = document.getElementById('activeScopeLabel');
            if (label) {
                let displayScope = 'TOÀN BỘ (ALL ACC)';
                if (scope === 'golike') displayScope = 'GOLIKE';
                else if (scope === 'tuongtaccheo') displayScope = 'TƯƠNG TÁC CHÉO (TTC)';
                else if (scope === 'traodoisub') displayScope = 'TRAO ĐỔI SUB (TDS)';
                label.textContent = 'Đang chỉnh: ' + displayScope;
            }

            // Lọc tài khoản theo scope được chọn
            filterAccountsByScope(scope);

            const configKey = 'bot_config_' + scope;
            const config = ALL_CONFIGS[configKey] || ALL_CONFIGS['bot_config_general'] || DEFAULT_CONFIG;
            applyConfigToForm(config, scope);

            let scopeToastTitle = 'Toàn bộ tài khoản (All acc)';
            if (scope === 'golike') scopeToastTitle = 'Golike (chỉ acc Golike)';
            else if (scope === 'tuongtaccheo') scopeToastTitle = 'Tương Tác Chéo (chỉ acc TTC)';
            else if (scope === 'traodoisub') scopeToastTitle = 'Trao Đổi Sub (chỉ acc TDS)';

            if (window.Swal) {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Chế độ: ' + scopeToastTitle,
                    showConfirmButton: false,
                    timer: 1500
                });
            }
        }

        // Áp dụng bộ cấu hình vào Form
        function applyConfigToForm(cfg, scope) {
            // Slider Jobs
            if (cfg.job_unlimited) {
                document.getElementById('checkJobUnlimited').checked = true;
                toggleJobUnlimited(true, '');
            } else {
                document.getElementById('checkJobUnlimited').checked = false;
                toggleJobUnlimited(false, '');
                setJobLimit(cfg.job_limit || 10, '');
            }

            // Slider Delay
            setDelay(cfg.delay_seconds || 15, '');

            // Slider Switch Acc
            if (cfg.switch_never) {
                document.getElementById('checkSwitchNever').checked = true;
                toggleSwitchNever(true, '');
            } else {
                document.getElementById('checkSwitchNever').checked = false;
                toggleSwitchNever(false, '');
                setSwitchAcc(cfg.switch_account_jobs || 5, '');
            }

            // Lọc giá
            const priceOn = !!cfg.price_filter_enabled;
            document.getElementById('checkPriceFilter').checked = priceOn;
            togglePriceFilter(priceOn, '');
            if (cfg.min_price) {
                setMinPrice(cfg.min_price, '');
            }

            // Cloud custom
            const cloudOn = !!cfg.cloud_custom_enabled;
            document.getElementById('checkCloudCustom').checked = cloudOn;
            toggleCloudConfig(cloudOn);

            // Accounts selection (chỉ áp dụng cho các tài khoản đang hiển thị)
            const accList = Array.isArray(cfg.selected_accounts) ? cfg.selected_accounts.map(x => parseInt(x)) : [];
            document.querySelectorAll('#accountsGridNormal .account-select-card').forEach(card => {
                if (card.style.display !== 'none') {
                    const chk = card.querySelector('.account-checkbox');
                    if (chk) {
                        const isCheck = accList.includes(parseInt(chk.value));
                        chk.checked = isCheck;
                        if (isCheck) card.classList.add('active');
                        else card.classList.remove('active');
                    }
                }
            });

            ensureDefaultAccount('normal', scope);
            if (document.getElementById('checkCloudCustom')?.checked) {
                ensureDefaultAccount('cloud', scope);
            }
        }

        // 2. Điều khiển Chọn MXH
        function toggleSocialAll(chip, groupId) {
            const chk = chip.querySelector('input');
            chk.checked = !chk.checked;
            chip.classList.toggle('active', chk.checked);

            if (chk.checked) {
                document.querySelectorAll('#' + groupId + ' .social-chip').forEach(c => {
                    if (c !== chip) {
                        c.classList.remove('active');
                        c.querySelector('input').checked = false;
                    }
                });
            }
        }

        function toggleSocialSingle(chip, allChipId) {
            const chk = chip.querySelector('input');
            chk.checked = !chk.checked;
            chip.classList.toggle('active', chk.checked);

            const allChip = document.getElementById(allChipId);
            if (allChip) {
                allChip.classList.remove('active');
                allChip.querySelector('input').checked = false;
            }
        }

        // 3. Điều khiển Chọn Tài khoản đa luồng
        function toggleAccountCard(card, mode) {
            const chk = card.querySelector('.account-checkbox');
            if (chk) {
                chk.checked = !chk.checked;
                card.classList.toggle('active', chk.checked);
                updateMultiThreadStatus(mode);
            }
        }

        // Chọn tất cả các tài khoản đang hiển thị
        function selectAllAccounts(mode) {
            const gridId = (mode === 'cloud') ? 'accountsGridCloud' : 'accountsGridNormal';
            const grid = document.getElementById(gridId);
            if (!grid) return;

            grid.querySelectorAll('.account-select-card').forEach(card => {
                if (card.style.display !== 'none') {
                    const chk = card.querySelector('.account-checkbox');
                    if (chk) {
                        chk.checked = true;
                        card.classList.add('active');
                    }
                }
            });
            updateMultiThreadStatus(mode);
        }

        // Bỏ chọn tất cả các tài khoản đang hiển thị
        function deselectAllAccounts(mode) {
            const gridId = (mode === 'cloud') ? 'accountsGridCloud' : 'accountsGridNormal';
            const grid = document.getElementById(gridId);
            if (!grid) return;

            grid.querySelectorAll('.account-select-card').forEach(card => {
                if (card.style.display !== 'none') {
                    const chk = card.querySelector('.account-checkbox');
                    if (chk) {
                        chk.checked = false;
                        card.classList.remove('active');
                    }
                }
            });
            updateMultiThreadStatus(mode);
        }

        // Đảm bảo luôn có ít nhất 1 tài khoản ĐANG HIỂN THỊ được chọn
        function ensureDefaultAccount(mode, scope) {
            const gridId = (mode === 'cloud') ? 'accountsGridCloud' : 'accountsGridNormal';
            const grid = document.getElementById(gridId);
            if (!grid) return;

            const visibleCards = Array.from(grid.querySelectorAll('.account-select-card')).filter(card => {
                return card.style.display !== 'none';
            });

            if (visibleCards.length === 0) {
                updateMultiThreadStatus(mode);
                return;
            }

            const checkedVisible = visibleCards.filter(card => {
                const chk = card.querySelector('.account-checkbox');
                return chk && chk.checked;
            });

            // Nếu không có acc visible nào được chọn -> Chọn acc visible đầu tiên
            if (checkedVisible.length === 0) {
                const firstCard = visibleCards[0];
                const chk = firstCard.querySelector('.account-checkbox');
                if (chk) {
                    chk.checked = true;
                    firstCard.classList.add('active');
                }
            }

            updateMultiThreadStatus(mode);
        }

        // Cập nhật số lượng tài khoản đa luồng (chỉ tính acc đang hiển thị)
        function updateMultiThreadStatus(mode) {
            const gridId = (mode === 'cloud') ? 'accountsGridCloud' : 'accountsGridNormal';
            const badgeId = (mode === 'cloud') ? 'cloudMultiThreadBadge' : 'multiThreadBadge';
            const countId = (mode === 'cloud') ? 'cloudSelectedAccCount' : 'selectedAccCount';

            const grid = document.getElementById(gridId);
            if (!grid) return;

            let checkedCount = 0;
            grid.querySelectorAll('.account-select-card').forEach(card => {
                if (card.style.display !== 'none') {
                    const chk = card.querySelector('.account-checkbox');
                    if (chk && chk.checked) {
                        checkedCount++;
                    }
                }
            });

            const badge = document.getElementById(badgeId);
            const countEl = document.getElementById(countId);
            if (countEl) countEl.textContent = checkedCount;

            if (badge) {
                if (checkedCount >= 2) {
                    badge.classList.remove('d-none');
                } else {
                    badge.classList.add('d-none');
                }
            }
        }

        // 4. Sliders & Quick Buttons Handlers
        function updateJobLimit(val, suffix) {
            document.getElementById('badge' + suffix + 'JobLimit').textContent = val + ' jobs';
        }

        function setJobLimit(val, suffix) {
            const slider = document.getElementById('slider' + suffix + 'JobLimit');
            const chk = document.getElementById('check' + suffix + 'JobUnlimited');
            if (chk) chk.checked = false;
            if (slider) {
                slider.disabled = false;
                slider.value = val;
            }
            updateJobLimit(val, suffix);
        }

        function toggleJobUnlimited(isUnlimited, suffix) {
            const slider = document.getElementById('slider' + suffix + 'JobLimit');
            const badge = document.getElementById('badge' + suffix + 'JobLimit');
            if (isUnlimited) {
                if (slider) slider.disabled = true;
                if (badge) badge.textContent = 'Vô hạn (∞)';
            } else {
                if (slider) slider.disabled = false;
                if (badge && slider) badge.textContent = slider.value + ' jobs';
            }
        }

        function updateDelay(val, suffix) {
            document.getElementById('badge' + suffix + 'Delay').textContent = val + ' giây';
        }

        function setDelay(val, suffix) {
            const slider = document.getElementById('slider' + suffix + 'Delay');
            if (slider) {
                slider.value = val;
                updateDelay(val, suffix);
            }
        }

        function updateSwitchAcc(val, suffix) {
            document.getElementById('badge' + suffix + 'SwitchAcc').textContent = val + ' jobs';
        }

        function setSwitchAcc(val, suffix) {
            const slider = document.getElementById('slider' + suffix + 'SwitchAcc');
            const chk = document.getElementById('check' + suffix + 'SwitchNever');
            if (chk) chk.checked = false;
            if (slider) {
                slider.disabled = false;
                slider.value = val;
            }
            updateSwitchAcc(val, suffix);
        }

        function toggleSwitchNever(isNever, suffix) {
            const slider = document.getElementById('slider' + suffix + 'SwitchAcc');
            const badge = document.getElementById('badge' + suffix + 'SwitchAcc');
            if (isNever) {
                if (slider) slider.disabled = true;
                if (badge) badge.textContent = 'Không đổi';
            } else {
                if (slider) slider.disabled = false;
                if (badge && slider) badge.textContent = slider.value + ' jobs';
            }
        }

        function togglePriceFilter(isEnabled, suffix) {
            const boxId = suffix === 'Cloud' ? 'filterCloudPriceBox' : 'filterPriceBox';
            const contentId = suffix === 'Cloud' ? 'priceFilterContentCloud' : 'priceFilterContent';
            const box = document.getElementById(boxId);
            const content = document.getElementById(contentId);
            if (box) box.classList.toggle('enabled', isEnabled);
            if (content) content.classList.toggle('d-none', !isEnabled);
        }

        function setMinPrice(val, suffix) {
            const inputId = suffix === 'Cloud' ? 'inputMinPriceCloud' : 'inputMinPrice';
            const input = document.getElementById(inputId);
            if (input) input.value = val;
        }

        // Bật / tắt khung cấu hình Cloud VPS
        function toggleCloudConfig(isEnabled) {
            const box = document.getElementById('cloudConfigBox');
            const badge = document.getElementById('cloudStatusBadge');
            const panel = document.getElementById('cloudSubPanel');
            const desc = document.getElementById('cloudDescText');

            if (box) box.classList.toggle('active', isEnabled);
            if (panel) panel.classList.toggle('d-none', !isEnabled);

            if (badge) {
                badge.className = isEnabled ? 'badge bg-primary text-white rounded-pill px-3 py-1 text-nowrap' : 'badge bg-secondary-subtle text-muted rounded-pill px-3 py-1 text-nowrap';
                badge.textContent = isEnabled ? 'Đang cấu hình riêng' : 'Dùng chung mặc định';
            }

            if (desc) {
                desc.textContent = isEnabled 
                    ? 'Đang bật thiết lập riêng cho máy chủ Cloud VPS 24/24 bên dưới.' 
                    : 'Nếu không bật, hệ thống sẽ sử dụng cấu hình mặc định ở trên để treo ngầm.';
            }

            if (isEnabled) {
                const currentScope = document.getElementById('targetScopeInput')?.value || 'general';
                ensureDefaultAccount('cloud', currentScope);
            }
        }

        // Điều khiển Popup Profile
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

        // Dialog SVG
        const SVG_TEMPLATES = {
            confirm: `
                <svg class="svg-draw-icon svg-confirm" viewBox="0 0 80 80">
                    <circle cx="40" cy="40" r="34" stroke="#e0e7ff" stroke-width="4" fill="none" />
                    <circle cx="40" cy="40" r="34" stroke="#ef4444" stroke-width="4" fill="none" stroke-dasharray="213" stroke-dashoffset="0" />
                    <path d="M30,30 C30,22 50,22 50,32 C50,40 40,42 40,48" stroke="#ef4444" stroke-width="4" fill="none" stroke-linecap="round" />
                    <circle cx="40" cy="56" r="3" fill="#ef4444" />
                </svg>
            `
        };

        function confirmLogout() {
            const overlay = document.getElementById('svgDialogOverlay');
            const iconEl = document.getElementById('svgDialogIcon');
            const titleEl = document.getElementById('svgDialogTitle');
            const messageEl = document.getElementById('svgDialogMessage');
            const actionsEl = document.getElementById('svgDialogActions');

            iconEl.innerHTML = SVG_TEMPLATES.confirm;
            titleEl.textContent = 'Xác nhận đăng xuất';
            messageEl.innerHTML = 'Bạn có chắc chắn muốn đăng xuất khỏi hệ thống không?';
            actionsEl.innerHTML = `
                <button type="button" class="btn btn-light border px-4 rounded-pill" id="cancelBtn">Hủy bỏ</button>
                <a href="logout.php" class="btn btn-danger px-4 rounded-pill">Đăng xuất</a>
            `;

            overlay.classList.add('active');
            document.getElementById('cancelBtn').onclick = () => {
                overlay.classList.remove('active');
            };
        }

        // Kiểm tra trước khi submit form
        document.getElementById('formBotSettings').addEventListener('submit', function(e) {
            const currentScope = document.getElementById('targetScopeInput')?.value || 'general';
            ensureDefaultAccount('normal', currentScope);
            if (document.getElementById('checkCloudCustom').checked) {
                ensureDefaultAccount('cloud', currentScope);
            }
        });

        // Khởi tạo trạng thái ban đầu khi tải trang
        document.addEventListener('DOMContentLoaded', function() {
            const initialScope = '<?= $scopeParam ?>';
            filterAccountsByScope(initialScope);
        });
    </script>
</body>
</html>
