<?php
/**
 * ==========================================================
 * TRANG GIỚI THIỆU BẠN BÈ - TÍCH LŨY KEY VIP GOLIKE
 * File: referral.php
 * Website: ThanhQuyTech
 * Cơ chế: 1 người tham gia = 1 ngày Key VIP (Đánh dấu chống nhận trùng lặp)
 * ==========================================================
 */

require_once __DIR__ . '/config/config.php';

require_login();

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

// Tự động kiểm tra và nâng cấp bảng referrals & referral_claims nếu chưa có cột mới
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `referrals` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `referrer_uuid` CHAR(36) NOT NULL,
            `referee_uuid` CHAR(36) NOT NULL UNIQUE,
            `reward_days` INT UNSIGNED NOT NULL DEFAULT 1,
            `is_claimed` TINYINT(1) NOT NULL DEFAULT 0,
            `claimed_at` DATETIME DEFAULT NULL,
            `claim_order_code` VARCHAR(50) DEFAULT NULL,
            `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_ref_referrer` (`referrer_uuid`),
            INDEX `idx_ref_referee` (`referee_uuid`),
            INDEX `idx_ref_is_claimed` (`is_claimed`),
            INDEX `idx_ref_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `referral_claims` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_uuid` CHAR(36) NOT NULL,
            `claim_code` VARCHAR(50) NOT NULL UNIQUE,
            `referred_count` INT UNSIGNED NOT NULL,
            `reward_days` INT UNSIGNED NOT NULL,
            `license_key` VARCHAR(100) NOT NULL UNIQUE,
            `expires_at` DATETIME NOT NULL,
            `status` ENUM('Active', 'Expired') NOT NULL DEFAULT 'Active',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_rc_user_uuid` (`user_uuid`),
            INDEX `idx_rc_claim_code` (`claim_code`),
            INDEX `idx_rc_license_key` (`license_key`),
            INDEX `idx_rc_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Đảm bảo các cột mới tồn tại nếu bảng cũ đã tạo trước đó
    $colCheck = $pdo->query("SHOW COLUMNS FROM `referrals` LIKE 'is_claimed'")->fetch();
    if (!$colCheck) {
        $pdo->exec("
            ALTER TABLE `referrals` 
            ADD COLUMN `reward_days` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `referee_uuid`,
            ADD COLUMN `is_claimed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `reward_days`,
            ADD COLUMN `claimed_at` DATETIME DEFAULT NULL AFTER `is_claimed`,
            ADD COLUMN `claim_order_code` VARCHAR(50) DEFAULT NULL AFTER `claimed_at`,
            ADD INDEX `idx_ref_is_claimed` (`is_claimed`);
        ");
    }
} catch (Exception $e) {
    // Bỏ qua nếu bảng đã chuẩn
}

// XỬ LÝ QUY ĐỔI KEY VIP: 1 NGƯỜI = 1 NGÀY KEY VIP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'claim_key_reward') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Mã xác thực CSRF không hợp lệ hoặc đã hết hạn.', 'Lỗi xác thực');
        header("Location: referral.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Khóa và lấy danh sách các F1 CHƯA ĐƯỢC QUY ĐỔI (is_claimed = 0)
        $stmtUnclaimed = $pdo->prepare("
            SELECT id, referee_uuid, reward_days 
            FROM referrals 
            WHERE referrer_uuid = ? AND is_claimed = 0 AND status = 'Active'
            FOR UPDATE
        ");
        $stmtUnclaimed->execute([$currentUser['uuid']]);
        $unclaimedList = $stmtUnclaimed->fetchAll();

        $unclaimedCount = count($unclaimedList);

        if ($unclaimedCount <= 0) {
            $pdo->rollBack();
            set_flash('warning', 'Bạn chưa có thành viên giới thiệu mới nào để quy đổi Key VIP. Hãy chia sẻ thêm link cho bạn bè!', 'Chưa có lượt quy đổi');
            header("Location: referral.php");
            exit;
        }

        // 2. Tính số ngày Key VIP tương ứng (1 người = 1 ngày)
        $totalDays = 0;
        $refIdsToUpdate = [];
        foreach ($unclaimedList as $item) {
            $totalDays += (int)($item['reward_days'] > 0 ? $item['reward_days'] : 1);
            $refIdsToUpdate[] = (int)$item['id'];
        }

        if ($totalDays <= 0) {
            $pdo->rollBack();
            set_flash('warning', 'Số ngày quy đổi không hợp lệ.', 'Lỗi dữ liệu');
            header("Location: referral.php");
            exit;
        }

        // 3. Sinh mã Key VIP ngẫu nhiên và mã đơn quy đổi
        $licenseKey = 'TQ-REF-' . strtoupper(bin2hex(random_bytes(3))) . '-' . strtoupper(bin2hex(random_bytes(3)));
        $claimCode = 'REF-KEY-' . strtoupper(substr(uniqid(), -8));
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$totalDays} days"));

        // 4. Lưu vào bảng key_orders (để đồng bộ sang danh sách Key của user và nhập vào Tool Golike)
        $stmtKeyOrder = $pdo->prepare("
            INSERT INTO key_orders (
                user_uuid, order_code, package_type, package_name, duration_days, license_key, cloud_server, amount, status, expires_at
            ) VALUES (
                ?, ?, 'key_only', ?, ?, ?, NULL, 0.00, 'Active', ?
            )
        ");
        $stmtKeyOrder->execute([
            $currentUser['uuid'],
            $claimCode,
            "Key VIP Thưởng Giới Thiệu ({$totalDays} Ngày)",
            $totalDays,
            $licenseKey,
            $expiresAt
        ]);

        // 5. Lưu vào lịch sử referral_claims
        $stmtClaimLog = $pdo->prepare("
            INSERT INTO referral_claims (
                user_uuid, claim_code, referred_count, reward_days, license_key, expires_at, status
            ) VALUES (
                ?, ?, ?, ?, ?, ?, 'Active'
            )
        ");
        $stmtClaimLog->execute([
            $currentUser['uuid'],
            $claimCode,
            $unclaimedCount,
            $totalDays,
            $licenseKey,
            $expiresAt
        ]);

        // 6. ĐÁNH DẤU CHỐNG LẤY LẠI TỪ ĐẦU (Đánh dấu is_claimed = 1 cho các ID này)
        $inPlaceholders = implode(',', array_fill(0, count($refIdsToUpdate), '?'));
        $updateParams = array_merge([$claimCode], $refIdsToUpdate);
        $stmtMarkClaimed = $pdo->prepare("
            UPDATE referrals 
            SET is_claimed = 1, claimed_at = NOW(), claim_order_code = ? 
            WHERE id IN ($inPlaceholders)
        ");
        $stmtMarkClaimed->execute($updateParams);

        $pdo->commit();

        set_flash(
            'success',
            'Chúc mừng bạn đã quy đổi thành công <strong>' . $unclaimedCount . ' bạn bè</strong> thành <strong>Key VIP ' . $totalDays . ' Ngày</strong>!<br>' .
            '<div class="p-3 my-2 bg-light border rounded-3 text-start font-monospace small">' .
            '<strong>Mã Key VIP:</strong> <span class="text-success fw-bold fs-6">' . $licenseKey . '</span><br>' .
            '<strong>Thời hạn:</strong> ' . $totalDays . ' ngày (Đến ' . date('d/m/Y H:i:s', strtotime($expiresAt)) . ')<br>' .
            '<strong>Mã đơn:</strong> #' . $claimCode .
            '</div>' .
            'Mã Key đã được kích hoạt và lưu vào bảng lịch sử bên dưới. Bạn có thể sao chép để dán vào Tool Golike ngay bây giờ!',
            'Quy đổi Key VIP thành công'
        );
        header("Location: referral.php");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Có lỗi xảy ra khi quy đổi Key VIP: ' . $e->getMessage(), 'Lỗi quy đổi');
        header("Location: referral.php");
        exit;
    }
}

// 1. Thống kê số lượng bạn bè và số ngày Key VIP
// - Số bạn bè CHƯA QUY ĐỔI (Sẵn sàng nhận thưởng)
$stmtUnclaimedCount = $pdo->prepare("
    SELECT COUNT(id) as unclaimed_count, COALESCE(SUM(reward_days), 0) as unclaimed_days 
    FROM referrals 
    WHERE referrer_uuid = ? AND is_claimed = 0 AND status = 'Active'
");
$stmtUnclaimedCount->execute([$currentUser['uuid']]);
$unclaimedData = $stmtUnclaimedCount->fetch();
$availableRewardDays = (int)($unclaimedData['unclaimed_days'] ?? 0);
$availableF1Count = (int)($unclaimedData['unclaimed_count'] ?? 0);

// - Tổng số bạn bè đã giới thiệu (Tất cả từ trước đến nay)
$stmtTotalRef = $pdo->prepare("
    SELECT COUNT(id) as total_referrals 
    FROM referrals 
    WHERE referrer_uuid = ?
");
$stmtTotalRef->execute([$currentUser['uuid']]);
$totalReferralsCount = (int)($stmtTotalRef->fetchColumn() ?: 0);

// - Tổng số ngày Key VIP đã từng quy đổi thành công
$stmtTotalClaimedDays = $pdo->prepare("
    SELECT COALESCE(SUM(reward_days), 0) as total_days 
    FROM referral_claims 
    WHERE user_uuid = ?
");
$stmtTotalClaimedDays->execute([$currentUser['uuid']]);
$totalClaimedDays = (int)($stmtTotalClaimedDays->fetchColumn() ?: 0);

// 2. Danh sách tất cả bạn bè F1 đã giới thiệu
$stmtF1List = $pdo->prepare("
    SELECT 
        r.id as ref_id,
        r.reward_days,
        r.is_claimed,
        r.claimed_at,
        r.claim_order_code,
        r.created_at as joined_at,
        u.uid,
        u.name,
        u.username,
        u.avatar,
        u.status as user_status
    FROM referrals r
    JOIN users u ON r.referee_uuid = u.uuid
    WHERE r.referrer_uuid = ?
    ORDER BY r.is_claimed ASC, r.id DESC
");
$stmtF1List->execute([$currentUser['uuid']]);
$referredUsers = $stmtF1List->fetchAll();

// 3. Lịch sử các đợt quy đổi Key VIP
$stmtClaimsHistory = $pdo->prepare("
    SELECT * 
    FROM referral_claims 
    WHERE user_uuid = ? 
    ORDER BY id DESC
");
$stmtClaimsHistory->execute([$currentUser['uuid']]);
$claimsHistory = $stmtClaimsHistory->fetchAll();

// Tạo URL giới thiệu
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$referralUrl = $protocol . $host . $basePath . '/register.php?ref=' . $currentUser['uid'];

$flash = get_flash();
$csrfToken = get_csrf_token();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Giới Thiệu Bạn Bè - Tích Lũy Key VIP - <?= htmlspecialchars(APP_NAME) ?></title>

    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

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
            --gradient-vip: linear-gradient(135deg, #f59e0b 0%, #ea580c 50%, #e11d48 100%);
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

        /* ==========================================================
         * 1. HEADER CỐ ĐỊNH (FIXED TOPBAR)
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

        .brand-name span { color: var(--primary); }

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
            background: #ffffff;
            color: #16a34a;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
            flex-shrink: 0;
        }

        .balance-text-group {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
            white-space: nowrap;
        }

        .balance-title {
            font-size: 0.72rem;
            color: #15803d;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .balance-val {
            font-size: 0.96rem;
            font-weight: 800;
            color: #166534;
            font-variant-numeric: tabular-nums;
        }

        .user-profile-container { position: relative; }

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
        }

        .user-profile-toggle:hover {
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
            background: #eef2ff;
        }

        /* Popup Hồ Sơ */
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

        .user-profile-popup.active { display: block !important; }

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
         * 2. SIDEBAR MENU CỐ ĐỊNH TRÁI (CHUẨN 1:1 THEO INDEX)
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
         * 3. KHU VỰC NỘI DUNG CHÍNH (APP MAIN)
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
        }

        /* Hero Banner Key VIP */
        .referral-hero-banner {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 45%, #4f46e5 100%);
            border-radius: var(--radius-lg);
            padding: 32px 36px;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 16px 35px -10px rgba(79, 70, 229, 0.35);
            margin-bottom: 24px;
        }

        .referral-hero-banner::before {
            content: '';
            position: absolute;
            top: -60px;
            right: -60px;
            width: 240px;
            height: 240px;
            background: radial-gradient(circle, rgba(245, 158, 11, 0.35) 0%, rgba(255, 255, 255, 0) 70%);
            border-radius: 50%;
        }

        .referral-hero-banner::after {
            content: '';
            position: absolute;
            bottom: -40px;
            left: 25%;
            width: 180px;
            height: 180px;
            background: radial-gradient(circle, rgba(6, 182, 212, 0.25) 0%, rgba(255, 255, 255, 0) 70%);
            border-radius: 50%;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(245, 158, 11, 0.2);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(245, 158, 11, 0.4);
            padding: 5px 14px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 800;
            color: #fef08a;
            margin-bottom: 12px;
        }

        .hero-title {
            font-size: 1.85rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            line-height: 1.25;
            margin-bottom: 10px;
        }

        .hero-subtitle {
            font-size: 0.95rem;
            color: #cbd5e1;
            max-width: 680px;
            line-height: 1.6;
            margin-bottom: 0;
        }

        /* Thẻ liên kết giới thiệu */
        .referral-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-card);
            margin-bottom: 24px;
        }

        .copy-box {
            background: #f8fafc;
            border: 1.5px dashed #cbd5e1;
            border-radius: 12px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            transition: var(--transition);
        }

        .copy-box:hover {
            border-color: var(--primary);
            background: #f1f5f9;
        }

        .copy-text {
            font-family: monospace;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--primary);
            word-break: break-all;
        }

        .btn-copy-action {
            background: var(--primary);
            color: #ffffff;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: var(--transition);
            flex-shrink: 0;
            white-space: nowrap;
        }

        .btn-copy-action:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-qr-action {
            background: #ffffff;
            color: var(--text-heading);
            border: 1.5px solid #cbd5e1;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: var(--transition);
            flex-shrink: 0;
        }

        .btn-qr-action:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: #eef2ff;
        }

        /* 4 Thẻ thống kê KPI */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: #ffffff;
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
            box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.08);
        }

        .stat-icon-wrapper {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
        }

        .icon-gold { background: #fef3c7; color: #d97706; }
        .icon-blue { background: #dbeafe; color: #2563eb; }
        .icon-purple { background: #ede9fe; color: #7c3aed; }
        .icon-green { background: #dcfce7; color: #16a34a; }

        .stat-info { min-width: 0; flex-grow: 1; }
        .stat-label {
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
        .stat-number {
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--text-heading);
            letter-spacing: -0.5px;
            line-height: 1.2;
        }

        /* Khối Quy Đổi Key VIP */
        .claim-card-box {
            background: linear-gradient(135deg, #fdf4ff 0%, #fae8ff 50%, #f3e8ff 100%);
            border: 1.5px solid #e9d5ff;
            border-radius: 16px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
        }

        .btn-claim-key {
            background: linear-gradient(135deg, #a855f7 0%, #7e22ce 100%);
            color: #ffffff;
            border: none;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(168, 85, 247, 0.35);
            transition: var(--transition);
        }

        .btn-claim-key:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(168, 85, 247, 0.45);
            filter: brightness(1.05);
        }

        .btn-claim-key:disabled {
            background: #cbd5e1;
            box-shadow: none;
            cursor: not-allowed;
            color: #64748b;
        }

        /* Quy trình 3 bước */
        .steps-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-card);
            margin-bottom: 24px;
        }

        .step-item {
            display: flex;
            gap: 16px;
            align-items: flex-start;
        }

        .step-circle {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #eef2ff;
            color: var(--primary);
            border: 2px solid #c7d2fe;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        /* Bảng & Tabs */
        .history-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-card);
        }

        .referral-tabs {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid #f1f5f9;
            margin-bottom: 20px;
        }

        .tab-btn {
            background: transparent;
            border: none;
            padding: 10px 18px;
            font-weight: 700;
            font-size: 0.92rem;
            color: var(--text-muted);
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .tab-btn:hover { color: var(--primary); }
        .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }

        .tab-badge {
            font-size: 0.72rem;
            padding: 2px 8px;
            border-radius: 20px;
            background: #f1f5f9;
            color: #64748b;
        }

        .tab-btn.active .tab-badge {
            background: #eef2ff;
            color: var(--primary);
            font-weight: 800;
        }

        .tab-content-panel { display: none; }
        .tab-content-panel.active { display: block; }

        /* Bảng dữ liệu */
        .table-custom {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .table-custom th {
            background: #f8fafc;
            color: var(--text-muted);
            font-size: 0.76rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 16px;
            border-top: 1px solid #f1f5f9;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        .table-custom td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.88rem;
            vertical-align: middle;
        }

        .table-custom tr:hover td { background: #fcfcfd; }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .status-completed { background: #dcfce7; color: #15803d; }
        .status-pending { background: #fef3c7; color: #b45309; }

        .key-code-box {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 4px 10px;
            border-radius: 8px;
            font-family: monospace;
            font-weight: 700;
            color: var(--primary);
            font-size: 0.88rem;
        }

        .btn-copy-mini {
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 2px;
            transition: var(--transition);
        }

        .btn-copy-mini:hover {
            color: var(--primary);
            transform: scale(1.15);
        }

        /* Modal QR Code */
        .modal-qr-body {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 24px;
        }

        .qr-image-wrapper {
            background: #ffffff;
            padding: 16px;
            border-radius: 16px;
            border: 1px solid var(--card-border);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05);
            margin-bottom: 16px;
        }

        /* SVG Dialog Styles */
        .svg-dialog-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            padding: 20px;
        }

        .svg-dialog-overlay.active { opacity: 1; visibility: visible; }

        .svg-dialog-card {
            width: 100%;
            max-width: 460px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius-lg);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            padding: 32px 28px;
            text-align: center;
            transform: scale(0.92) translateY(12px);
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .svg-dialog-overlay.active .svg-dialog-card {
            transform: scale(1) translateY(0);
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
                padding: 16px 12px 50px !important;
                overflow-x: hidden !important;
                box-sizing: border-box !important;
            }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 767.98px) {
            .app-sidebar { top: 56px !important; }
            .app-header { height: 56px !important; padding: 0 8px !important; }
            .brand-name { font-size: 0.86rem !important; }
            .user-avatar-small { width: 28px !important; height: 28px !important; }
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
            .referral-hero-banner { padding: 24px 18px; }
            .hero-title { font-size: 1.35rem; }
            .hero-subtitle { font-size: 0.85rem; }
            .stats-grid { grid-template-columns: 1fr; }
            .copy-box { flex-direction: column; align-items: stretch; }
            .btn-copy-action { width: 100%; justify-content: center; }
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

    <!-- ==========================================================
     * 1. HEADER CỐ ĐỊNH (FIXED TOPBAR)
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
                    <span>ThanhQuy</span><span class="brand-tech-suffix">Tech</span>
                </div>
            </a>
        </div>

        <div class="header-right">
            <!-- Khối Số dư -->
            <a href="/payments/deposit" class="header-balance-card" title="Nạp tiền vào tài khoản">
                <div class="balance-wallet-icon">
                    <i class="fa-solid fa-wallet"></i>
                </div>
                <div class="balance-text-group">
                    <span class="balance-title">Số dư</span>
                    <span class="balance-val"><?= format_currency($currentUser['balance']) ?></span>
                </div>
            </a>

            <!-- Avatar & Popup Menu -->
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
     * 2. SIDEBAR MENU CỐ ĐỊNH TRÁI (CHUẨN 1:1 THEO INDEX)
     * ========================================================== -->
    <aside class="app-sidebar" id="appSidebar">
        <div class="sidebar-category">BẢNG ĐIỀU KHIỂN</div>
        <ul class="sidebar-nav-list">
            <!-- Trang chủ -->
            <li>
                <a href="index.php" class="sidebar-link">
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
            <!-- Giới thiệu (Active) -->
            <li>
                <a href="referral.php" class="sidebar-link active">
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
            <!-- Admin Panel -->
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

    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeAppSidebar()"></div>

    <!-- ==========================================================
     * 3. KHU VỰC NỘI DUNG CHÍNH (APP MAIN)
     * ========================================================== -->
    <main class="app-main">
        <div class="dashboard-container-inner">

            <!-- Hero Banner -->
            <div class="referral-hero-banner">
                <div class="hero-badge">
                    <i class="fa-solid fa-gift"></i> Tặng Key VIP Miễn Phí
                </div>
                <h1 class="hero-title">Mời 1 Bạn Bè = Nhận Ngay 1 Ngày Key VIP</h1>
                <p class="hero-subtitle">
                    Không giới hạn số lượt! Cứ mỗi người bạn tham gia đăng ký qua liên kết của bạn, bạn sẽ được tích lũy ngay <strong>1 Ngày Bản Quyền Key VIP Tool Golike</strong>. Quy đổi bất cứ lúc nào bạn muốn.
                </p>
            </div>

            <!-- Thẻ liên kết giới thiệu & Khối quy đổi Key VIP -->
            <div class="referral-card">
                <div class="row g-4 align-items-stretch">
                    <div class="col-lg-7 d-flex flex-column justify-content-between">
                        <div>
                            <h4 class="fw-bold mb-2 text-dark">
                                <i class="fa-solid fa-link text-primary me-2"></i>Đường dẫn giới thiệu của bạn
                            </h4>
                            <p class="text-muted small mb-3">
                                Gửi link này cho bạn bè. Khi bạn bè hoàn tất tạo tài khoản, hệ thống sẽ tự động cộng <strong>+1 Ngày Key VIP</strong> vào quỹ thưởng chờ quy đổi của bạn.
                            </p>
                            
                            <div class="copy-box mb-3">
                                <span class="copy-text" id="refUrlText"><?= htmlspecialchars($referralUrl) ?></span>
                                <button type="button" class="btn-copy-action" onclick="copyReferralLink()">
                                    <i class="fa-regular fa-copy" id="copyIcon"></i>
                                    <span id="copyBtnLabel">Sao chép liên kết</span>
                                </button>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-3 flex-wrap pt-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-muted small fw-semibold">Mã giới thiệu UID:</span>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1 font-monospace fw-bold" style="font-size: 0.9rem;">
                                    #<?= htmlspecialchars($currentUser['uid']) ?>
                                </span>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick="copyReferralCode('<?= htmlspecialchars($currentUser['uid']) ?>')" title="Sao chép UID">
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                            </div>

                            <button type="button" class="btn-qr-action ms-auto" data-bs-toggle="modal" data-bs-target="#qrCodeModal">
                                <i class="fa-solid fa-qrcode text-primary"></i> Mã QR Quét Nhanh
                            </button>
                        </div>
                    </div>

                    <!-- Khối quy đổi Key VIP -->
                    <div class="col-lg-5">
                        <div class="claim-card-box">
                            <div>
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="fw-bold text-dark small"><i class="fa-solid fa-award text-warning me-1"></i> Quỹ Key VIP chờ nhận</span>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1 fw-bold">
                                        Tự động chống trùng
                                    </span>
                                </div>
                                <div class="mb-3">
                                    <div class="d-flex align-items-baseline gap-2">
                                        <span class="fs-1 fw-extrabold text-purple-700" style="color: #7e22ce; font-weight: 800;">
                                            <?= $availableRewardDays ?>
                                        </span>
                                        <span class="fw-bold text-muted">Ngày Key VIP</span>
                                        <span class="badge bg-purple-100 text-purple-700 px-2 py-1 ms-auto small" style="background: #ede9fe; color: #6b21a8;">
                                            <?= $availableF1Count ?> bạn mới
                                        </span>
                                    </div>
                                    <p class="text-muted small mb-0 mt-1">
                                        <?php if ($availableRewardDays > 0): ?>
                                            Bạn đang có <strong><?= $availableF1Count ?> bạn bè mới</strong> chưa nhận thưởng. Bấm nút bên dưới để tạo ngay mã Key VIP <strong><?= $availableRewardDays ?> ngày</strong>!
                                        <?php else: ?>
                                            Bạn đã quy đổi hết tất cả bạn bè hiện tại. Mời thêm bạn bè mới để tích lũy thêm ngày Key VIP.
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>

                            <form method="POST" action="referral.php" onsubmit="return confirmRedeemKey(<?= $availableRewardDays ?>)">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="claim_key_reward">
                                <button type="submit" class="btn-claim-key" <?= ($availableRewardDays <= 0) ? 'disabled' : '' ?>>
                                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                                    <?php if ($availableRewardDays > 0): ?>
                                        Quy đổi ngay <?= $availableRewardDays ?> Ngày Key VIP
                                    <?php else: ?>
                                        Chưa có lượt mới để đổi
                                    <?php endif; ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4 Thẻ thống kê KPI -->
            <div class="stats-grid">
                <!-- Key VIP khả dụng -->
                <div class="stat-card">
                    <div class="stat-icon-wrapper icon-gold">
                        <i class="fa-solid fa-key"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Key VIP chờ quy đổi</div>
                        <div class="stat-number text-warning" style="color: #d97706 !important;">
                            +<?= $availableRewardDays ?> <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted);">Ngày</span>
                        </div>
                    </div>
                </div>

                <!-- Bạn bè chưa quy đổi -->
                <div class="stat-card">
                    <div class="stat-icon-wrapper icon-purple">
                        <i class="fa-solid fa-user-clock"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Bạn bè chờ nhận thưởng</div>
                        <div class="stat-number text-dark">
                            <?= $availableF1Count ?> <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted);">Người</span>
                        </div>
                    </div>
                </div>

                <!-- Tổng ngày Key đã từng nhận -->
                <div class="stat-card">
                    <div class="stat-icon-wrapper icon-green">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Tổng Key VIP đã nhận</div>
                        <div class="stat-number text-success">
                            <?= $totalClaimedDays ?> <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted);">Ngày</span>
                        </div>
                    </div>
                </div>

                <!-- Tổng bạn bè từ trước đến nay -->
                <div class="stat-card">
                    <div class="stat-icon-wrapper icon-blue">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Tổng bạn bè đã mời</div>
                        <div class="stat-number text-primary">
                            <?= $totalReferralsCount ?> <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted);">Người</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quy trình 3 bước nhận Key VIP -->
            <div class="steps-card">
                <h5 class="fw-bold mb-4 text-dark"><i class="fa-solid fa-circle-nodes text-primary me-2"></i>Quy tắc nhận Key VIP đơn giản & minh bạch</h5>
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="step-item">
                            <div class="step-circle">1</div>
                            <div>
                                <h6 class="fw-bold mb-1">Mời bạn bè tham gia</h6>
                                <p class="text-muted small mb-0">Chia sẻ đường link hoặc mã giới thiệu UID cho bạn bè đăng ký tài khoản mới.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="step-item">
                            <div class="step-circle">2</div>
                            <div>
                                <h6 class="fw-bold mb-1">Tích lũy 1 Ngày / Người</h6>
                                <p class="text-muted small mb-0">Cứ mỗi bạn bè tham gia thành công, hệ thống tự động cộng dồn <strong>+1 Ngày Key VIP</strong>.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="step-item">
                            <div class="step-circle">3</div>
                            <div>
                                <h6 class="fw-bold mb-1">Quy đổi Key VIP Bản Quyền</h6>
                                <p class="text-muted small mb-0">Bấm nút quy đổi để tạo ngay mã Key VIP với tổng số ngày tương ứng để sử dụng cày xu.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bảng Dữ Liệu: Tabs Chuyển Đổi (Lịch Sử Nhận Key & Danh Sách Bạn Bè) -->
            <div class="history-card">
                <div class="referral-tabs">
                    <button type="button" class="tab-btn active" onclick="switchReferralTab('claims', this)">
                        <i class="fa-solid fa-key"></i> Lịch sử quy đổi Key VIP
                        <span class="tab-badge"><?= count($claimsHistory) ?></span>
                    </button>
                    <button type="button" class="tab-btn" onclick="switchReferralTab('members', this)">
                        <i class="fa-solid fa-user-group"></i> Danh sách bạn bè đã giới thiệu (F1)
                        <span class="tab-badge"><?= count($referredUsers) ?></span>
                    </button>
                </div>

                <!-- Tab 1: Lịch sử nhận Key VIP -->
                <div id="tabClaims" class="tab-content-panel active">
                    <?php if (empty($claimsHistory)): ?>
                        <div class="text-center py-5">
                            <div class="mb-3 text-muted" style="font-size: 3rem;">
                                <i class="fa-solid fa-ticket"></i>
                            </div>
                            <h6 class="fw-bold text-dark">Chưa có lần quy đổi Key VIP nào</h6>
                            <p class="text-muted small">Khi bạn có bạn bè mới tham gia và bấm nút quy đổi, mã Key VIP sẽ xuất hiện chi tiết tại đây.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table-custom">
                                <thead>
                                    <tr>
                                        <th>Mã Đơn Quy Đổi</th>
                                        <th>Mã Key VIP Bản Quyền</th>
                                        <th>Số Bạn Bè Đổi</th>
                                        <th>Thời Hạn Key</th>
                                        <th>Hạn Dùng Đến</th>
                                        <th>Thời Gian Đổi</th>
                                        <th>Trạng Thái</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($claimsHistory as $claim): ?>
                                    <?php 
                                        $isExpired = (strtotime($claim['expires_at']) < time());
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="fw-bold font-monospace text-primary">
                                                #<?= htmlspecialchars($claim['claim_code']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="key-code-box">
                                                <span><?= htmlspecialchars($claim['license_key']) ?></span>
                                                <button type="button" class="btn-copy-mini" onclick="copyKey('<?= htmlspecialchars($claim['license_key']) ?>')" title="Sao chép Key">
                                                    <i class="fa-regular fa-copy"></i>
                                                </button>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border fw-bold px-2 py-1">
                                                <?= (int)$claim['referred_count'] ?> người
                                            </span>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-purple font-monospace" style="color: #7e22ce;">
                                                +<?= (int)$claim['reward_days'] ?> Ngày VIP
                                            </span>
                                        </td>
                                        <td class="text-muted small font-monospace">
                                            <?= date('d/m/Y H:i', strtotime($claim['expires_at'])) ?>
                                        </td>
                                        <td class="text-muted small">
                                            <?= date('d/m/Y H:i', strtotime($claim['created_at'])) ?>
                                        </td>
                                        <td>
                                            <?php if ($isExpired): ?>
                                                <span class="status-pill" style="background: #f1f5f9; color: #64748b;">
                                                    <i class="fa-solid fa-clock-rotate-left"></i> Đã hết hạn
                                                </span>
                                            <?php else: ?>
                                                <span class="status-pill status-completed">
                                                    <i class="fa-solid fa-circle-check"></i> Đang hoạt động
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tab 2: Danh sách bạn bè đã giới thiệu (F1) -->
                <div id="tabMembers" class="tab-content-panel">
                    <?php if (empty($referredUsers)): ?>
                        <div class="text-center py-5">
                            <div class="mb-3 text-muted" style="font-size: 3rem;">
                                <i class="fa-solid fa-user-plus"></i>
                            </div>
                            <h6 class="fw-bold text-dark">Bạn chưa có thành viên F1 nào</h6>
                            <p class="text-muted small">Hãy sao chép link giới thiệu bên trên và gửi cho bạn bè để bắt đầu tích lũy ngày Key VIP.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table-custom">
                                <thead>
                                    <tr>
                                        <th>Thành Viên (F1)</th>
                                        <th>Tên Người Dùng</th>
                                        <th>Mã UID</th>
                                        <th>Ngày Tham Gia</th>
                                        <th>Phần Thưởng</th>
                                        <th>Trạng Thái Quy Đổi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($referredUsers as $f1): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <img src="<?= htmlspecialchars($f1['avatar']) ?>" 
                                                     alt="Avatar" 
                                                     style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 1px solid #cbd5e1;"
                                                     onerror="this.onerror=null; this.src='assets/images/default-avatar.svg';">
                                                <span class="fw-bold text-dark"><?= htmlspecialchars($f1['name']) ?></span>
                                            </div>
                                        </td>
                                        <td class="text-muted small font-monospace"><?= htmlspecialchars($f1['username']) ?></td>
                                        <td><span class="badge bg-light text-primary border font-monospace">#<?= htmlspecialchars($f1['uid']) ?></span></td>
                                        <td class="text-muted small"><?= date('d/m/Y H:i', strtotime($f1['joined_at'])) ?></td>
                                        <td>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle fw-bold">
                                                +1 Ngày Key VIP
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ((int)$f1['is_claimed'] === 1): ?>
                                                <span class="status-pill status-completed" title="Đã nhận thưởng vào đơn #<?= htmlspecialchars($f1['claim_order_code'] ?? '') ?>">
                                                    <i class="fa-solid fa-circle-check"></i> Đã nhận thưởng Key
                                                </span>
                                            <?php else: ?>
                                                <span class="status-pill status-pending" title="Chưa quy đổi, sẵn sàng nhận">
                                                    <i class="fa-solid fa-clock"></i> Chưa quy đổi (Sẵn sàng)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

    <!-- Modal QR Code Giới Thiệu -->
    <div class="modal fade" id="qrCodeModal" tabindex="-1" aria-labelledby="qrCodeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: var(--radius-lg);">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark" id="qrCodeModalLabel">
                        <i class="fa-solid fa-qrcode text-primary me-2"></i>Mã QR Giới Thiệu
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-qr-body">
                    <p class="text-muted small mb-3">Mở camera trên điện thoại và quét mã bên dưới để mở ngay liên kết đăng ký thành viên:</p>
                    
                    <div class="qr-image-wrapper">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=<?= urlencode($referralUrl) ?>&margin=1" 
                             alt="Referral QR Code" 
                             class="img-fluid"
                             style="width: 200px; height: 200px; border-radius: 8px;">
                    </div>

                    <div class="fw-bold text-dark mb-1"><?= htmlspecialchars($currentUser['name']) ?></div>
                    <div class="text-muted small mb-3 font-monospace">Mã giới thiệu: #<?= htmlspecialchars($currentUser['uid']) ?></div>

                    <button type="button" class="btn btn-primary w-100 fw-bold py-2 rounded-3" onclick="copyReferralLink()">
                        <i class="fa-regular fa-copy me-1"></i> Sao chép đường dẫn
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Hộp thoại SVG Alert -->
    <div id="svgDialogOverlay" class="svg-dialog-overlay">
        <div class="svg-dialog-card">
            <div class="mb-3" id="svgDialogIcon" style="display: flex; justify-content: center;"></div>
            <h3 class="fw-bold mb-2 text-dark" id="svgDialogTitle">Thông báo</h3>
            <div class="text-muted mb-4 small" id="svgDialogMessage"></div>
            <div id="svgDialogActions" class="d-flex gap-2 justify-content-center"></div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // ==========================================================
        // SAO CHÉP LIÊN KẾT & MÃ KEY
        // ==========================================================
        function copyReferralLink() {
            const urlText = document.getElementById('refUrlText').innerText;
            navigator.clipboard.writeText(urlText).then(() => {
                const btnLabel = document.getElementById('copyBtnLabel');
                const copyIcon = document.getElementById('copyIcon');
                if (btnLabel && copyIcon) {
                    btnLabel.innerText = 'Đã sao chép!';
                    copyIcon.className = 'fa-solid fa-check';
                    setTimeout(() => {
                        btnLabel.innerText = 'Sao chép liên kết';
                        copyIcon.className = 'fa-regular fa-copy';
                    }, 2500);
                }
                showSvgAlert('Đã sao chép liên kết giới thiệu vào bộ nhớ tạm. Hãy gửi ngay cho bạn bè!', 'Thành công', 'success');
            }).catch(err => {
                showSvgAlert('Không thể sao chép, vui lòng sao chép thủ công.', 'Lỗi', 'error');
            });
        }

        function copyReferralCode(code) {
            navigator.clipboard.writeText(code).then(() => {
                showSvgAlert('Đã sao chép mã giới thiệu <strong>#' + code + '</strong>!', 'Thành công', 'success');
            });
        }

        function copyKey(keyText) {
            navigator.clipboard.writeText(keyText).then(() => {
                showSvgAlert('Đã sao chép mã Key VIP: <strong class="text-primary font-monospace">' + keyText + '</strong><br>Bạn có thể dán vào Tool Golike để sử dụng ngay.', 'Sao chép thành công', 'success');
            });
        }

        function confirmRedeemKey(days) {
            if (days <= 0) {
                showSvgAlert('Bạn chưa có bạn bè mới nào chưa quy đổi để nhận thưởng.', 'Chưa đủ điều kiện', 'info');
                return false;
            }
            return confirm('Bạn có chắc chắn muốn quy đổi ' + days + ' bạn bè thành ' + days + ' ngày Key VIP bản quyền không?');
        }

        // ==========================================================
        // CHUYỂN ĐỔI TAB BẢNG DỮ LIỆU
        // ==========================================================
        function switchReferralTab(tabKey, btnElement) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content-panel').forEach(p => p.classList.remove('active'));

            if (btnElement) btnElement.classList.add('active');

            if (tabKey === 'claims') {
                document.getElementById('tabClaims').classList.add('active');
            } else if (tabKey === 'members') {
                document.getElementById('tabMembers').classList.add('active');
            }
        }

        // ==========================================================
        // ĐIỀU KHIỂN SIDEBAR
        // ==========================================================
        const sidebarToggle = document.getElementById('sidebarToggle');
        const appSidebar = document.getElementById('appSidebar');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');

        if (sidebarToggle) {
            sidebarToggle.onclick = toggleAppSidebar;
        }
        if (sidebarBackdrop) {
            sidebarBackdrop.onclick = closeAppSidebar;
        }

        // ==========================================================
        // BẬT / TẮT POPUP HỒ SƠ
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

        document.addEventListener('click', function(e) {
            const container = document.getElementById('userDropdownContainer');
            if (container && !container.contains(e.target)) {
                closeUserPopup();
            }
        });

        // ==========================================================
        // SVG DIALOG NOTIFICATION
        // ==========================================================
        function showSvgAlert(msg, title = 'Thông báo', type = 'info') {
            const overlay = document.getElementById('svgDialogOverlay');
            const titleEl = document.getElementById('svgDialogTitle');
            const msgEl = document.getElementById('svgDialogMessage');
            const actionsEl = document.getElementById('svgDialogActions');
            const iconEl = document.getElementById('svgDialogIcon');

            if (!overlay || !titleEl || !msgEl || !actionsEl || !iconEl) return;

            titleEl.innerHTML = title;
            msgEl.innerHTML = msg;

            if (type === 'success') {
                iconEl.innerHTML = `
                    <svg style="width: 56px; height: 56px;" viewBox="0 0 52 52">
                        <circle cx="26" cy="26" r="25" fill="none" stroke="#10b981" stroke-width="3"/>
                        <path fill="none" stroke="#10b981" stroke-width="3" stroke-linecap="round" d="M14 27l7 7 16-16"/>
                    </svg>
                `;
            } else if (type === 'error') {
                iconEl.innerHTML = `
                    <svg style="width: 56px; height: 56px;" viewBox="0 0 52 52">
                        <circle cx="26" cy="26" r="25" fill="none" stroke="#ef4444" stroke-width="3"/>
                        <path fill="none" stroke="#ef4444" stroke-width="3" stroke-linecap="round" d="M16 16l20 20M36 16L16 36"/>
                    </svg>
                `;
            } else {
                iconEl.innerHTML = `
                    <svg style="width: 56px; height: 56px;" viewBox="0 0 52 52">
                        <circle cx="26" cy="26" r="25" fill="none" stroke="#4f46e5" stroke-width="3"/>
                        <path fill="none" stroke="#4f46e5" stroke-width="3" stroke-linecap="round" d="M26 16v12M26 34v2"/>
                    </svg>
                `;
            }

            actionsEl.innerHTML = `
                <button type="button" class="btn btn-primary px-4 fw-bold rounded-3" onclick="closeSvgAlert()">Đồng ý</button>
            `;

            overlay.classList.add('active');
        }

        function closeSvgAlert() {
            const overlay = document.getElementById('svgDialogOverlay');
            if (overlay) overlay.classList.remove('active');
        }

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
