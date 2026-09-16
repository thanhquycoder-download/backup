<?php
/**
 * ==========================================================
 * TRANG HỒ SƠ CÁ NHÂN (PROFILE) - NỀN TRẮNG ĐỘC LẬP
 * File: profile.php
 * Website: ThanhQuyTech
 * ==========================================================
 */

require_once __DIR__ . '/config/config.php';

require_login();

$stmt = $pdo->prepare("
    SELECT id, uid, uuid, name, username, email, balance, avatar, role, status, created_at, updated_at
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

// 1. Thống kê sản lượng từ bảng rankings liên kết theo users.uuid
$userRankStats = ['total_earned' => 0, 'total_points' => 0, 'active_days' => 0];
try {
    $stmtStats = $pdo->prepare("
        SELECT 
            COALESCE(SUM(amount), 0) as total_earned,
            COALESCE(SUM(points), 0) as total_points,
            COUNT(DISTINCT date) as active_days
        FROM rankings
        WHERE user_uuid = ?
    ");
    $stmtStats->execute([$user['uuid']]);
    $resStats = $stmtStats->fetch();
    if ($resStats) {
        $userRankStats = $resStats;
    }
} catch (Exception $e) {
    //
}

// 2. Thống kê theo từng nền tảng (JOIN platforms & rankings qua platform_id và user_uuid)
$userPlatforms = [];
try {
    $stmtPlatformStats = $pdo->prepare("
        SELECT 
            p.name as platform_name,
            p.icon as platform_icon,
            COALESCE(SUM(r.amount), 0) as platform_amount,
            COALESCE(SUM(r.points), 0) as platform_points
        FROM platforms p
        LEFT JOIN rankings r ON p.id = r.platform_id AND r.user_uuid = ?
        WHERE p.status = 'Active'
        GROUP BY p.id, p.name, p.icon
        ORDER BY platform_amount DESC
    ");
    $stmtPlatformStats->execute([$user['uuid']]);
    $userPlatforms = $stmtPlatformStats->fetchAll();
} catch (Exception $e) {
    //
}

// 3. Lịch sử biến động số dư từ bảng transactions liên kết theo users.uuid
$userTransactions = [];
try {
    $stmtTrans = $pdo->prepare("
        SELECT code, type, amount, balance_before, balance_after, status, note, created_at
        FROM transactions
        WHERE user_uuid = ?
        ORDER BY id DESC
        LIMIT 5
    ");
    $stmtTrans->execute([$user['uuid']]);
    $userTransactions = $stmtTrans->fetchAll();
} catch (Exception $e) {
    //
}

$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hồ sơ cá nhân - <?= htmlspecialchars(APP_NAME) ?></title>

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
            --accent: #06b6d4;
            --gradient-btn: linear-gradient(135deg, #4f46e5 0%, #6366f1 50%, #06b6d4 100%);
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

        /* ==========================================================
         * 1. HEADER CỐ ĐỊNH (FIXED TOPBAR) - CHỐNG TRƯỢT TREO KHI CUỘN
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
            overflow: hidden;
            max-width: 100%;
            box-sizing: border-box;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 14px;
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
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, #4f46e5 0%, #6366f1 50%, #06b6d4 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
        }

        .brand-name {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--text-heading);
            letter-spacing: -0.3px;
        }

        .brand-name span {
            color: var(--primary);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
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
            overflow-x: hidden;
            width: calc(100% - 260px);
            max-width: 100vw;
        }

        .profile-container-inner {
            max-width: 1020px;
            margin: 0 auto;
            width: 100%;
            min-width: 0;
        }

        .profile-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 36px 32px;
            box-shadow: var(--shadow-card);
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 22px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 28px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }

        .profile-avatar {
            width: 92px;
            height: 92px;
            border-radius: 50%;
            border: 3px solid #6366f1;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.25);
            object-fit: cover;
        }

        .profile-name {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-heading);
            margin-bottom: 4px;
        }

        .profile-handle {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .stat-box {
            background: #f8fafc;
            border: 1px solid var(--card-border);
            padding: 20px;
            border-radius: var(--radius-md);
            transition: var(--transition);
        }

        .stat-box:hover {
            border-color: #cbd5e1;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.04);
            transform: translateY(-2px);
        }

        .stat-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--text-muted);
            font-weight: 700;
            margin-bottom: 6px;
        }

        .stat-value {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--text-heading);
            word-break: break-all;
        }

        .stat-value.highlight {
            color: #4f46e5;
            font-size: 1.55rem;
        }

        .uuid-box {
            font-family: monospace;
            font-size: 0.85rem;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 6px 12px;
            border-radius: 8px;
            color: #0284c7;
            display: inline-block;
        }

        .badge-role {
            background: #ede9fe;
            color: #7c3aed;
            font-weight: 700;
            font-size: 0.78rem;
            padding: 5px 12px;
            border-radius: 50px;
        }

        .badge-status {
            background: #dcfce7;
            color: #15803d;
            font-weight: 700;
            font-size: 0.78rem;
            padding: 5px 12px;
            border-radius: 50px;
        }

        .btn-gradient-sm {
            background: var(--gradient-btn);
            color: #ffffff;
            border: none;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
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
            width: 84px;
            height: 84px;
            fill: none;
            stroke-width: 4.5;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .svg-success .svg-circle {
            stroke: #10b981;
            stroke-dasharray: 220;
            stroke-dashoffset: 220;
            animation: strokeCircle 0.6s ease-out forwards;
        }
        .svg-success .svg-check {
            stroke: #10b981;
            stroke-dasharray: 60;
            stroke-dashoffset: 60;
            animation: strokeCheck 0.4s 0.45s ease-out forwards;
        }

        .svg-confirm .svg-circle {
            stroke: #0284c7;
            stroke-dasharray: 220;
            stroke-dashoffset: 220;
            animation: strokeCircle 0.6s ease-out forwards;
        }
        .svg-confirm .svg-question {
            stroke: #0284c7;
            stroke-dasharray: 60;
            stroke-dashoffset: 60;
            animation: strokeQuestion 0.4s 0.45s ease-out forwards;
        }
        .svg-confirm .svg-question-dot {
            fill: #0284c7;
            stroke: none;
            transform: scale(0);
            transform-origin: 40px 56px;
            animation: scaleDot 0.25s 0.75s forwards;
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
                padding: 12px 6px 50px !important;
                overflow-x: hidden !important;
                box-sizing: border-box !important;
            }
            .profile-container-inner {
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
                overflow-x: hidden !important;
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
            .profile-card {
                padding: 14px 10px !important;
                border-radius: 14px !important;
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
                box-sizing: border-box !important;
            }
            .stats-grid {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }
            .stat-box {
                padding: 14px !important;
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
    <!-- Hộp Thoại Dialog SVG Stroke Draw -->
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
                    <span class="balance-val"><?= format_currency($user['balance']) ?></span>
                </div>
            </a>

            <!-- Ảnh avatar hồ sơ & Bảng Popup Hồ Sơ -->
            <div class="user-profile-container" id="userDropdownContainer">
                <button type="button" class="user-profile-toggle" id="userProfileToggle" onclick="toggleUserPopup(event)" aria-expanded="false" title="<?= htmlspecialchars($user['name']) ?>">
                    <img src="<?= htmlspecialchars($user['avatar']) ?>" 
                         alt="Avatar" 
                         class="user-avatar-small"
                         onerror="this.onerror=null; this.src='assets/images/default-avatar.svg';">
                </button>

                <!-- Bảng Popup Thông Tin & Chức Năng Hồ Sơ -->
                <div class="user-profile-popup" id="userProfilePopup">
                    <!-- Thông tin người dùng -->
                    <div class="d-flex align-items-center gap-3 pb-3 border-bottom mb-3">
                        <img src="<?= htmlspecialchars($user['avatar']) ?>" 
                             alt="Avatar" 
                             style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid #e0e7ff; background: #eef2ff;"
                             onerror="this.onerror=null; this.src='assets/images/default-avatar.svg';">
                        <div style="min-width: 0; flex-grow: 1;">
                            <div class="fw-bold text-dark text-truncate" style="font-size: 0.95rem;"><?= htmlspecialchars($user['name']) ?></div>
                            <div class="text-muted small text-truncate"><?= htmlspecialchars($user['username']) ?></div>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <span class="badge font-monospace text-primary bg-primary-subtle px-2 py-0" style="font-size: 0.7rem;">UID: #<?= htmlspecialchars($user['uid']) ?></span>
                                <?php if ($user['role'] === 'Admin'): ?>
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
                            <div class="fw-bold" style="color: #15803d; font-size: 0.95rem;"><?= format_currency($user['balance']) ?></div>
                        </div>
                        <a href="/payments/deposit" class="btn btn-sm btn-success rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;">
                            <i class="fa-solid fa-circle-arrow-down me-1"></i> Nạp tiền
                        </a>
                    </div>

                    <!-- Các mục điều hướng -->
                    <div class="d-flex flex-column gap-1">
                        <a href="profile.php" class="popup-menu-item text-primary fw-bold">
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
     * MENU SIDEBAR CỐ ĐỊNH TRÁI
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
                <a href="support.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-headset"></i></span>
                    <span class="sidebar-title">Hỗ trợ</span>
                    <span class="badge-history ms-auto"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                </a>
            </li>
            <!-- Access Token -->
            <li>
                <a href="token.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fingerprint"></i></span>
                    <span class="sidebar-title">Access Token</span>
                </a>
            </li>
            <!-- Cấu hình -->
            <li>
                <a href="settings.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-gear"></i></span>
                    <span class="sidebar-title">Cấu hình</span>
                </a>
            </li>
            <?php if ($isAdmin): ?>
            <!-- Admin Panel (Chỉ hiển thị cho Admin) -->
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
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeAppSidebar()"></div>

    <!-- KHU VỰC NỘI DUNG CHÍNH (APP MAIN) -->
    <main class="app-main">
        <div class="profile-container-inner">

        <div class="profile-card">
            <div class="profile-header">
                <div>
                    <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="Avatar" class="profile-avatar">
                </div>
                <div class="flex-grow-1">
                    <h2 class="profile-name"><?= htmlspecialchars($user['name']) ?></h2>
                    <div class="profile-handle">
                        <span class="text-primary fw-semibold"><?= htmlspecialchars($user['username']) ?></span> &bull; <span><?= htmlspecialchars($user['email']) ?></span>
                    </div>
                    <div class="d-flex gap-2 mt-2 flex-wrap">
                        <span class="badge-role">
                            <i class="fa-solid fa-shield-halved me-1"></i> Vai trò: <?= htmlspecialchars($user['role']) ?>
                        </span>
                        <span class="badge-status">
                            <i class="fa-solid fa-circle-check me-1"></i> Trạng thái: <?= htmlspecialchars($user['status']) ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Grid Thống kê -->
            <div class="stats-grid">
                <!-- UID 7 số -->
                <div class="stat-box">
                    <div class="stat-label">
                        <i class="fa-solid fa-id-card text-primary me-1"></i> Mã định danh (UID 7 số)
                    </div>
                    <div class="stat-value text-primary font-monospace">
                        #<?= htmlspecialchars($user['uid']) ?>
                    </div>
                    <div class="text-muted small mt-1">Mã số riêng biệt của mỗi tài khoản</div>
                </div>

                <!-- Số dư khả dụng -->
                <div class="stat-box">
                    <div class="stat-label">
                        <i class="fa-solid fa-wallet text-warning me-1"></i> Số dư nạp vào (Balance)
                    </div>
                    <div class="stat-value highlight">
                        <?= format_currency($user['balance']) ?>
                    </div>
                    <div class="text-muted small mt-1">Dùng để thanh toán các dịch vụ</div>
                </div>

                <!-- UUIDv7 -->
                <div class="stat-box" style="grid-column: 1 / -1;">
                    <div class="stat-label">
                        <i class="fa-solid fa-network-wired text-info me-1"></i> Mã định danh liên kết bảng (UUIDv7)
                    </div>
                    <div class="d-flex align-items-center gap-2 mt-2 flex-wrap">
                        <span class="uuid-box" id="uuidText"><?= htmlspecialchars($user['uuid']) ?></span>
                        <button type="button" class="btn-gradient-sm" onclick="copyUUID()">
                            <i class="fa-regular fa-copy me-1"></i> Sao chép
                        </button>
                    </div>
                    <div class="text-muted small mt-2">
                        Chuẩn UUIDv7 có sắp xếp theo thời gian khởi tạo, liên kết các bảng tối ưu hiệu suất.
                    </div>
                </div>

                <!-- Ngày tạo & Ngày cập nhật -->
                <div class="stat-box">
                    <div class="stat-label">
                        <i class="fa-regular fa-calendar-plus me-1"></i> Ngày đăng ký
                    </div>
                    <div class="stat-value text-muted" style="font-size: 0.95rem;">
                        <?= date('H:i:s d/m/Y', strtotime($user['created_at'])) ?>
                    </div>
                </div>

                <div class="stat-box">
                    <div class="stat-label">
                        <i class="fa-regular fa-clock me-1"></i> Cập nhật lần cuối
                    </div>
                    <div class="stat-value text-muted" style="font-size: 0.95rem;">
                        <?= date('H:i:s d/m/Y', strtotime($user['updated_at'])) ?>
                    </div>
                </div>

                <!-- Trạng thái JWT -->
                <div class="stat-box" style="grid-column: 1 / -1;">
                    <div class="stat-label">
                        <i class="fa-solid fa-database text-success me-1"></i> Phiên đăng nhập JWT (LocalStorage)
                    </div>
                    <div class="mt-2 d-flex align-items-center gap-2">
                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 rounded-pill small">
                            <i class="fa-solid fa-check me-1"></i> JWT đang hoạt động trong LocalStorage
                        </span>
                        <span class="text-muted small">Tự động duy trì đăng nhập khi mở lại trình duyệt</span>
                    </div>
                </div>
            </div>

            <!-- KHỐI LIÊN KẾT BẢNG 1: SẢN LƯỢNG RANKINGS & PLATFORMS (Liên kết qua UUIDv7) -->
            <div class="mt-4 pt-4 border-top">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="fa-solid fa-trophy text-warning me-2"></i>Sản Lượng Đua Top Cá Nhân
                    </h5>
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-3 py-2 rounded-pill small">
                        <i class="fa-solid fa-link me-1"></i> Liên kết: rankings.user_uuid &rarr; users.uuid
                    </span>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-4">
                        <div class="p-3 bg-light rounded-3 border text-center">
                            <div class="text-muted small fw-semibold text-uppercase">Tổng Doanh Thu Đã Kiếm</div>
                            <div class="fs-5 fw-bold text-success mt-1"><?= format_currency($userRankStats['total_earned']) ?></div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="p-3 bg-light rounded-3 border text-center">
                            <div class="text-muted small fw-semibold text-uppercase">Tổng Điểm / Tác Vụ</div>
                            <div class="fs-5 fw-bold text-primary mt-1"><?= number_format($userRankStats['total_points']) ?> pts</div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="p-3 bg-light rounded-3 border text-center">
                            <div class="text-muted small fw-semibold text-uppercase">Số Ngày Ghi Nhận</div>
                            <div class="fs-5 fw-bold text-dark mt-1"><?= number_format($userRankStats['active_days']) ?> ngày</div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($userPlatforms)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle border mb-0 rounded-3 overflow-hidden">
                        <thead class="table-light small text-muted">
                            <tr>
                                <th>NỀN TẢNG (PLATFORM)</th>
                                <th class="text-end">DOANH THU</th>
                                <th class="text-end">ĐIỂM / TÁC VỤ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($userPlatforms as $up): ?>
                            <tr>
                                <td>
                                    <i class="fa-solid <?= htmlspecialchars($up['platform_icon']) ?> text-primary me-2"></i>
                                    <strong><?= htmlspecialchars($up['platform_name']) ?></strong>
                                </td>
                                <td class="text-end fw-semibold text-dark"><?= format_currency($up['platform_amount']) ?></td>
                                <td class="text-end text-muted"><?= number_format($up['platform_points']) ?> pts</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- KHỐI LIÊN KẾT BẢNG 2: BIẾN ĐỘNG SỐ DƯ TRANSACTIONS (Liên kết qua UUIDv7) -->
            <div class="mt-4 pt-4 border-top">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="fa-solid fa-receipt text-info me-2"></i>Lịch Sử Giao Dịch Số Dư
                    </h5>
                    <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-3 py-2 rounded-pill small">
                        <i class="fa-solid fa-link me-1"></i> Liên kết: transactions.user_uuid &rarr; users.uuid
                    </span>
                </div>

                <?php if (!empty($userTransactions)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle border mb-0 rounded-3 overflow-hidden">
                        <thead class="table-light small text-muted">
                            <tr>
                                <th>MÃ GD</th>
                                <th>LOẠI</th>
                                <th>SỐ TIỀN</th>
                                <th>SỐ DƯ SAU GD</th>
                                <th>TRẠNG THÁI</th>
                                <th>THỜI GIAN</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($userTransactions as $tx): ?>
                            <tr>
                                <td><span class="font-monospace fw-semibold text-primary"><?= htmlspecialchars($tx['code']) ?></span></td>
                                <td>
                                    <span class="badge bg-<?= ($tx['type'] === 'Deposit') ? 'success' : (($tx['type'] === 'Payment') ? 'danger' : 'secondary') ?> bg-opacity-10 text-<?= ($tx['type'] === 'Deposit') ? 'success' : (($tx['type'] === 'Payment') ? 'danger' : 'secondary') ?> px-2 py-1">
                                        <?= htmlspecialchars($tx['type']) ?>
                                    </span>
                                </td>
                                <td class="fw-bold text-<?= ($tx['type'] === 'Deposit') ? 'success' : 'danger' ?>">
                                    <?= ($tx['type'] === 'Deposit' ? '+' : '-') . format_currency($tx['amount']) ?>
                                </td>
                                <td class="text-muted"><?= format_currency($tx['balance_after']) ?></td>
                                <td>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25">
                                        <?= htmlspecialchars($tx['status']) ?>
                                    </span>
                                </td>
                                <td class="text-muted small"><?= date('H:i d/m/Y', strtotime($tx['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="p-3 text-center bg-light rounded-3 text-muted small">
                    <i class="fa-solid fa-circle-info me-1"></i> Chưa có ghi nhận giao dịch biến động số dư nào.
                </div>
                <?php endif; ?>
            </div>

            <div class="p-3 bg-light border rounded-3 small text-muted mt-4">
                <i class="fa-solid fa-shield-halved text-primary me-1"></i>
                <strong>Bảo mật tài khoản:</strong> Mật khẩu mã hóa Hash + Salt + Stretching (BCrypt Cost 12) + Application Pepper. Duy trì phiên qua chữ ký điện tử JWT (HMAC-SHA256).
            </div>
        </div>
        </div> <!-- /profile-container-inner -->
    </main> <!-- /app-main -->

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
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

        function copyUUID() {
            const uuidText = document.getElementById('uuidText').innerText;
            navigator.clipboard.writeText(uuidText).then(() => {
                showSvgAlert(
                    'Đã sao chép mã UUIDv7 vào clipboard:<br><div class="mt-2 p-2 bg-light border rounded text-primary font-monospace">' + uuidText + '</div>',
                    'Sao chép thành công',
                    'success'
                );
            });
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

        // ==========================================================
        // ĐIỀU KHIỂN ĐÓNG/MỞ SIDEBAR (MOBILE & DESKTOP)
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
