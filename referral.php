<?php
/**
 * ==========================================================
 * TRANG GIỚI THIỆU & TIẾP THỊ LIÊN KẾT (REFERRAL AFFILIATE)
 * File: referral.php
 * Website: ThanhQuyTech
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

// Tự động kiểm tra và khởi tạo bảng nếu chưa có (Chống lỗi khi chưa import database.sql)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `referrals` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `referrer_uuid` CHAR(36) NOT NULL,
            `referee_uuid` CHAR(36) NOT NULL UNIQUE,
            `commission_rate` DECIMAL(5, 2) NOT NULL DEFAULT 10.00,
            `total_commission` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
            `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_ref_referrer` (`referrer_uuid`),
            INDEX `idx_ref_referee` (`referee_uuid`),
            INDEX `idx_ref_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `referral_commissions` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `referrer_uuid` CHAR(36) NOT NULL,
            `referee_uuid` CHAR(36) NOT NULL,
            `order_code` VARCHAR(50) DEFAULT NULL,
            `service_type` ENUM('buy_key', 'cloud', 'deposit', 'other') NOT NULL DEFAULT 'buy_key',
            `order_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
            `commission_rate` DECIMAL(5, 2) NOT NULL DEFAULT 10.00,
            `commission_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
            `status` ENUM('Pending', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Completed',
            `note` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_rc_referrer` (`referrer_uuid`),
            INDEX `idx_rc_referee` (`referee_uuid`),
            INDEX `idx_rc_order_code` (`order_code`),
            INDEX `idx_rc_status` (`status`),
            INDEX `idx_rc_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {
    // Bỏ qua nếu bảng đã tồn tại
}

// Xử lý hành động rút hoa hồng về ví chính (nếu có yêu cầu)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'claim_commission') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Mã xác thực CSRF không hợp lệ hoặc đã hết hạn.', 'Lỗi xác thực');
        header("Location: referral.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Tính tổng hoa hồng chưa rút (các commission Completed chưa được rút)
        // Ở đây hệ thống hỗ trợ cộng dồn hoa hồng tích lũy
        $stmtSum = $pdo->prepare("
            SELECT COALESCE(SUM(commission_amount), 0) as total_comm 
            FROM referral_commissions 
            WHERE referrer_uuid = ? AND status = 'Completed'
        ");
        $stmtSum->execute([$currentUser['uuid']]);
        $totalComm = (float)($stmtSum->fetch()['total_comm'] ?? 0);

        // Kiểm tra số dư hoa hồng có thể rút
        if ($totalComm <= 0) {
            $pdo->rollBack();
            set_flash('warning', 'Hiện tại bạn chưa có khoản hoa hồng khả dụng để quy đổi.', 'Chưa đủ điều kiện');
            header("Location: referral.php");
            exit;
        }

        // Kiểm tra xem đã từng rút bao nhiêu
        $stmtClaimed = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as claimed_amount 
            FROM transactions 
            WHERE user_uuid = ? AND type = 'Deposit' AND note LIKE 'Quy đổi hoa hồng giới thiệu%'
        ");
        $stmtClaimed->execute([$currentUser['uuid']]);
        $claimedAmount = (float)($stmtClaimed->fetch()['claimed_amount'] ?? 0);

        $availableToClaim = $totalComm - $claimedAmount;

        if ($availableToClaim < 10000) {
            $pdo->rollBack();
            set_flash('warning', 'Số tiền hoa hồng tối thiểu để quy đổi vào ví là <strong>10.000đ</strong>. Số dư hiện tại của bạn: <strong>' . format_currency($availableToClaim) . '</strong>.', 'Chưa đạt tối thiểu');
            header("Location: referral.php");
            exit;
        }

        // Khóa dòng user để cập nhật số dư
        $stmtUser = $pdo->prepare("SELECT balance FROM users WHERE uuid = ? FOR UPDATE");
        $stmtUser->execute([$currentUser['uuid']]);
        $currBal = (float)$stmtUser->fetchColumn();
        $newBal = $currBal + $availableToClaim;

        $updateUser = $pdo->prepare("UPDATE users SET balance = ? WHERE uuid = ?");
        $updateUser->execute([$newBal, $currentUser['uuid']]);

        // Ghi nhận biến động số dư vào transactions
        $transCode = 'COMM-' . strtoupper(substr(uniqid(), -8));
        $stmtTrans = $pdo->prepare("
            INSERT INTO transactions (
                user_uuid, code, type, amount, balance_before, balance_after, status, note
            ) VALUES (
                ?, ?, 'Deposit', ?, ?, ?, 'Success', ?
            )
        ");
        $stmtTrans->execute([
            $currentUser['uuid'],
            $transCode,
            $availableToClaim,
            $currBal,
            $newBal,
            'Quy đổi hoa hồng giới thiệu (Mã: ' . $transCode . ')'
        ]);

        $pdo->commit();

        set_flash(
            'success',
            'Đã quy đổi thành công <strong>+' . format_currency($availableToClaim) . '</strong> vào số dư ví của bạn!<br>Số dư mới: <strong class="text-success">' . format_currency($newBal) . '</strong>.',
            'Quy đổi thành công'
        );
        header("Location: referral.php");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Có lỗi xảy ra: ' . $e->getMessage(), 'Lỗi quy đổi');
        header("Location: referral.php");
        exit;
    }
}

// 1. Thống kê tổng quan Tiếp thị liên kết
$stmtRefStats = $pdo->prepare("
    SELECT 
        COUNT(id) as total_referrals,
        COALESCE(SUM(total_commission), 0) as total_commission_earned
    FROM referrals 
    WHERE referrer_uuid = ?
");
$stmtRefStats->execute([$currentUser['uuid']]);
$refStats = $stmtRefStats->fetch() ?: ['total_referrals' => 0, 'total_commission_earned' => 0];

// Hoa hồng tháng này
$stmtMonthComm = $pdo->prepare("
    SELECT COALESCE(SUM(commission_amount), 0) as month_comm
    FROM referral_commissions
    WHERE referrer_uuid = ? 
      AND status = 'Completed' 
      AND MONTH(created_at) = MONTH(CURRENT_DATE()) 
      AND YEAR(created_at) = YEAR(CURRENT_DATE())
");
$stmtMonthComm->execute([$currentUser['uuid']]);
$monthCommission = (float)($stmtMonthComm->fetch()['month_comm'] ?? 0);

// Tính cấp bậc và tỉ lệ hoa hồng
$totalF1Count = (int)$refStats['total_referrals'];
$tierName = 'Thành viên Mới';
$commissionRate = 10.0;
$tierBadgeClass = 'badge-tier-bronze';
$tierIcon = 'fa-medal';

if ($totalF1Count >= 50) {
    $tierName = 'Đối tác Kim Cương';
    $commissionRate = 20.0;
    $tierBadgeClass = 'badge-tier-diamond';
    $tierIcon = 'fa-gem';
} elseif ($totalF1Count >= 20) {
    $tierName = 'Đại lý Vàng';
    $commissionRate = 15.0;
    $tierBadgeClass = 'badge-tier-gold';
    $tierIcon = 'fa-crown';
} elseif ($totalF1Count >= 5) {
    $tierName = 'Cộng tác viên Bạc';
    $commissionRate = 12.0;
    $tierBadgeClass = 'badge-tier-silver';
    $tierIcon = 'fa-shield-halved';
}

// 2. Danh sách F1 được giới thiệu
$stmtF1List = $pdo->prepare("
    SELECT 
        r.id as ref_id,
        r.commission_rate,
        r.total_commission as f1_commission,
        r.status as ref_status,
        r.created_at as joined_at,
        u.uid,
        u.name,
        u.username,
        u.avatar,
        u.status as user_status
    FROM referrals r
    JOIN users u ON r.referee_uuid = u.uuid
    WHERE r.referrer_uuid = ?
    ORDER BY r.id DESC
");
$stmtF1List->execute([$currentUser['uuid']]);
$referredUsers = $stmtF1List->fetchAll();

// 3. Lịch sử nhận hoa hồng gần nhất (50 giao dịch)
$stmtCommHistory = $pdo->prepare("
    SELECT 
        rc.*,
        u.uid as referee_uid,
        u.name as referee_name,
        u.username as referee_username
    FROM referral_commissions rc
    LEFT JOIN users u ON rc.referee_uuid = u.uuid
    WHERE rc.referrer_uuid = ?
    ORDER BY rc.id DESC
    LIMIT 50
");
$stmtCommHistory->execute([$currentUser['uuid']]);
$commissionHistory = $stmtCommHistory->fetchAll();

// Tạo URL giới thiệu tuyệt đối
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
    <title>Chương Trình Giới Thiệu & Hoa Hồng - <?= htmlspecialchars(APP_NAME) ?></title>

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
            --gradient-affiliate: linear-gradient(135deg, #0ea5e9 0%, #3b82f6 50%, #8b5cf6 100%);
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

        /* Hero Banner Tiếp Thị */
        .referral-hero-banner {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 40%, #4338ca 100%);
            border-radius: var(--radius-lg);
            padding: 32px 36px;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 16px 35px -10px rgba(49, 46, 129, 0.4);
            margin-bottom: 24px;
        }

        .referral-hero-banner::before {
            content: '';
            position: absolute;
            top: -60px;
            right: -60px;
            width: 240px;
            height: 240px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.35) 0%, rgba(255, 255, 255, 0) 70%);
            border-radius: 50%;
        }

        .referral-hero-banner::after {
            content: '';
            position: absolute;
            bottom: -40px;
            left: 20%;
            width: 180px;
            height: 180px;
            background: radial-gradient(circle, rgba(6, 182, 212, 0.25) 0%, rgba(255, 255, 255, 0) 70%);
            border-radius: 50%;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            padding: 5px 14px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 700;
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
            max-width: 650px;
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

        .icon-green { background: #dcfce7; color: #16a34a; }
        .icon-blue { background: #dbeafe; color: #2563eb; }
        .icon-purple { background: #ede9fe; color: #7c3aed; }
        .icon-amber { background: #fef3c7; color: #d97706; }

        .stat-info { min-width: 0; flex-grow: 1; }
        .stat-label {
            font-size: 0.78rem;
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

        /* Cấp bậc badge */
        .badge-tier-bronze { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-tier-silver { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .badge-tier-gold { background: #fef08a; color: #854d0e; border: 1px solid #facc15; }
        .badge-tier-diamond { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }

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

        .tab-btn:hover {
            color: var(--primary);
        }

        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

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

        /* Bảng dữ liệu đẹp */
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

        .table-custom tr:hover td {
            background: #fcfcfd;
        }

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
        .status-active { background: #e0f2fe; color: #0369a1; }

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
            max-width: 440px;
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

        /* Responsive */
        @media (max-width: 991.98px) {
            .app-sidebar {
                transform: translateX(-100%);
            }
            .app-sidebar.sidebar-open {
                transform: translateX(0);
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
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
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 767.98px) {
            .app-sidebar { top: 56px; }
            .app-header {
                height: 56px !important;
                padding: 0 8px !important;
            }
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
            }
            .referral-hero-banner {
                padding: 24px 18px;
            }
            .hero-title { font-size: 1.35rem; }
            .hero-subtitle { font-size: 0.85rem; }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .copy-box {
                flex-direction: column;
                align-items: stretch;
            }
            .btn-copy-action {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

    <!-- ==========================================================
     * 1. HEADER CỐ ĐỊNH (FIXED TOPBAR)
     * ========================================================== -->
    <header class="app-header">
        <div class="header-left">
            <button type="button" class="sidebar-toggle-btn" id="sidebarToggle" title="Đóng/Mở Menu">
                <i class="fa-solid fa-bars"></i>
            </button>
            <a href="index.php" class="brand-logo">
                <div class="brand-icon">
                    <i class="fa-solid fa-bolt"></i>
                </div>
                <div class="brand-name">
                    <span>ThanhQuy</span><span class="text-dark">Tech</span>
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

                    <div class="d-flex flex-column gap-1">
                        <a href="profile.php" class="popup-menu-item">
                            <i class="fa-solid fa-user-circle me-2 text-primary"></i> Xem hồ sơ cá nhân
                        </a>
                        <a href="buy-key.php" class="popup-menu-item">
                            <i class="fa-solid fa-key me-2 text-warning"></i> Quản lý Key bản quyền
                        </a>
                        <a href="cloud.php" class="popup-menu-item">
                            <i class="fa-solid fa-cloud me-2 text-info"></i> Thuê máy chủ Cloud
                        </a>
                        <a href="referral.php" class="popup-menu-item fw-bold text-primary">
                            <i class="fa-solid fa-share-nodes me-2 text-primary"></i> Tiếp thị & Giới thiệu
                        </a>
                        <div class="border-top my-2"></div>
                        <a href="logout.php" class="popup-menu-item text-danger">
                            <i class="fa-solid fa-right-from-bracket me-2"></i> Đăng xuất
                        </a>
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

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- ==========================================================
     * 3. KHU VỰC NỘI DUNG CHÍNH (APP MAIN)
     * ========================================================== -->
    <main class="app-main">
        <div class="dashboard-container-inner">

            <!-- Hero Banner -->
            <div class="referral-hero-banner">
                <div class="hero-badge">
                    <i class="fa-solid fa-hand-holding-dollar"></i> Tiếp Thị Liên Kết 4.0
                </div>
                <h1 class="hero-title">Kiếm Tiền Thụ Động Cùng ThanhQuyTech</h1>
                <p class="hero-subtitle">
                    Giới thiệu bạn bè tham gia sử dụng Key Tool Golike hoặc Thuê Cloud VPS để nhận ngay <strong>10% - 20% hoa hồng trọn đời</strong> cho mỗi giao dịch thành công.
                </p>
            </div>

            <!-- Thẻ liên kết giới thiệu & Mã QR -->
            <div class="referral-card">
                <div class="row g-4 align-items-center">
                    <div class="col-lg-7">
                        <h4 class="fw-bold mb-2 text-dark">
                            <i class="fa-solid fa-link text-primary me-2"></i>Đường dẫn giới thiệu của bạn
                        </h4>
                        <p class="text-muted small mb-3">
                            Chia sẻ đường dẫn này cho bạn bè. Khi họ đăng ký tài khoản, hệ thống sẽ tự động liên kết họ làm thành viên cấp dưới (F1) của bạn mãi mãi.
                        </p>
                        
                        <div class="copy-box mb-3">
                            <span class="copy-text" id="refUrlText"><?= htmlspecialchars($referralUrl) ?></span>
                            <button type="button" class="btn-copy-action" onclick="copyReferralLink()">
                                <i class="fa-regular fa-copy" id="copyIcon"></i>
                                <span id="copyBtnLabel">Sao chép liên kết</span>
                            </button>
                        </div>

                        <div class="d-flex align-items-center gap-3 flex-wrap">
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

                    <div class="col-lg-5">
                        <div class="p-3 rounded-4 bg-light border">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="fw-bold text-dark small"><i class="fa-solid fa-wallet text-success me-1"></i> Rút hoa hồng về ví</span>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">Tự động 24/7</span>
                            </div>
                            <p class="text-muted small mb-3">
                                Hoa hồng sẽ được cộng dồn. Bạn có thể quy đổi trực tiếp vào số dư ví tài khoản để mua Key hoặc rút về ngân hàng.
                            </p>
                            <form method="POST" action="referral.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="claim_commission">
                                <button type="submit" class="btn btn-success w-100 fw-bold py-2 shadow-sm d-flex align-items-center justify-content-center gap-2">
                                    <i class="fa-solid fa-arrow-down-to-bracket"></i> Quy đổi hoa hồng vào số dư ví
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4 Thẻ thống kê KPI -->
            <div class="stats-grid">
                <!-- Tổng hoa hồng -->
                <div class="stat-card">
                    <div class="stat-icon-wrapper icon-green">
                        <i class="fa-solid fa-sack-dollar"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Tổng hoa hồng đã nhận</div>
                        <div class="stat-number text-success"><?= format_currency($refStats['total_commission_earned']) ?></div>
                    </div>
                </div>

                <!-- Số lượng F1 -->
                <div class="stat-card">
                    <div class="stat-icon-wrapper icon-blue">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Thành viên đã giới thiệu</div>
                        <div class="stat-number text-primary"><?= number_format($totalF1Count) ?> <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted);">người</span></div>
                    </div>
                </div>

                <!-- Hoa hồng tháng này -->
                <div class="stat-card">
                    <div class="stat-icon-wrapper icon-purple">
                        <i class="fa-solid fa-chart-line"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Hoa hồng tháng này</div>
                        <div class="stat-number text-dark"><?= format_currency($monthCommission) ?></div>
                    </div>
                </div>

                <!-- Cấp bậc & Chiết khấu -->
                <div class="stat-card">
                    <div class="stat-icon-wrapper icon-amber">
                        <i class="fa-solid <?= $tierIcon ?>"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Cấp bậc & Tỷ lệ</div>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <span class="badge <?= $tierBadgeClass ?> px-2 py-1 fw-bold" style="font-size: 0.82rem;">
                                <?= htmlspecialchars($tierName) ?> (<?= $commissionRate ?>%)
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quy trình kiếm tiền 3 bước -->
            <div class="steps-card">
                <h5 class="fw-bold mb-4 text-dark"><i class="fa-solid fa-circle-nodes text-primary me-2"></i>Quy trình 3 bước nhận hoa hồng thụ động</h5>
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="step-item">
                            <div class="step-circle">1</div>
                            <div>
                                <h6 class="fw-bold mb-1">Lấy link hoặc mã QR</h6>
                                <p class="text-muted small mb-0">Sao chép đường link liên kết hoặc tải mã QR giới thiệu được cấp riêng cho bạn.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="step-item">
                            <div class="step-circle">2</div>
                            <div>
                                <h6 class="fw-bold mb-1">Chia sẻ cho bạn bè</h6>
                                <p class="text-muted small mb-0">Gửi cho bạn bè hoặc cộng đồng cày xu Golike đăng ký tài khoản tham gia hệ thống.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="step-item">
                            <div class="step-circle">3</div>
                            <div>
                                <h6 class="fw-bold mb-1">Nhận hoa hồng trọn đời</h6>
                                <p class="text-muted small mb-0">Hệ thống trích ngay 10% - 20% mỗi khi bạn bè thanh toán mua Key bản quyền hoặc thuê Cloud.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bảng Dữ Liệu: Tabs Chuyển Đổi (Lịch Sử Hoa Hồng & Danh Sách Bạn Bè) -->
            <div class="history-card">
                <div class="referral-tabs">
                    <button type="button" class="tab-btn active" onclick="switchReferralTab('commissions', this)">
                        <i class="fa-solid fa-clock-rotate-left"></i> Lịch sử nhận hoa hồng
                        <span class="tab-badge"><?= count($commissionHistory) ?></span>
                    </button>
                    <button type="button" class="tab-btn" onclick="switchReferralTab('members', this)">
                        <i class="fa-solid fa-user-group"></i> Bạn bè đã giới thiệu (F1)
                        <span class="tab-badge"><?= count($referredUsers) ?></span>
                    </button>
                </div>

                <!-- Tab 1: Lịch sử nhận hoa hồng -->
                <div id="tabCommissions" class="tab-content-panel active">
                    <?php if (empty($commissionHistory)): ?>
                        <div class="text-center py-5">
                            <div class="mb-3 text-muted" style="font-size: 3rem;">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                            <h6 class="fw-bold text-dark">Chưa có giao dịch hoa hồng nào</h6>
                            <p class="text-muted small">Khi bạn bè của bạn phát sinh đơn hàng, các khoản hoa hồng sẽ hiển thị chi tiết tại đây.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table-custom">
                                <thead>
                                    <tr>
                                        <th>Mã Đơn / Giao Dịch</th>
                                        <th>Thành Viên (F1)</th>
                                        <th>Dịch Vụ</th>
                                        <th>Giá Trị Đơn</th>
                                        <th>% Hoa Hồng</th>
                                        <th>Hoa Hồng Nhận</th>
                                        <th>Thời Gian</th>
                                        <th>Trạng Thái</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($commissionHistory as $comm): ?>
                                    <tr>
                                        <td>
                                            <span class="fw-bold font-monospace text-primary">
                                                #<?= htmlspecialchars($comm['order_code'] ?? ('ORD-' . $comm['id'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="fw-semibold text-dark"><?= htmlspecialchars($comm['referee_name'] ?? 'Ẩn danh') ?></div>
                                                <span class="badge bg-light text-muted font-monospace" style="font-size: 0.7rem;">#<?= htmlspecialchars($comm['referee_uid'] ?? '') ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($comm['service_type'] === 'buy_key'): ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><i class="fa-solid fa-key me-1"></i> Mua Key</span>
                                            <?php elseif ($comm['service_type'] === 'cloud'): ?>
                                                <span class="badge bg-info-subtle text-info border border-info-subtle"><i class="fa-solid fa-cloud me-1"></i> Thuê Cloud</span>
                                            <?php else: ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fa-solid fa-wallet me-1"></i> Nạp tiền</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="font-monospace text-muted"><?= format_currency($comm['order_amount']) ?></td>
                                        <td><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle fw-bold"><?= (float)$comm['commission_rate'] ?>%</span></td>
                                        <td>
                                            <span class="fw-bold text-success font-monospace">
                                                +<?= format_currency($comm['commission_amount']) ?>
                                            </span>
                                        </td>
                                        <td class="text-muted small"><?= date('d/m/Y H:i', strtotime($comm['created_at'])) ?></td>
                                        <td>
                                            <span class="status-pill status-completed">
                                                <i class="fa-solid fa-circle-check"></i> Đã cộng
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tab 2: Danh sách bạn bè đã giới thiệu -->
                <div id="tabMembers" class="tab-content-panel">
                    <?php if (empty($referredUsers)): ?>
                        <div class="text-center py-5">
                            <div class="mb-3 text-muted" style="font-size: 3rem;">
                                <i class="fa-solid fa-user-plus"></i>
                            </div>
                            <h6 class="fw-bold text-dark">Bạn chưa có thành viên F1 nào</h6>
                            <p class="text-muted small">Hãy sao chép link giới thiệu bên trên và gửi cho bạn bè để bắt đầu nhận hoa hồng.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table-custom">
                                <thead>
                                    <tr>
                                        <th>Thành Viên</th>
                                        <th>Tên Người Dùng</th>
                                        <th>Mã UID</th>
                                        <th>Ngày Tham Gia</th>
                                        <th>Hoa Hồng Mang Lại</th>
                                        <th>Tỷ Lệ Áp Dụng</th>
                                        <th>Trạng Thái</th>
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
                                        <td class="fw-bold text-success font-monospace">+<?= format_currency($f1['f1_commission']) ?></td>
                                        <td><span class="badge bg-primary-subtle text-primary"><?= (float)$f1['commission_rate'] ?>%</span></td>
                                        <td>
                                            <span class="status-pill status-active">
                                                <i class="fa-solid fa-circle"></i> Đang hoạt động
                                            </span>
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
                        <!-- Sử dụng API sinh mã QR độ nét cao trực tuyến -->
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
        // SAO CHÉP LIÊN KẾT GIỚI THIỆU & MÃ UID
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

        // ==========================================================
        // CHUYỂN ĐỔI TAB BẢNG DỮ LIỆU
        // ==========================================================
        function switchReferralTab(tabKey, btnElement) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content-panel').forEach(p => p.classList.remove('active'));

            if (btnElement) btnElement.classList.add('active');

            if (tabKey === 'commissions') {
                document.getElementById('tabCommissions').classList.add('active');
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
