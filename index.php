<?php
/**
 * ==========================================================
 * TRANG TỔNG QUAN (DASHBOARD) - THỐNG KÊ, BIỂU ĐỒ & ĐUA TOP
 * File: index.php
 * Website: ThanhQuyTech
 * ==========================================================
 */

require_once __DIR__ . '/config/config.php';

require_login();

// Lấy thông tin tài khoản hiện tại
$stmtUser = $pdo->prepare("SELECT id, uid, uuid, name, username, email, balance, avatar, role, status FROM users WHERE id = ? LIMIT 1");
$stmtUser->execute([$_SESSION['user_id']]);
$currentUser = $stmtUser->fetch();

if (!$currentUser) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$isAdmin = ($currentUser['role'] === 'Admin');

// ==========================================================
// TỰ ĐỘNG NÂNG CẤP & ĐỒNG BỘ CƠ SỞ DỮ LIỆU CŨ (SELF-HEALING MIGRATION)
// Tự động kiểm tra và sửa lỗi nếu bảng cũ bị thiếu cột user_uuid
// ==========================================================
try {
    // 1. Đảm bảo bảng users có cột uuid chuẩn UUIDv7
    $uCols = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'uuid'")->fetchAll();
    if (empty($uCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `uuid` CHAR(36) NULL AFTER `uid`");
        $allUsers = $pdo->query("SELECT id FROM `users` WHERE `uuid` IS NULL OR `uuid` = ''")->fetchAll();
        foreach ($allUsers as $uRow) {
            $genUuid = generate_uuidv7();
            $upStmt = $pdo->prepare("UPDATE `users` SET `uuid` = ? WHERE `id` = ?");
            $upStmt->execute([$genUuid, $uRow['id']]);
        }
        $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `uuid` CHAR(36) NOT NULL");
        $pdo->exec("ALTER TABLE `users` ADD UNIQUE KEY `idx_users_uuid` (`uuid`)");
    }

    // 2. Kiểm tra và nâng cấp bảng rankings nếu thiếu cột user_uuid
    $checkRankings = $pdo->query("SHOW TABLES LIKE 'rankings'")->fetchAll();
    if (!empty($checkRankings)) {
        $rCols = $pdo->query("SHOW COLUMNS FROM `rankings` LIKE 'user_uuid'")->fetchAll();
        if (empty($rCols)) {
            $hasUserId = $pdo->query("SHOW COLUMNS FROM `rankings` LIKE 'user_id'")->fetchAll();
            if (!empty($hasUserId)) {
                $pdo->exec("ALTER TABLE `rankings` ADD COLUMN `user_uuid` CHAR(36) NULL AFTER `id`");
                $pdo->exec("UPDATE `rankings` r JOIN `users` u ON r.user_id = u.id SET r.user_uuid = u.uuid WHERE r.user_uuid IS NULL");
                $pdo->exec("DELETE FROM `rankings` WHERE `user_uuid` IS NULL");
                $pdo->exec("ALTER TABLE `rankings` MODIFY COLUMN `user_uuid` CHAR(36) NOT NULL");
            } else {
                // Xóa bảng cũ không tương thích để tạo lại đúng chuẩn
                $pdo->exec("DROP TABLE IF EXISTS `rankings`");
            }
        }
    }

    // 3. Kiểm tra và nâng cấp bảng password_resets nếu thiếu cột user_uuid
    $checkPR = $pdo->query("SHOW TABLES LIKE 'password_resets'")->fetchAll();
    if (!empty($checkPR)) {
        $prCols = $pdo->query("SHOW COLUMNS FROM `password_resets` LIKE 'user_uuid'")->fetchAll();
        if (empty($prCols)) {
            $pdo->exec("ALTER TABLE `password_resets` ADD COLUMN `user_uuid` CHAR(36) NULL AFTER `id`");
            $pdo->exec("UPDATE `password_resets` pr JOIN `users` u ON pr.email = u.email SET pr.user_uuid = u.uuid WHERE pr.user_uuid IS NULL");
            $pdo->exec("DELETE FROM `password_resets` WHERE `user_uuid` IS NULL");
            $pdo->exec("ALTER TABLE `password_resets` MODIFY COLUMN `user_uuid` CHAR(36) NOT NULL");
        }
    }
} catch (Exception $e) {
    // Tiếp tục xử lý
}

// Khởi tạo bảng nếu chưa có trong CSDL (khớp 100% cấu trúc database.sql)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `platforms` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(50) NOT NULL UNIQUE,
            `name` VARCHAR(100) NOT NULL,
            `icon` VARCHAR(50) NOT NULL DEFAULT 'fa-bolt',
            `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `rankings` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_uuid` CHAR(36) NOT NULL,
            `platform_id` INT NOT NULL,
            `amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
            `points` INT UNSIGNED NOT NULL DEFAULT 0,
            `date` DATE NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_ranking_date` (`date`),
            INDEX `idx_ranking_user_uuid` (`user_uuid`),
            INDEX `idx_ranking_platform` (`platform_id`),
            UNIQUE KEY `uq_user_platform_date` (`user_uuid`, `platform_id`, `date`),
            CONSTRAINT `fk_rankings_user_uuid` 
                FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`) 
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_rankings_platform_id` 
                FOREIGN KEY (`platform_id`) REFERENCES `platforms` (`id`) 
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `transactions` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_uuid` CHAR(36) NOT NULL,
            `code` VARCHAR(50) NOT NULL UNIQUE,
            `type` ENUM('Deposit', 'Withdraw', 'Payment', 'Refund') NOT NULL DEFAULT 'Deposit',
            `amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
            `balance_before` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
            `balance_after` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
            `status` ENUM('Pending', 'Success', 'Failed', 'Cancelled') NOT NULL DEFAULT 'Success',
            `note` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_trans_user_uuid` (`user_uuid`),
            INDEX `idx_trans_code` (`code`),
            CONSTRAINT `fk_transactions_user_uuid` 
                FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`) 
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        INSERT INTO `platforms` (`id`, `code`, `name`, `icon`, `status`) VALUES
        (1, 'golike', 'Golike', 'fa-bolt', 'Active'),
        (2, 'tuongtaccheo', 'Tương Tác Chéo', 'fa-share-nodes', 'Active'),
        (3, 'traodoisub', 'Trao Đổi Sub', 'fa-arrows-rotate', 'Active')
        ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `icon` = VALUES(`icon`);
    ");

    // Nạp dữ liệu xếp hạng mẫu nếu bảng rankings đang trống
    $countRankings = (int)$pdo->query("SELECT COUNT(*) FROM `rankings`")->fetchColumn();
    if ($countRankings === 0) {
        $firstUserUuid = $pdo->query("SELECT uuid FROM users LIMIT 1")->fetchColumn();
        if ($firstUserUuid) {
            $pdo->exec("
                INSERT INTO `rankings` (`user_uuid`, `platform_id`, `amount`, `points`, `date`) VALUES
                ('$firstUserUuid', 1, 350000.00, 1420, CURDATE()),
                ('$firstUserUuid', 2, 280000.00, 1150, CURDATE()),
                ('$firstUserUuid', 3, 195000.00, 890, CURDATE()),
                ('$firstUserUuid', 1, 410000.00, 1680, DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
                ('$firstUserUuid', 2, 320000.00, 1290, DATE_SUB(CURDATE(), INTERVAL 1 DAY))
                ON DUPLICATE KEY UPDATE `amount` = VALUES(`amount`);
            ");
        }
    }
} catch (Exception $e) {
    // Bỏ qua nếu bảng đã tồn tại hoặc đã có ràng buộc
}

// Xử lý Admin Bật / Tắt nền tảng
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_platform') {
    if (!$isAdmin) {
        set_flash('error', 'Bạn không có quyền thực hiện thao tác này.', 'Từ Chối Quyền');
        header("Location: index.php");
        exit;
    }

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Phiên làm việc hết hạn. Vui lòng thử lại.', 'Lỗi Xác Thực');
        header("Location: index.php");
        exit;
    }

    $platformId = (int)($_POST['platform_id'] ?? 0);
    $newStatus  = ($_POST['new_status'] === 'Active') ? 'Active' : 'Inactive';

    $stmtToggle = $pdo->prepare("UPDATE platforms SET status = ? WHERE id = ?");
    $stmtToggle->execute([$newStatus, $platformId]);

    set_flash('success', 'Đã ' . ($newStatus === 'Active' ? 'kích hoạt' : 'tắt') . ' nền tảng thành công!', 'Cập Nhật Nền Tảng');
    header("Location: index.php");
    exit;
}

// Lấy danh sách nền tảng
$platforms = $pdo->query("SELECT * FROM platforms ORDER BY id ASC")->fetchAll();

// Lọc theo nền tảng được chọn
$selectedPlatformCode = $_GET['platform'] ?? 'all';
$selectedPlatformId = null;

if ($selectedPlatformCode !== 'all') {
    foreach ($platforms as $p) {
        if ($p['code'] === $selectedPlatformCode) {
            if ($p['status'] === 'Active') {
                $selectedPlatformId = $p['id'];
            } else {
                $selectedPlatformCode = 'all';
            }
            break;
        }
    }
}

// Điều kiện lọc theo platform
$platformCondition = "";
$queryParams = [];
if ($selectedPlatformId !== null) {
    $platformCondition = " AND r.platform_id = ? ";
    $queryParams[] = $selectedPlatformId;
} else {
    // Chỉ tính các nền tảng đang kích hoạt (Active)
    $activePlatformIds = array_column(array_filter($platforms, fn($p) => $p['status'] === 'Active'), 'id');
    if (!empty($activePlatformIds)) {
        $inClause = implode(',', array_map('intval', $activePlatformIds));
        $platformCondition = " AND r.platform_id IN ($inClause) ";
    } else {
        $platformCondition = " AND 1=0 ";
    }
}

// ----------------------------------------------------------
// HÀM LẤY BẢNG XẾP HẠNG (NGÀY / TUẦN / THÁNG)
// ----------------------------------------------------------
function getTopRankings($pdo, $dateConditionSql, $platformConditionSql, $params, $limit = 5) {
    $sql = "
        SELECT 
            u.id as user_id, u.uuid, u.name, u.username, u.avatar, u.role,
            SUM(r.amount) as total_amount,
            SUM(r.points) as total_points
        FROM rankings r
        JOIN users u ON r.user_uuid = u.uuid
        WHERE $dateConditionSql $platformConditionSql
        GROUP BY u.id, u.uuid, u.name, u.username, u.avatar, u.role
        ORDER BY total_amount DESC, total_points DESC
        LIMIT $limit
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Top Ngày (Hôm nay: CURDATE())
$topDay = getTopRankings($pdo, "r.date = CURDATE()", $platformCondition, $queryParams);

// Top Tuần (Tuần hiện tại: Từ Thứ Hai đến Chủ Nhật theo chuẩn ISO 8601)
$topWeek = getTopRankings($pdo, "YEARWEEK(r.date, 1) = YEARWEEK(CURDATE(), 1)", $platformCondition, $queryParams);

// Top Tháng (Tháng hiện tại)
$topMonth = getTopRankings($pdo, "MONTH(r.date) = MONTH(CURDATE()) AND YEAR(r.date) = YEAR(CURDATE())", $platformCondition, $queryParams);

// ----------------------------------------------------------
// DỮ LIỆU BIỂU ĐỒ DOANH THU 7 NGÀY GẦN NHẤT
// ----------------------------------------------------------
$chartDates = [];
$chartAmounts = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartDates[] = date('d/m', strtotime($d));
    
    $stmtChart = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM rankings r 
        WHERE r.date = ? $platformCondition
    ");
    $chartParams = array_merge([$d], $queryParams);
    $stmtChart->execute($chartParams);
    $chartAmounts[] = (float)$stmtChart->fetchColumn();
}

// ----------------------------------------------------------
// DỮ LIỆU BIỂU ĐỒ TRÒN (TỶ TRỌNG CÁC NỀN TẢNG)
// ----------------------------------------------------------
$pieLabels = [];
$pieData = [];
$pieColors = ['#4f46e5', '#06b6d4', '#10b981', '#f59e0b', '#ec4899'];

foreach ($platforms as $idx => $p) {
    if ($p['status'] === 'Active') {
        $stmtPie = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM rankings 
            WHERE platform_id = ? AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())
        ");
        $stmtPie->execute([$p['id']]);
        $val = (float)$stmtPie->fetchColumn();
        $pieLabels[] = $p['name'];
        $pieData[] = $val;
    }
}

// Nếu chưa có dữ liệu biểu đồ tròn thì hiển thị mẫu tỷ trọng cân đối để biểu đồ hiện đẹp
if (array_sum($pieData) == 0) {
    $pieData = [45, 35, 20];
}

$csrfToken = get_csrf_token();
$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trang Tổng Quan & Đua Top - <?= htmlspecialchars(APP_NAME) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gradient-primary: linear-gradient(135deg, #4f46e5 0%, #6366f1 50%, #06b6d4 100%);
            --radius-md: 14px;
            --radius-lg: 20px;
            --shadow-card: 0 10px 30px -10px rgba(0, 0, 0, 0.05), 0 2px 8px rgba(0, 0, 0, 0.02);
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

        canvas {
            max-width: 100% !important;
        }

        /* ==========================================================
         * 1. HEADER CỐ ĐỊNH (FIXED TOPBAR) - CHỐNG TRƯỢT TREO KHI CUỘN
         * ========================================================== */
        .app-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
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
         * 2. SIDEBAR MENU CỐ ĐỊNH TRÁI
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

        .sidebar-icon {
            width: 22px;
            font-size: 1rem;
            display: inline-flex;
            justify-content: center;
            color: #64748b;
            transition: var(--transition);
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

        /* ==========================================================
         * 3. KHU VỰC NỘI DUNG CHÍNH (APP MAIN) - CHỐNG TRƯỢT TREO
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

        .dashboard-container-inner {
            max-width: 1320px;
            margin: 0 auto;
            width: 100%;
            min-width: 0;
            overflow-x: hidden !important;
        }

        /* Card Container */
        .dash-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 22px;
            box-shadow: var(--shadow-card);
            height: 100%;
            min-width: 0 !important;
            max-width: 100%;
            overflow: hidden !important;
        }

        .chart-canvas-wrapper {
            position: relative;
            width: 100% !important;
            max-width: 100% !important;
            height: 270px;
            min-width: 0 !important;
            overflow: hidden !important;
        }

        .doughnut-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Tabs / Pills lọc nền tảng */
        .platform-pills {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding-bottom: 8px;
            margin-bottom: 24px;
        }

        .platform-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 50px;
            background: #ffffff;
            border: 1px solid var(--card-border);
            color: var(--text-body);
            text-decoration: none;
            font-weight: 700;
            font-size: 0.88rem;
            white-space: nowrap;
            transition: var(--transition);
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }

        .platform-pill:hover {
            border-color: #cbd5e1;
            transform: translateY(-1px);
        }

        .platform-pill.active {
            background: var(--gradient-primary);
            color: #ffffff;
            border-color: transparent;
            box-shadow: 0 8px 20px -6px rgba(79, 70, 229, 0.45);
        }

        /* Phần thưởng Banner */
        .reward-banner {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: var(--radius-md);
            padding: 12px;
            margin-bottom: 16px;
        }

        .reward-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.8rem;
            padding: 4px 0;
        }

        .reward-item:not(:last-child) {
            border-bottom: 1px solid #f1f5f9;
        }

        .rank-badge {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.82rem;
        }

        .rank-1 { background: #fef08a; color: #854d0e; border: 2px solid #facc15; }
        .rank-2 { background: #e2e8f0; color: #475569; border: 2px solid #94a3b8; }
        .rank-3 { background: #fed7aa; color: #9a3412; border: 2px solid #fb923c; }
        .rank-other { background: #f1f5f9; color: #64748b; }

        /* Bảng Đua Top */
        .ranking-table {
            width: 100%;
            border-collapse: collapse;
        }

        .ranking-table th {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            padding: 8px 6px;
            border-bottom: 1px solid #f1f5f9;
        }

        .ranking-table td {
            padding: 10px 6px;
            vertical-align: middle;
            font-size: 0.88rem;
            border-bottom: 1px solid #f8fafc;
        }

        .user-mini {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .avatar-mini {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid var(--card-border);
        }

        /* Countdown Badge */
        .countdown-badge {
            background: #f1f5f9;
            color: #4f46e5;
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.78rem;
            font-family: monospace;
        }

        /* SVG Dialog */
        .svg-dialog-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            padding: 20px;
        }
        .svg-dialog-overlay.active { opacity: 1; visibility: visible; }
        .svg-dialog-card {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius-lg);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            padding: 36px 30px;
            text-align: center;
            transform: scale(0.85) translateY(20px);
            transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .svg-dialog-overlay.active .svg-dialog-card { transform: scale(1) translateY(0); }
        .svg-draw-icon {
            width: 80px; height: 80px; fill: none; stroke-width: 4.5;
            stroke-linecap: round; stroke-linejoin: round;
        }
        .svg-success .svg-circle {
            stroke: #10b981; stroke-dasharray: 220; stroke-dashoffset: 220;
            animation: strokeCircle 0.6s ease-out forwards;
        }
        .svg-success .svg-check {
            stroke: #10b981; stroke-dasharray: 60; stroke-dashoffset: 60;
            animation: strokeCheck 0.4s 0.45s ease-out forwards;
        }
        .svg-confirm .svg-circle {
            stroke: #0284c7; stroke-dasharray: 220; stroke-dashoffset: 220;
            animation: strokeCircle 0.6s ease-out forwards;
        }
        .svg-confirm .svg-question {
            stroke: #0284c7; stroke-dasharray: 60; stroke-dashoffset: 60;
            animation: strokeQuestion 0.4s 0.45s ease-out forwards;
        }
        .svg-confirm .svg-question-dot {
            fill: #0284c7; stroke: none; transform: scale(0);
            transform-origin: 40px 56px; animation: scaleDot 0.25s 0.75s forwards;
        }
        @keyframes strokeCircle { from { stroke-dashoffset: 220; } to { stroke-dashoffset: 0; } }
        @keyframes strokeCheck { from { stroke-dashoffset: 60; } to { stroke-dashoffset: 0; } }
        @keyframes strokeQuestion { from { stroke-dashoffset: 60; } to { stroke-dashoffset: 0; } }
        @keyframes scaleDot { from { transform: scale(0); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        /* ==========================================================
         * RESPONSIVE MOBILE, TABLET & DESKTOP SIDEBAR COLLAPSE
         * ========================================================== */
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

        @media (max-width: 991.98px) {
            .app-sidebar {
                position: fixed !important;
                top: 70px;
                left: 0;
                bottom: 0;
                width: 270px !important;
                max-width: 85vw !important;
                transform: translateX(-100%) !important;
                transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1) !important;
                z-index: 1050 !important;
                box-shadow: none;
            }
            .app-sidebar.sidebar-open {
                transform: translateX(0) !important;
                box-shadow: 4px 0 25px rgba(0, 0, 0, 0.2) !important;
                display: block !important;
                visibility: visible !important;
            }
            .sidebar-backdrop {
                z-index: 1045 !important;
            }
            .app-main {
                margin-left: 0 !important;
                margin-top: 56px !important;
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
                padding: 12px 6px 50px !important;
                overflow-x: hidden !important;
                box-sizing: border-box !important;
            }
            .dashboard-container-inner {
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
                overflow-x: hidden !important;
                box-sizing: border-box !important;
            }
            .row, .row.g-3 {
                --bs-gutter-x: 0 !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            .row > *, .col-lg-8, .col-lg-4 {
                padding-left: 0 !important;
                padding-right: 0 !important;
                min-width: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }
        }

        @media (max-width: 767.98px) {
            html, body {
                width: 100% !important;
                max-width: 100% !important;
                overflow-x: hidden !important;
                touch-action: pan-y !important;
            }
            .app-header {
                height: 56px !important;
                padding: 0 8px !important;
                width: 100% !important;
                max-width: 100% !important;
                left: 0 !important;
                right: 0 !important;
            }
            .app-sidebar {
                top: 56px;
            }
            .header-left {
                gap: 4px !important;
                min-width: 0 !important;
                flex-shrink: 1 !important;
            }
            .brand-logo {
                gap: 4px !important;
                min-width: 0 !important;
            }
            .brand-icon {
                width: 26px !important;
                height: 26px !important;
                font-size: 0.78rem !important;
                border-radius: 6px !important;
                flex-shrink: 0 !important;
            }
            .brand-name {
                font-size: 0.86rem !important;
                letter-spacing: -0.3px !important;
                font-weight: 700 !important;
            }
            .brand-tech-suffix {
                display: none !important;
            }
            .sidebar-toggle-btn {
                width: 30px !important;
                height: 30px !important;
                font-size: 0.85rem !important;
                border-radius: 7px !important;
                flex-shrink: 0 !important;
            }
            .header-right {
                gap: 5px !important;
                flex-shrink: 0 !important;
            }
            .header-balance-card {
                padding: 2px 6px !important;
                gap: 4px !important;
                border-radius: 20px !important;
                background: #f0fdf4 !important;
                border: 1px solid #bbf7d0 !important;
            }
            .balance-wallet-icon {
                width: 18px !important;
                height: 18px !important;
                font-size: 0.62rem !important;
                flex-shrink: 0 !important;
            }
            .balance-text-group {
                display: flex !important;
                flex-direction: column !important;
                line-height: 1 !important;
            }
            .balance-title {
                display: none !important;
            }
            .balance-val {
                font-size: 0.72rem !important;
                white-space: nowrap !important;
                font-weight: 800 !important;
            }
            .balance-add-btn {
                display: none !important;
            }
            .user-profile-toggle {
                padding: 0 !important;
                border-radius: 50% !important;
                background: transparent !important;
                border: 1px solid #cbd5e1 !important;
                box-shadow: none !important;
                gap: 0 !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                cursor: pointer !important;
            }
            .user-avatar-small {
                width: 28px !important;
                height: 28px !important;
                border-width: 1.5px !important;
                flex-shrink: 0 !important;
                pointer-events: none !important;
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
            .dash-card {
                padding: 12px 10px !important;
                border-radius: 14px !important;
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
                box-sizing: border-box !important;
                overflow: hidden !important;
            }
            .dash-card h4, .dash-card h5 {
                font-size: 0.96rem !important;
                white-space: normal !important;
                word-break: break-word !important;
                overflow-wrap: break-word !important;
                line-height: 1.3 !important;
            }
            .dash-card small, .dash-card p {
                font-size: 0.74rem !important;
                white-space: normal !important;
                word-break: break-word !important;
                overflow-wrap: break-word !important;
            }
            .chart-canvas-wrapper {
                height: 200px !important;
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
                overflow: hidden !important;
            }
            .platform-pills {
                margin-bottom: 12px !important;
                gap: 5px !important;
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
                padding-bottom: 4px !important;
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                touch-action: pan-x pan-y !important;
            }
            .platform-pill {
                padding: 5px 10px !important;
                font-size: 0.76rem !important;
                flex-shrink: 0 !important;
                white-space: nowrap !important;
            }
            .table-responsive {
                width: 100% !important;
                max-width: 100% !important;
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                touch-action: pan-x pan-y !important;
            }
            .ranking-table {
                min-width: 260px !important;
            }
        }

        @media (max-width: 360px) {
            .app-header {
                padding: 0 4px !important;
            }
            .brand-name {
                font-size: 0.82rem !important;
            }
            .header-balance-card {
                padding: 2px 4px !important;
                gap: 3px !important;
            }
            .balance-val {
                font-size: 0.68rem !important;
            }
            .user-avatar-small {
                width: 26px !important;
                height: 26px !important;
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
     * HEADER CỐ ĐỊNH (FIXED TOPBAR) - CHỐNG TRƯỢT TREO KHI CUỘN
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
                        <?php if ($isAdmin): ?>
                        <button type="button" class="popup-menu-item text-start border-0 bg-transparent w-100" data-bs-toggle="modal" data-bs-target="#adminPlatformModal" onclick="closeUserPopup()">
                            <i class="fa-solid fa-sliders text-info me-2"></i> Quản trị nền tảng
                        </button>
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
     * MENU SIDEBAR CỐ ĐỊNH TRÁI
     * ========================================================== -->
    <aside class="app-sidebar" id="appSidebar">
        <div class="sidebar-category">BẢNG ĐIỀU KHIỂN</div>
        <ul class="sidebar-nav-list">
            <!-- Trang chủ -->
            <li>
                <a href="index.php" class="sidebar-link active">
                    <span class="sidebar-icon"><i class="fa-solid fa-house"></i></span>
                    <span class="sidebar-title">Trang chủ</span>
                </a>
            </li>
            <!-- Mua key -->
            <li>
                <a href="buy-key.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-key"></i></span>
                    <span class="sidebar-title">Mua key</span>
                </a>
            </li>
            <!-- Thuê cloud -->
            <li>
                <a href="cloud.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-cloud"></i></span>
                    <span class="sidebar-title">Thuê cloud</span>
                </a>
            </li>
        </ul>

        <div class="sidebar-category">CÔNG CỤ & DỊCH VỤ</div>
        <ul class="sidebar-nav-list">
            <!-- Tool Golike (có menu sổ xuống) -->
            <li>
                <button class="sidebar-link collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#submenuGolike" aria-expanded="false">
                    <span class="sidebar-icon"><i class="fa-solid fa-robot"></i></span>
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

            <!-- Account (có menu sổ xuống) -->
            <li>
                <button class="sidebar-link collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#submenuAccount" aria-expanded="false">
                    <span class="sidebar-icon"><i class="fa-solid fa-users-gear"></i></span>
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

            <!-- Payment (có menu sổ xuống) -->
            <li>
                <button class="sidebar-link collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#submenuPayment" aria-expanded="false">
                    <span class="sidebar-icon"><i class="fa-solid fa-credit-card"></i></span>
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
                    <span class="sidebar-icon"><i class="fa-solid fa-share-nodes"></i></span>
                    <span class="sidebar-title">Giới thiệu</span>
                    <span class="badge-history ms-auto"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                </a>
            </li>
            <!-- Hỗ trợ -->
            <li>
                <a href="/support" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-headset"></i></span>
                    <span class="sidebar-title">Hỗ trợ</span>
                    <span class="badge-history ms-auto"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                </a>
            </li>
            <?php if ($isAdmin): ?>
            <!-- Quản trị nền tảng (Admin) -->
            <li>
                <button type="button" class="sidebar-link text-primary" data-bs-toggle="modal" data-bs-target="#adminPlatformModal">
                    <span class="sidebar-icon"><i class="fa-solid fa-sliders"></i></span>
                    <span class="sidebar-title">Quản trị nền tảng</span>
                </button>
            </li>
            <!-- Admin Panel (Chỉ hiển thị cho Admin - nằm dưới Quản trị nền tảng) -->
            <li>
                <a href="/admin/dashboard" class="sidebar-link text-danger fw-bold">
                    <span class="sidebar-icon text-danger"><i class="fa-solid fa-shield-halved"></i></span>
                    <span class="sidebar-title">Admin Panel</span>
                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 ms-auto" style="font-size: 0.65rem; padding: 2px 7px;">Admin</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </aside>

    <!-- Lớp nền mờ cho Sidebar trên điện thoại -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- KHU VỰC NỘI DUNG CHÍNH (APP MAIN) -->
    <main class="app-main">
        <div class="dashboard-container-inner">

        <!-- Bộ Lọc Nền Tảng (Tabs) -->
        <div class="platform-pills">
            <a href="index.php?platform=all" class="platform-pill <?= ($selectedPlatformCode === 'all') ? 'active' : '' ?>">
                <i class="fa-solid fa-layer-group"></i>
                <span>Tổng quan (Tất cả nền tảng)</span>
            </a>
            <?php foreach ($platforms as $p): ?>
                <?php if ($p['status'] === 'Active'): ?>
                    <a href="index.php?platform=<?= htmlspecialchars($p['code']) ?>" class="platform-pill <?= ($selectedPlatformCode === $p['code']) ? 'active' : '' ?>">
                        <i class="fa-solid <?= htmlspecialchars($p['icon']) ?>"></i>
                        <span><?= htmlspecialchars($p['name']) ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <!-- Hàng Biểu Đồ & Thống Kê -->
        <div class="row g-3 mb-4">
            <!-- Biểu đồ cột/đường 7 ngày -->
            <div class="col-lg-8">
                <div class="dash-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h5 class="fw-bold mb-1 text-dark">
                                <i class="fa-solid fa-chart-line text-primary me-2"></i>Biểu đồ sản lượng (7 ngày gần nhất)
                            </h5>
                            <small class="text-muted">Theo dõi tổng doanh số đua top qua từng ngày</small>
                        </div>
                    </div>
                    <div class="chart-canvas-wrapper">
                        <canvas id="lineChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Biểu đồ tròn tỷ trọng các nền tảng -->
            <div class="col-lg-4">
                <div class="dash-card">
                    <div class="mb-3">
                        <h5 class="fw-bold mb-1 text-dark">
                            <i class="fa-solid fa-chart-pie text-info me-2"></i>Tỷ trọng nền tảng
                        </h5>
                        <small class="text-muted">Phần trăm thị phần trong tháng</small>
                    </div>
                    <div class="chart-canvas-wrapper doughnut-wrapper">
                        <canvas id="doughnutChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tiêu đề phần Bảng Xếp Hạng -->
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
                <h4 class="fw-bold text-dark mb-1">
                    <i class="fa-solid fa-trophy text-warning me-2"></i>Bảng xếp hạng & Cơ cấu thưởng
                </h4>
                <p class="text-muted small mb-0">Hệ thống tự động lọc danh sách và trao thưởng tại mốc 00:00 của mỗi chu kỳ</p>
            </div>
        </div>

        <!-- Grid 3 Bảng: Top Ngày, Top Tuần, Top Tháng -->
        <div class="row g-3">
            <!-- ================= TOP NGÀY ================= -->
            <div class="col-lg-4">
                <div class="dash-card">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-calendar-day text-danger me-1"></i> Top Ngày
                        </h5>
                        <span class="countdown-badge" id="countdownDay">00:00:00</span>
                    </div>
                    <div class="text-muted small mb-3">
                        <i class="fa-regular fa-clock me-1"></i> Reset vào <strong>00:00</strong> mỗi ngày
                    </div>

                    <!-- Cơ cấu thưởng Ngày -->
                    <div class="reward-banner">
                        <div class="fw-bold text-dark small mb-2"><i class="fa-solid fa-gift text-warning me-1"></i> Phần thưởng:</div>
                        <div class="reward-item">
                            <span>🥇 <strong>Top 1:</strong> Key 1 Days</span>
                            <span class="badge bg-primary bg-opacity-10 text-primary">+ Cloud 24h</span>
                        </div>
                        <div class="reward-item">
                            <span>🥈 <strong>Top 2:</strong> Key 12H</span>
                            <span class="badge bg-primary bg-opacity-10 text-primary">+ Cloud 12h</span>
                        </div>
                        <div class="reward-item">
                            <span>🥉 <strong>Top 3:</strong> Key 6H</span>
                            <span class="badge bg-primary bg-opacity-10 text-primary">+ Cloud 6h</span>
                        </div>
                    </div>

                    <!-- Bảng người dùng Top Ngày -->
                    <div class="table-responsive">
                        <table class="ranking-table">
                            <thead>
                                <tr>
                                    <th style="width: 38px;">Hạng</th>
                                    <th>Thành viên</th>
                                    <th class="text-end">Doanh số</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($topDay)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-4 small">
                                            <i class="fa-solid fa-hourglass-start me-1"></i> Chưa có dữ liệu đua top ngày hôm nay.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($topDay as $idx => $row): ?>
                                        <tr>
                                            <td>
                                                <span class="rank-badge <?= ($idx === 0) ? 'rank-1' : (($idx === 1) ? 'rank-2' : (($idx === 2) ? 'rank-3' : 'rank-other')) ?>">
                                                    <?= $idx + 1 ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="user-mini">
                                                    <img src="<?= htmlspecialchars($row['avatar']) ?>" class="avatar-mini">
                                                    <div>
                                                        <strong class="d-block text-dark"><?= htmlspecialchars($row['name']) ?></strong>
                                                        <small class="text-muted"><?= htmlspecialchars($row['username']) ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <strong class="text-primary"><?= format_currency($row['total_amount']) ?></strong>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ================= TOP TUẦN ================= -->
            <div class="col-lg-4">
                <div class="dash-card">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-calendar-week text-primary me-1"></i> Top Tuần
                        </h5>
                        <span class="countdown-badge" id="countdownWeek">--:--:--</span>
                    </div>
                    <div class="text-muted small mb-3">
                        <i class="fa-regular fa-clock me-1"></i> Reset vào <strong>00:00 Thứ 2</strong> hàng tuần
                    </div>

                    <!-- Cơ cấu thưởng Tuần -->
                    <div class="reward-banner">
                        <div class="fw-bold text-dark small mb-2"><i class="fa-solid fa-gift text-warning me-1"></i> Phần thưởng:</div>
                        <div class="reward-item">
                            <span>🥇 <strong>Top 1:</strong> Key 3 Days</span>
                            <span class="badge bg-primary bg-opacity-10 text-primary">+ Cloud 72h (3d)</span>
                        </div>
                        <div class="reward-item">
                            <span>🥈 <strong>Top 2:</strong> Key 2 Days</span>
                            <span class="badge bg-primary bg-opacity-10 text-primary">+ Cloud 48h (2d)</span>
                        </div>
                        <div class="reward-item">
                            <span>🥉 <strong>Top 3:</strong> Key 1 Days</span>
                            <span class="badge bg-primary bg-opacity-10 text-primary">+ Cloud 24h (1d)</span>
                        </div>
                    </div>

                    <!-- Bảng người dùng Top Tuần -->
                    <div class="table-responsive">
                        <table class="ranking-table">
                            <thead>
                                <tr>
                                    <th style="width: 38px;">Hạng</th>
                                    <th>Thành viên</th>
                                    <th class="text-end">Doanh số</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($topWeek)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-4 small">
                                            <i class="fa-solid fa-hourglass-start me-1"></i> Chưa có dữ liệu đua top tuần này.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($topWeek as $idx => $row): ?>
                                        <tr>
                                            <td>
                                                <span class="rank-badge <?= ($idx === 0) ? 'rank-1' : (($idx === 1) ? 'rank-2' : (($idx === 2) ? 'rank-3' : 'rank-other')) ?>">
                                                    <?= $idx + 1 ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="user-mini">
                                                    <img src="<?= htmlspecialchars($row['avatar']) ?>" class="avatar-mini">
                                                    <div>
                                                        <strong class="d-block text-dark"><?= htmlspecialchars($row['name']) ?></strong>
                                                        <small class="text-muted"><?= htmlspecialchars($row['username']) ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <strong class="text-primary"><?= format_currency($row['total_amount']) ?></strong>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ================= TOP THÁNG ================= -->
            <div class="col-lg-4">
                <div class="dash-card">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-calendar-days text-success me-1"></i> Top Tháng
                        </h5>
                        <span class="countdown-badge" id="countdownMonth">--:--:--</span>
                    </div>
                    <div class="text-muted small mb-3">
                        <i class="fa-regular fa-clock me-1"></i> Reset vào <strong>00:00 ngày 1</strong> tháng mới
                    </div>

                    <!-- Cơ cấu thưởng Tháng -->
                    <div class="reward-banner">
                        <div class="fw-bold text-dark small mb-2"><i class="fa-solid fa-gift text-warning me-1"></i> Phần thưởng:</div>
                        <div class="reward-item">
                            <span>🥇 <strong>Top 1:</strong> Key 7 Days</span>
                            <span class="badge bg-primary bg-opacity-10 text-primary">+ Cloud 168h (7d)</span>
                        </div>
                        <div class="reward-item">
                            <span>🥈 <strong>Top 2:</strong> Key 5 Days</span>
                            <span class="badge bg-primary bg-opacity-10 text-primary">+ Cloud 120h (5d)</span>
                        </div>
                        <div class="reward-item">
                            <span>🥉 <strong>Top 3:</strong> Key 3 Days</span>
                            <span class="badge bg-primary bg-opacity-10 text-primary">+ Cloud 72h (3d)</span>
                        </div>
                    </div>

                    <!-- Bảng người dùng Top Tháng -->
                    <div class="table-responsive">
                        <table class="ranking-table">
                            <thead>
                                <tr>
                                    <th style="width: 38px;">Hạng</th>
                                    <th>Thành viên</th>
                                    <th class="text-end">Doanh số</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($topMonth)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-4 small">
                                            <i class="fa-solid fa-hourglass-start me-1"></i> Chưa có dữ liệu đua top tháng này.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($topMonth as $idx => $row): ?>
                                        <tr>
                                            <td>
                                                <span class="rank-badge <?= ($idx === 0) ? 'rank-1' : (($idx === 1) ? 'rank-2' : (($idx === 2) ? 'rank-3' : 'rank-other')) ?>">
                                                    <?= $idx + 1 ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="user-mini">
                                                    <img src="<?= htmlspecialchars($row['avatar']) ?>" class="avatar-mini">
                                                    <div>
                                                        <strong class="d-block text-dark"><?= htmlspecialchars($row['name']) ?></strong>
                                                        <small class="text-muted"><?= htmlspecialchars($row['username']) ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <strong class="text-primary"><?= format_currency($row['total_amount']) ?></strong>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        </div> <!-- /dashboard-container-inner -->
    </main> <!-- /app-main -->

    <!-- Modal Admin Bật/Tắt Nền Tảng (Chỉ Admin mới có quyền) -->
    <?php if ($isAdmin): ?>
        <div class="modal fade" id="adminPlatformModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg rounded-4">
                    <div class="modal-header border-bottom-0 pb-0">
                        <h5 class="modal-title fw-bold text-dark">
                            <i class="fa-solid fa-sliders text-primary me-2"></i> Quản trị nền tảng đua top
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body py-4">
                        <p class="text-muted small mb-3">
                            Bật hoặc tắt các nền tảng. Khi tắt một nền tảng, các thành viên thông thường sẽ không thể xem và tính sản lượng của nền tảng đó.
                        </p>
                        <div class="list-group list-group-flush border rounded-3 overflow-hidden">
                            <?php foreach ($platforms as $p): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="p-2 bg-light rounded-circle text-primary" style="width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;">
                                            <i class="fa-solid <?= htmlspecialchars($p['icon']) ?>"></i>
                                        </div>
                                        <div>
                                            <strong class="text-dark d-block"><?= htmlspecialchars($p['name']) ?></strong>
                                            <small class="text-muted">Mã: <?= htmlspecialchars($p['code']) ?></small>
                                        </div>
                                    </div>
                                    <form method="POST" action="index.php" style="margin: 0;">
                                        <input type="hidden" name="action" value="toggle_platform">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="platform_id" value="<?= $p['id'] ?>">
                                        <?php if ($p['status'] === 'Active'): ?>
                                            <input type="hidden" name="new_status" value="Inactive">
                                            <button type="submit" class="btn btn-sm btn-success rounded-pill px-3">
                                                <i class="fa-solid fa-toggle-on me-1"></i> Đang Bật
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="new_status" value="Active">
                                            <button type="submit" class="btn btn-sm btn-secondary rounded-pill px-3">
                                                <i class="fa-solid fa-toggle-off me-1"></i> Đã Tắt
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // ==========================================================
        // KHỞI TẠO BIỂU ĐỒ CHART.JS
        // ==========================================================
        // 1. Biểu đồ dạng sóng (Wave Area Spline Chart) 7 ngày gần nhất
        const lineCanvas = document.getElementById('lineChart');
        const lineCtx = lineCanvas.getContext('2d');

        // Tạo dải màu gradient sóng nước mềm mại
        const waveGradient = lineCtx.createLinearGradient(0, 0, 0, 260);
        waveGradient.addColorStop(0, 'rgba(79, 70, 229, 0.40)');
        waveGradient.addColorStop(0.55, 'rgba(99, 102, 241, 0.12)');
        waveGradient.addColorStop(1, 'rgba(99, 102, 241, 0.00)');

        new Chart(lineCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chartDates) ?>,
                datasets: [{
                    label: 'Sản lượng (VNĐ)',
                    data: <?= json_encode($chartAmounts) ?>,
                    borderColor: '#4f46e5',
                    borderWidth: 3,
                    backgroundColor: waveGradient,
                    fill: true,
                    tension: 0.42, // Đường cong sóng tự nhiên
                    cubicInterpolationMode: 'monotone',
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#4f46e5',
                    pointBorderWidth: 2.5,
                    pointRadius: 4,
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: '#4f46e5',
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 2.5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.92)',
                        titleFont: { size: 12, weight: '700' },
                        bodyFont: { size: 13, weight: '600' },
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return ' ' + new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(context.raw);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            font: { size: 11, weight: '600' },
                            color: '#64748b'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#f1f5f9',
                            drawBorder: false
                        },
                        ticks: {
                            font: { size: 11 },
                            color: '#94a3b8',
                            callback: function(value) {
                                return value >= 1000000 ? (value/1000000) + 'M' : (value >= 1000 ? (value/1000) + 'k' : value);
                            }
                        }
                    }
                }
            }
        });

        // 2. Biểu đồ tròn tỷ trọng
        const pieCtx = document.getElementById('doughnutChart').getContext('2d');
        new Chart(pieCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($pieLabels) ?>,
                datasets: [{
                    data: <?= json_encode($pieData) ?>,
                    backgroundColor: <?= json_encode(array_slice($pieColors, 0, count($pieLabels))) ?>,
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } }
                },
                cutout: '70%'
            }
        });

        // ==========================================================
        // ĐỒNG HỒ ĐẾM NGƯỢC THỜI GIAN RESET (NGÀY, TUẦN, THÁNG)
        // ==========================================================
        function updateCountdowns() {
            const now = new Date();

            // 1. Reset Ngày: 00:00 ngày hôm sau
            const tomorrow = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 0, 0, 0);
            const diffDay = tomorrow - now;
            document.getElementById('countdownDay').textContent = formatDiff(diffDay);

            // 2. Reset Tuần: 00:00 Thứ Hai tuần sau
            const dayOfWeek = now.getDay(); // 0 là Chủ Nhật, 1 là Thứ Hai...
            const daysUntilMonday = (dayOfWeek === 0) ? 1 : (8 - dayOfWeek);
            const nextMonday = new Date(now.getFullYear(), now.getMonth(), now.getDate() + daysUntilMonday, 0, 0, 0);
            const diffWeek = nextMonday - now;
            document.getElementById('countdownWeek').textContent = formatDiff(diffWeek, true);

            // 3. Reset Tháng: 00:00 ngày 1 của tháng kế tiếp
            const nextMonth = new Date(now.getFullYear(), now.getMonth() + 1, 1, 0, 0, 0);
            const diffMonth = nextMonth - now;
            document.getElementById('countdownMonth').textContent = formatDiff(diffMonth, true);
        }

        function formatDiff(ms, showDays = false) {
            if (ms <= 0) return "00:00:00";
            const totalSeconds = Math.floor(ms / 1000);
            const days = Math.floor(totalSeconds / 86400);
            const hours = Math.floor((totalSeconds % 86400) / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const seconds = totalSeconds % 60;

            const pad = (n) => String(n).padStart(2, '0');
            if (showDays && days > 0) {
                return `${days}d ${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
            }
            return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
        }

        setInterval(updateCountdowns, 1000);
        updateCountdowns();

        // ==========================================================
        // DIALOG XÁC NHẬN ĐĂNG XUẤT HIỆU ỨNG SVG
        // ==========================================================
        const SVG_TEMPLATES = {
            success: `
                <svg class="svg-draw-icon svg-success" viewBox="0 0 80 80">
                    <circle class="svg-circle" cx="40" cy="40" r="34" />
                    <polyline class="svg-check" points="24,42 35,53 56,28" />
                </svg>
            `,
            confirm: `
                <svg class="svg-draw-icon svg-confirm" viewBox="0 0 80 80">
                    <circle class="svg-circle" cx="40" cy="40" r="34" />
                    <path class="svg-question" d="M30,30 C30,22 50,22 50,32 C50,40 40,42 40,48" />
                    <circle class="svg-question-dot" cx="40" cy="56" r="3" />
                </svg>
            `
        };

        function showSvgAlert(message, title = 'Thông báo', type = 'success') {
            const overlay = document.getElementById('svgDialogOverlay');
            const iconEl = document.getElementById('svgDialogIcon');
            const titleEl = document.getElementById('svgDialogTitle');
            const messageEl = document.getElementById('svgDialogMessage');
            const actionsEl = document.getElementById('svgDialogActions');

            iconEl.innerHTML = SVG_TEMPLATES[type] || SVG_TEMPLATES.success;
            titleEl.textContent = title;
            messageEl.innerHTML = message;
            actionsEl.innerHTML = `
                <button type="button" class="btn btn-primary px-4" id="svgCloseBtn">
                    <i class="fa-solid fa-check me-1"></i> Xác nhận
                </button>
            `;

            overlay.classList.add('active');
            document.getElementById('svgCloseBtn').onclick = () => {
                overlay.classList.remove('active');
            };
        }

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
                <button type="button" class="btn btn-light border px-4" id="cancelBtn">Hủy bỏ</button>
                <button type="button" class="btn btn-danger px-4" id="confirmBtn">Đăng xuất</button>
            `;

            overlay.classList.add('active');
            document.getElementById('cancelBtn').onclick = () => {
                overlay.classList.remove('active');
            };
            document.getElementById('confirmBtn').onclick = () => {
                window.location.href = 'logout.php';
            };
        }

        // ==========================================================
        // ĐIỀU KHIỂN ĐÓNG/MỞ SIDEBAR (MOBILE & DESKTOP)
        // ==========================================================
        const sidebarToggle = document.getElementById('sidebarToggle');
        const appSidebar = document.getElementById('appSidebar');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');

        if (sidebarToggle && appSidebar && sidebarBackdrop) {
            sidebarToggle.addEventListener('click', () => {
                appSidebar.classList.toggle('sidebar-open');
                sidebarBackdrop.classList.toggle('active');
            });

            sidebarBackdrop.addEventListener('click', () => {
                appSidebar.classList.remove('sidebar-open');
                sidebarBackdrop.classList.remove('active');
            });
        }

        // ==========================================================
        // ĐIỀU KHIỂN BẬT/TẮT BẢNG POPUP HỒ SƠ (THUẦN JS 100% NHẠY)
        // ==========================================================
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

        // Đóng popup khi bấm ra ngoài hoặc cuộn
        document.addEventListener('click', function (e) {
            const container = document.getElementById('userDropdownContainer');
            if (container && !container.contains(e.target)) {
                closeUserPopup();
            }
        });

        // Đóng khi bấm phím Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeUserPopup();
            }
        });

        <?php if (!empty($flash)): ?>
            document.addEventListener('DOMContentLoaded', () => {
                showSvgAlert(
                    <?= json_encode($flash['message']) ?>,
                    <?= json_encode(!empty($flash['title']) ? $flash['title'] : 'Thông báo') ?>,
                    <?= json_encode($flash['type']) ?>
                );
            });
        <?php endif; ?>
    </script>
</body>
</html>
