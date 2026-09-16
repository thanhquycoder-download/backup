<?php
/**
 * ==========================================================
 * TRANG QUẢN LÝ ACCESS TOKEN & KHÓA API - THANHQUYTECH
 * File: token.php
 * Website: ThanhQuyTech
 * ==========================================================
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/token/golike.php';

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

// 2. Tự động kiểm tra và khởi tạo bảng `tokens` nếu chưa có
try {
    ensure_tokens_table($pdo);
} catch (Exception $e) {
    // Bỏ qua nếu bảng đã tồn tại
}

// 3. XỬ LÝ CÁC HÀNH ĐỘNG POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Phiên làm việc hoặc mã bảo mật CSRF không hợp lệ. Vui lòng tải lại trang.', 'Lỗi xác thực');
        header("Location: token.php");
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ==========================================
    // 3.1. LƯU HOẶC CẬP NHẬT TOKEN ĐA NỀN TẢNG (GOLIKE, TDS, TTC...)
    // ==========================================
    if ($action === 'save_token' || $action === 'save_golike_token') {
        $platform = strtolower(trim($_POST['platform'] ?? 'golike'));
        $rawToken = trim($_POST['token'] ?? $_POST['golike_token'] ?? '');

        if (empty($rawToken)) {
            set_flash('error', 'Vui lòng nhập hoặc dán mã Token (JWT Token hoặc Access Token).', 'Thiếu thông tin');
            header("Location: token.php");
            exit;
        }

        $res = save_or_update_platform_account($pdo, $user['uuid'], $platform, $rawToken);
        if ($res['success']) {
            $platName = strtoupper($res['platform'] ?? $platform);
            set_flash('success', "Đã kết nối tài khoản {$platName} thành công: <strong>" . htmlspecialchars($res['name']) . "</strong> (@" . htmlspecialchars($res['username']) . ") - ID: <strong>#" . htmlspecialchars($res['account_id']) . "</strong> - Số dư: <strong class='text-success'>" . $res['coin_formatted'] . "</strong>", 'Kết nối thành công');
        } else {
            set_flash('error', $res['message'], 'Lỗi kết nối');
        }
        header("Location: token.php");
        exit;
    }

    // ==========================================
    // 3.2. LÀM MỚI SỐ DƯ TÀI KHOẢN
    // ==========================================
    if ($action === 'refresh_token' || $action === 'refresh_golike_token') {
        $accountId = (int)($_POST['account_id'] ?? $_POST['golike_account_id'] ?? 0);
        $res = refresh_platform_account($pdo, $user['uuid'], $accountId);
        if ($res['success']) {
            set_flash('success', $res['message'], 'Cập nhật số dư');
        } else {
            set_flash('error', $res['message'], 'Cảnh báo Token');
        }
        header("Location: token.php");
        exit;
    }

    // ==========================================
    // 3.3. XÓA TÀI KHOẢN KHỎI DANH SÁCH
    // ==========================================
    if ($action === 'delete_token' || $action === 'delete_golike_token') {
        $accountId = (int)($_POST['account_id'] ?? $_POST['golike_account_id'] ?? 0);
        ensure_tokens_table($pdo);
        $stmtDel = $pdo->prepare("DELETE FROM `tokens` WHERE `id` = ? AND `user_uuid` = ?");
        $stmtDel->execute([$accountId, $user['uuid']]);
        set_flash('success', 'Đã xóa tài khoản khỏi danh sách lưu trữ.', 'Đã xóa');
        header("Location: token.php");
        exit;
    }
}

// 4.1. LẤY DANH SÁCH TÀI KHOẢN TỪ BẢNG `tokens`
$accounts = [];
try {
    ensure_tokens_table($pdo);
    $stmtAccounts = $pdo->prepare("
        SELECT id, platform, account_id, account_id AS golike_id, name, username, coin, token, status, last_checked_at, created_at, updated_at 
        FROM `tokens` 
        WHERE `user_uuid` = ? 
        ORDER BY id DESC
    ");
    $stmtAccounts->execute([$user['uuid']]);
    $accounts = $stmtAccounts->fetchAll();
} catch (Exception $e) {
    $accounts = [];
}

// 4.2. THỐNG KÊ TỔNG QUAN TÀI KHOẢN & XU
$totalAccounts = count($accounts);
$totalCoins = 0;
$activeAccounts = 0;
$expiredAccounts = 0;

foreach ($accounts as $acc) {
    $totalCoins += (int)$acc['coin'];
    if ($acc['status'] === 'Active') {
        $activeAccounts++;
    } else {
        $expiredAccounts++;
    }
}

// 4.3. LẤY LOGO CÁC NỀN TẢNG TỪ BẢNG `platforms`
$platformLogos = [
    'golike' => 'https://cdn.jsdelivr.net/gh/thanhquytech-stack/images@main/golike.png'
];
try {
    $stmtPlats = $pdo->query("SELECT `code`, `image` FROM `platforms` WHERE `image` IS NOT NULL AND `image` != ''");
    if ($stmtPlats) {
        while ($pRow = $stmtPlats->fetch(PDO::FETCH_ASSOC)) {
            $platformLogos[strtolower(trim($pRow['code']))] = $pRow['image'];
        }
    }
} catch (Exception $e) {
    // Sử dụng fallback nếu bảng chưa cập nhật
}
$golikeLogo = $platformLogos['golike'] ?? 'https://cdn.jsdelivr.net/gh/thanhquytech-stack/images@main/golike.png';

$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Quản Lý Token & Tài Khoản - ThanhQuyTech</title>
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


        /* BỐ CỤC CÁC CARD (MỖI HÀNG 1 CARD ĐỘC LẬP CHUẨN 1:1 THEO DEPOSIT.PHP) */
        .deposit-layout {
            display: flex;
            flex-direction: column;
            gap: 24px;
            margin-bottom: 30px;
        }

        .deposit-card,
        .section-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 28px 32px;
            box-shadow: var(--shadow-card);
            width: 100%;
        }

        @media (max-width: 991.98px) {
            .deposit-card,
            .section-card {
                padding: 20px 18px;
            }
        }

        .card-header-title,
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
            flex-wrap: wrap;
            gap: 14px;
        }

        .card-title-text,
        .section-title {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        /* MULTI-PLATFORM STYLES */

        /* GOLIKE STYLES */
        .coin-badge {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #ffffff;
            font-weight: 800;
            font-size: 0.88rem;
            padding: 5px 14px;
            border-radius: 50px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
            white-space: nowrap !important;
        }

        .stat-icon-gold {
            background: #fef3c7;
            color: #d97706;
        }

        .golike-avatar {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #f1f5f9;
            color: #4338ca;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border: 1px solid var(--card-border);
            white-space: nowrap !important;
            transition: var(--transition);
        }

        .platform-avatar-golike {
            background: #fefce8 !important;
            border-color: #fde047 !important;
            color: #ca8a04 !important;
        }

        .platform-avatar-tds {
            background: #f0f9ff !important;
            border-color: #7dd3fc !important;
            color: #0284c7 !important;
        }

        .platform-avatar-ttc {
            background: #f0fdf4 !important;
            border-color: #86efac !important;
            color: #16a34a !important;
        }

        .platform-avatar-other {
            background: #f8fafc !important;
            border-color: #cbd5e1 !important;
            color: #64748b !important;
        }

        /* AVATAR TÀI KHOẢN & PILL NỀN TẢNG (THEO ẢNH THIẾT KẾ) */
        .account-avatar-user {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: #4361ee;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.12rem;
            flex-shrink: 0;
            box-shadow: 0 3px 8px rgba(67, 97, 238, 0.28);
        }

        .platform-pill-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f0f7ff;
            color: #2563eb;
            border: 1px solid #dbeafe;
            padding: 5px 14px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 700;
            box-shadow: 0 1px 3px rgba(37, 99, 235, 0.05);
            white-space: nowrap !important;
        }

        .platform-pill-badge.platform-pill-golike {
            background: #eff6ff;
            color: #2563eb;
            border-color: #dbeafe;
        }

        .platform-pill-badge.platform-pill-tds {
            background: #f0f9ff;
            color: #0284c7;
            border-color: #bae6fd;
        }

        .platform-pill-badge.platform-pill-ttc {
            background: #f0fdf4;
            color: #16a34a;
            border-color: #bbf7d0;
        }

        .platform-pill-badge.platform-pill-other {
            background: #f8fafc;
            color: #475569;
            border-color: #e2e8f0;
        }

        /* ẢNH NỀN TẢNG BO TRÒN (GOLIKE & CÁC NỀN TẢNG) */
        .platform-logo-rounded {
            width: 20px;
            height: 20px;
            border-radius: 50% !important;
            object-fit: cover;
            display: inline-block;
            flex-shrink: 0;
            vertical-align: middle;
            border: none !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        .platform-logo-rounded-md {
            width: 25px;
            height: 25px;
            border-radius: 50% !important;
            object-fit: cover;
            display: inline-block;
            flex-shrink: 0;
            vertical-align: middle;
            border: none !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        .platform-logo-rounded-lg {
            width: 36px;
            height: 36px;
            border-radius: 50% !important;
            object-fit: cover;
            display: inline-block;
            flex-shrink: 0;
            vertical-align: middle;
            border: none !important;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
        }

        /* LOẠI BỎ HOÀN TOÀN KHUNG VIỀN & NỀN XUNG QUANH KHI LÀ LOGO ẢNH */
        .platform-symbol-badge.badge-no-frame,
        .platform-symbol-badge:has(img) {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
        }

        .platform-item-icon.icon-no-frame,
        .platform-item-icon:has(img),
        .platform-avatar-golike:has(img),
        .platform-avatar-golike.icon-no-frame,
        .golike-avatar.icon-no-frame,
        .golike-avatar:has(img) {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
        }

        /* KHUNG NỀN TẢNG & DROPDOWN TÙY BIẾN CAO CẤP FINTECH (GỌN GÀNG, ĐẸP MẮT) */
        .custom-platform-dropdown-wrapper {
            position: relative;
            width: 100%;
        }

        .premium-platform-box {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            padding: 6px 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.03);
            transition: all 0.2s ease;
            cursor: pointer;
            user-select: none;
            min-height: 48px;
        }

        .premium-platform-box:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
        }

        .premium-platform-box.open {
            border-color: #6366f1;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12), 0 4px 14px rgba(99, 102, 241, 0.06);
        }

        .platform-symbol-badge {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
            color: #4f46e5;
            border: 1px solid #c7d2fe;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            font-weight: 800;
            flex-shrink: 0;
            box-shadow: 0 2px 4px rgba(79, 70, 229, 0.08);
            transition: all 0.2s ease;
        }

        .platform-field-inner {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            justify-content: center;
        }

        .platform-field-label {
            font-size: 0.62rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #94a3b8;
            margin-bottom: 1px;
            line-height: 1;
            display: block;
        }

        .platform-selected-name {
            font-size: 0.94rem;
            font-weight: 700;
            color: #0f172a;
            font-family: 'Plus Jakarta Sans', sans-serif;
            line-height: 1.25;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dropdown-arrow-icon {
            font-size: 0.75rem;
            color: #94a3b8;
            transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1), color 0.2s ease;
        }

        .premium-platform-box.open .dropdown-arrow-icon {
            transform: rotate(180deg);
            color: #4f46e5;
        }

        .custom-platform-dropdown-menu {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 12px 30px -4px rgba(15, 23, 42, 0.12), 0 4px 10px -2px rgba(15, 23, 42, 0.04);
            padding: 6px;
            z-index: 1050;
            display: none;
            animation: platformDropdownIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .custom-platform-dropdown-menu.show {
            display: block;
        }

        @keyframes platformDropdownIn {
            from {
                opacity: 0;
                transform: translateY(-6px) scale(0.99);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .dropdown-platform-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.15s ease;
            color: #1e293b;
            user-select: none;
        }

        .dropdown-platform-item:hover {
            background: #f8fafc;
        }

        .dropdown-platform-item.active {
            background: #eef2ff;
            color: #4338ca;
        }

        .dropdown-platform-item-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .platform-item-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
        }

        .dropdown-platform-item-title {
            font-size: 0.9rem;
            font-weight: 700;
            line-height: 1.25;
            color: inherit;
        }

        .dropdown-platform-item-sub {
            font-size: 0.74rem;
            color: #64748b;
            font-weight: 500;
            line-height: 1.2;
        }

        .dropdown-platform-item.active .dropdown-platform-item-sub {
            color: #6366f1;
        }

        .platform-item-check {
            font-size: 1rem;
            color: #10b981;
            opacity: 0;
            transition: opacity 0.15s ease, transform 0.15s ease;
            transform: scale(0.85);
        }

        .dropdown-platform-item.active .platform-item-check {
            opacity: 1;
            transform: scale(1);
            color: #10b981;
        }

        .platform-tag-pill {
            background: #f8fafc;
            color: #475569;
            font-weight: 800;
            font-size: 0.76rem;
            padding: 5px 12px;
            border-radius: 8px;
            letter-spacing: 0.5px;
            border: 1px solid #e2e8f0;
            flex-shrink: 0;
            white-space: nowrap;
        }

        .token-textarea-field {
            width: 100%;
            background: #ffffff;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px 18px;
            font-size: 0.88rem;
            font-family: 'Fira Code', monospace;
            color: #1e293b;
            outline: none;
            resize: vertical;
            min-height: 88px;
            line-height: 1.55;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.02);
        }

        .token-textarea-field:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12), 0 4px 14px rgba(99, 102, 241, 0.06);
            background: #ffffff;
        }

        .token-textarea-field::placeholder {
            color: #94a3b8;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 0.84rem;
        }

        .btn-action-save {
            background: var(--gradient-primary);
            color: #ffffff;
            font-weight: 700;
            font-size: 1rem;
            border: none;
            padding: 13px 28px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            box-shadow: 0 6px 18px rgba(79, 70, 229, 0.35);
            transition: var(--transition);
            text-decoration: none;
            width: 100%;
        }

        .btn-action-save:hover {
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(79, 70, 229, 0.45);
        }

        /* HISTORY COUNT BADGE (CHUẨN 1:1 THEO DEPOSIT.PHP) */
        .history-count-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: linear-gradient(135deg, #f0f7ff 0%, #e0eefe 100%);
            border: 1px solid #bfdbfe;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.82rem;
            color: #1e293b;
            box-shadow: 0 1px 3px rgba(37, 99, 235, 0.08);
            transition: all 0.2s ease;
        }

        .history-count-badge:hover {
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.15);
            border-color: #93c5fd;
        }

        .count-badge-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #2563eb;
            color: #ffffff;
            font-size: 0.68rem;
            flex-shrink: 0;
        }

        .count-badge-label {
            font-weight: 600;
            color: #475569;
            font-size: 0.8rem;
        }

        .count-badge-number {
            font-weight: 800;
            font-size: 0.85rem;
            color: #1d4ed8;
            background: #ffffff;
            padding: 1px 8px;
            border-radius: 20px;
            border: 1px solid #bfdbfe;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
            display: inline-block;
            line-height: 1.35;
        }

        .live-preview-card {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1.5px solid #86efac;
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin-top: 14px;
            animation: fadeInMenu 0.3s ease-in-out;
        }

        /* TABLE */
        .token-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            white-space: nowrap !important;
        }

        .token-table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 14px 18px;
            border-bottom: 1px solid var(--card-border);
            white-space: nowrap !important;
        }

        .token-table td {
            padding: 14px 18px;
            border-bottom: 1px solid var(--card-border);
            vertical-align: middle;
            font-size: 0.9rem;
            white-space: nowrap !important;
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
            white-space: nowrap !important;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 0.78rem;
            font-weight: 700;
            white-space: nowrap !important;
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

        /* ==========================================================
         * TỐI ƯU GIAO DIỆN MOBILE CHUYÊN BIỆT CHO FORM TOKEN
         * ========================================================== */
        @media (max-width: 576px) {
            .deposit-card,
            .section-card {
                padding: 18px 14px !important;
                border-radius: 16px !important;
            }

            .card-header-title {
                display: flex !important;
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 8px !important;
                margin-bottom: 16px !important;
                padding-bottom: 12px !important;
            }

            .card-title-text {
                font-size: 1.05rem !important;
                line-height: 1.35 !important;
                letter-spacing: -0.2px !important;
            }

            .guide-toggle-btn {
                font-size: 0.76rem !important;
                padding: 4px 12px !important;
                background: #f8fafc !important;
                border-color: #cbd5e1 !important;
                color: #475569 !important;
            }

            /* Dropdown nền tảng trên mobile */
            .premium-platform-box {
                padding: 8px 12px !important;
                min-height: 48px !important;
                gap: 10px !important;
                border-radius: 12px !important;
            }

            .platform-field-label {
                font-size: 0.6rem !important;
                margin-bottom: 2px !important;
            }

            .platform-selected-name {
                font-size: 0.88rem !important;
            }

            /* Ẩn tag pill trên mobile để tiêu đề nền tảng không bị cắt ngắn thành '...' */
            .platform-tag-pill {
                display: none !important;
            }

            /* Nhãn và nút Dán Token */
            #tokenInputLabel {
                font-size: 0.88rem !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
            }

            .btn-paste-token {
                font-size: 0.78rem !important;
                padding: 4px 12px !important;
                white-space: nowrap !important;
                border-radius: 50px !important;
            }

            .token-textarea-field {
                font-size: 0.82rem !important;
                padding: 11px 13px !important;
                border-radius: 12px !important;
                min-height: 80px !important;
                line-height: 1.45 !important;
            }

            .btn-action-save {
                padding: 12px 18px !important;
                font-size: 0.95rem !important;
                border-radius: 12px !important;
            }

            .custom-platform-dropdown-menu {
                border-radius: 12px !important;
                padding: 4px !important;
            }

            .dropdown-platform-item {
                padding: 7px 10px !important;
            }

            .dropdown-platform-item-title {
                font-size: 0.86rem !important;
            }

            .dropdown-platform-item-sub {
                font-size: 0.7rem !important;
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
            <!-- BREADCRUMB -->
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb mb-0" style="font-size: 0.85rem; font-weight: 600;">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted"><i class="fa-solid fa-house me-1"></i> Trang chủ</a></li>
                    <li class="breadcrumb-item active text-primary" aria-current="page">Quản Lý Token & Tài Khoản</li>
                </ol>
            </nav>

            <!-- FLASH NOTIFICATIONS -->
            <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> alert-dismissible fade show rounded-3 shadow-sm border-0 mb-4" role="alert">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-circle-check fs-5"></i>
                    <div>
                        <strong><?= htmlspecialchars($flash['title']) ?>:</strong> <?= $flash['message'] ?>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <!-- HERO CARD -->
            <div class="token-hero">
                <div class="hero-badge">
                    <i class="fa-solid fa-layer-group"></i>
                    <span>CÔNG CỤ ĐỒNG BỘ TOKEN ĐA NỀN TẢNG (GOLIKE, TDS, TTC...)</span>
                </div>
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                    <div>
                        <h1 class="hero-title">Quản Lý Token & Tài Khoản</h1>
                        <p class="hero-subtitle">
                            Hỗ trợ lưu trữ và tự động đồng bộ tài khoản Golike, Trao Đổi Sub (TDS), Tương Tác Chéo (TTC)... Nhập mã JWT token hoặc Access token để tự động trích xuất Họ tên, Username, ID và cập nhật số dư coin theo thời gian thực.
                        </p>
                    </div>
                    <div class="flex-shrink-0">
                        <a href="#addTokenCard" class="btn btn-light fw-bold px-4 py-2 rounded-3 shadow-sm">
                            <i class="fa-solid fa-plus-circle text-primary me-2"></i> Thêm Token Mới
                        </a>
                    </div>
                </div>
            </div>

            <!-- STATS CARDS -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon-wrapper stat-icon-gold">
                            <i class="fa-solid fa-coins"></i>
                        </div>
                        <div>
                            <div class="stat-label">Tổng số xu (Coin)</div>
                            <div class="stat-value text-warning fw-bolder"><?= number_format($totalCoins, 0, ',', '.') ?> <span style="font-size: 0.82rem; font-weight: 700;">xu</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon-wrapper stat-icon-primary">
                            <i class="fa-solid fa-users"></i>
                        </div>
                        <div>
                            <div class="stat-label">Tổng tài khoản</div>
                            <div class="stat-value text-primary"><?= number_format($totalAccounts) ?></div>
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
                            <div class="stat-value text-success"><?= number_format($activeAccounts) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon-wrapper stat-icon-warning">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>
                        <div>
                            <div class="stat-label">Cần cập nhật token</div>
                            <div class="stat-value text-muted"><?= number_format($expiredAccounts) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- BỐ CỤC CHÍNH (CHUẨN 1:1 THEO DEPOSIT.PHP) -->
            <div class="deposit-layout">

                <!-- CARD 1: FORM THÊM TOKEN MỚI -->
                <div class="deposit-card" id="addTokenCard">
                    <div class="card-header-title">
                        <div class="card-title-text">
                            <i class="fa-solid fa-key text-primary"></i>
                            <span>Thêm Token & Tự Động Đồng Bộ Tài Khoản</span>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-bold guide-toggle-btn" type="button" data-bs-toggle="collapse" data-bs-target="#collapseGuide" aria-expanded="false">
                            <i class="fa-solid fa-circle-question me-1 text-primary"></i> Hướng dẫn lấy Token
                        </button>
                    </div>

                    <!-- Hướng dẫn lấy token -->
                    <div class="collapse mb-4" id="collapseGuide">
                        <div class="p-3 bg-light rounded-3 border">
                            <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-lightbulb text-warning me-1"></i> Cách lấy Token từ các nền tảng:</h6>
                            <div class="row g-3 small text-muted">
                                <div class="col-md-4">
                                    <div class="p-3 bg-white rounded border h-100 shadow-sm">
                                        <strong class="text-warning d-block mb-1"><img src="<?= htmlspecialchars($golikeLogo) ?>" alt="Golike" class="platform-logo-rounded me-1" style="width: 18px; height: 18px;"> Golike:</strong>
                                        Đăng nhập <code>app.golike.net</code> &gt; nhấn <code>F12</code> &gt; tab <strong>Network</strong> &gt; lọc <strong>Fetch/XHR</strong> &gt; tìm request <code>me</code> &gt; sao chép giá trị chuỗi <code>authorization</code>.
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-3 bg-white rounded border h-100 shadow-sm">
                                        <strong class="text-info d-block mb-1"><i class="fa-solid fa-bolt me-1"></i> Trao Đổi Sub:</strong>
                                        Đăng nhập <code>traodoisub.com</code> &gt; vào mục <strong>Cài đặt</strong> hoặc <strong>API</strong> &gt; sao chép chuỗi <strong>Access Token</strong> của bạn.
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-3 bg-white rounded border h-100 shadow-sm">
                                        <strong class="text-success d-block mb-1"><i class="fa-solid fa-arrows-rotate me-1"></i> Tương Tác Chéo:</strong>
                                        Đăng nhập <code>tuongtaccheo.com</code> &gt; vào mục <strong>Cài đặt / Token</strong> &gt; sao chép chuỗi <strong>Access Token</strong> tài khoản.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <form action="token.php" method="POST" id="formSaveToken">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="save_token">

                        <!-- BƯỚC 1: CHỌN NỀN TẢNG (DROPDOWN TÙY BIẾN CAO CẤP FINTECH) -->
                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark mb-2">
                                1. Chọn nền tảng tài khoản: <span class="text-danger">*</span>
                            </label>
                            
                            <!-- Input ẩn lưu giá trị nền tảng -->
                            <input type="hidden" name="platform" id="platformSelect" value="golike">

                            <!-- Khung kích hoạt Dropdown nhỏ gọn, tinh tế -->
                            <div class="custom-platform-dropdown-wrapper">
                                <div class="premium-platform-box" id="platformTriggerBtn" onclick="togglePlatformDropdown(event)">
                                    <div class="platform-symbol-badge badge-no-frame" id="platformIconIndicator">
                                        <img src="<?= htmlspecialchars($golikeLogo) ?>" alt="Golike" class="platform-logo-rounded-md">
                                    </div>
                                    <div class="platform-field-inner">
                                        <span class="platform-field-label">Nền tảng hệ thống</span>
                                        <div class="platform-selected-name" id="platformSelectedName">Golike</div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="platform-tag-pill" id="platformTagPill">JWT Bearer</span>
                                        <i class="fa-solid fa-chevron-down dropdown-arrow-icon" id="dropdownArrowIcon"></i>
                                    </div>
                                </div>

                                <!-- Menu Dropdown tùy biến tuyệt đẹp -->
                                <div class="custom-platform-dropdown-menu" id="platformDropdownMenu">
                                    <div class="dropdown-platform-item active" data-plat="golike" onclick="choosePlatform('golike', 'Golike', 'JWT Bearer', 'golike')">
                                        <div class="dropdown-platform-item-left">
                                            <div class="platform-item-icon icon-no-frame platform-avatar-golike" style="overflow: hidden; padding: 0;">
                                                <img src="<?= htmlspecialchars($golikeLogo) ?>" alt="Golike" class="platform-logo-rounded-md">
                                            </div>
                                            <div>
                                                <div class="dropdown-platform-item-title">Golike</div>
                                                <div class="dropdown-platform-item-sub">JWT Bearer Token</div>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fa-solid fa-check platform-item-check"></i>
                                        </div>
                                    </div>

                                    <div class="dropdown-platform-item" data-plat="tds" onclick="choosePlatform('tds', 'Trao Đổi Sub', 'Access Token', 'fa-solid fa-bolt text-info')">
                                        <div class="dropdown-platform-item-left">
                                            <div class="platform-item-icon platform-avatar-tds">
                                                <i class="fa-solid fa-bolt"></i>
                                            </div>
                                            <div>
                                                <div class="dropdown-platform-item-title">Trao Đổi Sub</div>
                                                <div class="dropdown-platform-item-sub">Access Token tài khoản</div>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fa-solid fa-check platform-item-check"></i>
                                        </div>
                                    </div>

                                    <div class="dropdown-platform-item" data-plat="ttc" onclick="choosePlatform('ttc', 'Tương Tác Chéo', 'Access Token', 'fa-solid fa-arrows-rotate text-success')">
                                        <div class="dropdown-platform-item-left">
                                            <div class="platform-item-icon platform-avatar-ttc">
                                                <i class="fa-solid fa-arrows-rotate"></i>
                                            </div>
                                            <div>
                                                <div class="dropdown-platform-item-title">Tương Tác Chéo</div>
                                                <div class="dropdown-platform-item-sub">Access Token tài khoản</div>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fa-solid fa-check platform-item-check"></i>
                                        </div>
                                    </div>

                                    <div class="dropdown-platform-item" data-plat="other" onclick="choosePlatform('other', 'Nền tảng khác (JWT / Access Token)', 'Tùy biến', 'fa-solid fa-globe text-secondary')">
                                        <div class="dropdown-platform-item-left">
                                            <div class="platform-item-icon platform-avatar-other">
                                                <i class="fa-solid fa-globe"></i>
                                            </div>
                                            <div>
                                                <div class="dropdown-platform-item-title">Nền tảng khác</div>
                                                <div class="dropdown-platform-item-sub">Chuỗi JWT hoặc Access Token</div>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fa-solid fa-check platform-item-check"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- BƯỚC 2: NHẬP MÃ TOKEN (FINTECH STYLE THEO DEPOSIT.PHP) -->
                        <div class="mb-4">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                <label for="token_input" class="form-label fw-bold text-dark mb-0 flex-grow-1" id="tokenInputLabel">
                                    2. Mã Token Golike: <span class="text-danger">*</span>
                                </label>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fw-bold shadow-sm flex-shrink-0 btn-paste-token" onclick="pasteToken()" title="Dán từ Clipboard">
                                    <i class="fa-solid fa-paste me-1"></i> <span class="d-none d-sm-inline">Dán từ Clipboard</span><span class="d-inline d-sm-none">Dán nhanh</span>
                                </button>
                            </div>
                            <textarea class="token-textarea-field" 
                                      id="token_input" 
                                      name="token" 
                                      rows="3" 
                                      placeholder="Dán token tại đây (ví dụ: Bearer eyJ0eXAiOiJKV1QiLC... hoặc eyJ0eXAiOi...)" 
                                      required></textarea>
                        </div>

                        <div class="mt-4 pt-1">
                            <button type="submit" class="btn-action-save">
                                <i class="fa-solid fa-floppy-disk me-2"></i> Lưu & Đồng Bộ Ngay
                            </button>
                        </div>
                    </form>
                </div>

                <!-- CARD 2: DANH SÁCH TÀI KHOẢN ĐÃ LIÊN KẾT -->
                <div class="deposit-card">
                    <div class="card-header-title">
                        <div class="card-title-text">
                            <i class="fa-solid fa-list-check text-success"></i>
                            <span>Danh Sách Tài Khoản Đã Liên Kết</span>
                        </div>
                        <div class="history-count-badge">
                            <span class="count-badge-icon">
                                <i class="fa-solid fa-users"></i>
                            </span>
                            <span class="count-badge-label">Tổng tài khoản:</span>
                            <span class="count-badge-number"><?= number_format($totalAccounts) ?></span>
                        </div>
                    </div>

                <?php if (empty($accounts)): ?>
                <div class="text-center py-5">
                    <div class="mb-3 text-muted" style="font-size: 3rem;">
                        <i class="fa-solid fa-layer-group"></i>
                    </div>
                    <h5 class="fw-bold text-dark">Chưa có tài khoản nào trong danh sách</h5>
                    <p class="text-muted small mx-auto" style="max-width: 480px;">
                        Hãy chọn nền tảng (Golike, TDS, TTC...) và dán mã Token vào khung phía trên để hệ thống tự động kiểm tra số dư và lưu tài khoản.
                    </p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="token-table">
                        <thead>
                            <tr>
                                <th><i class="fa-solid fa-circle-user text-primary me-1"></i> Tài khoản</th>
                                <th><i class="fa-solid fa-layer-group text-primary me-1"></i> Nền tảng</th>
                                <th><i class="fa-solid fa-at text-primary me-1"></i> Tên Người Dùng</th>
                                <th><i class="fa-solid fa-coins text-warning me-1"></i> Số Dư Coin</th>
                                <th><i class="fa-solid fa-circle-check text-success me-1"></i> Trạng Thái</th>
                                <th><i class="fa-regular fa-clock text-secondary me-1"></i> Thời Gian</th>
                                <th class="text-end"><i class="fa-solid fa-sliders text-secondary me-1"></i> Thao Tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accounts as $acc): 
                                $isExpired = ($acc['status'] === 'Expired');
                                $plat = strtolower($acc['platform'] ?? 'golike');
                                $accId = !empty($acc['account_id']) ? $acc['account_id'] : (!empty($acc['golike_id']) ? $acc['golike_id'] : $acc['id']);
                                $timeStr = !empty($acc['last_checked_at']) ? date('H:i d/m/Y', strtotime($acc['last_checked_at'])) : 'Vừa xong';
                            ?>
                            <tr id="row-account-<?= $acc['id'] ?>">
                                <!-- 1. Tài khoản (Avatar vuông bo góc xanh + Icon User, Tên ở trên, Vân tay + ID ở dưới) -->
                                <td>
                                    <div class="d-inline-flex align-items-center gap-2" style="white-space: nowrap;">
                                        <div class="account-avatar-user">
                                            <i class="fa-solid fa-user"></i>
                                        </div>
                                        <div class="d-inline-flex flex-column justify-content-center text-start" style="line-height: 1.25; white-space: nowrap;">
                                            <span class="fw-bold text-dark" style="font-size: 0.95rem;"><?= htmlspecialchars($acc['name']) ?></span>
                                            <span class="text-secondary d-flex align-items-center gap-1" style="font-size: 0.8rem; font-weight: 500; color: #64748b;">
                                                <i class="fa-solid fa-fingerprint" style="color: #94a3b8; font-size: 0.85rem;"></i>
                                                <span>ID: <?= htmlspecialchars($accId) ?></span>
                                            </span>
                                        </div>
                                    </div>
                                </td>

                                <!-- 2. Nền tảng (Pill badge bo tròn với ảnh Golike) -->
                                <td>
                                    <?php if ($plat === 'golike'): ?>
                                        <span class="platform-pill-badge platform-pill-golike">
                                            <img src="<?= htmlspecialchars($golikeLogo) ?>" alt="Golike" class="platform-logo-rounded">
                                            <span>Golike</span>
                                        </span>
                                    <?php elseif ($plat === 'tds'): ?>
                                        <span class="platform-pill-badge platform-pill-tds">
                                            <i class="fa-solid fa-bolt"></i> <span>TDS</span>
                                        </span>
                                    <?php elseif ($plat === 'ttc'): ?>
                                        <span class="platform-pill-badge platform-pill-ttc">
                                            <i class="fa-solid fa-arrows-rotate"></i> <span>TTC</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="platform-pill-badge platform-pill-other">
                                            <i class="fa-solid fa-layer-group"></i> <span><?= htmlspecialchars(strtoupper($plat)) ?></span>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- 3. Tên Người Dùng (username) -->
                                <td>
                                    <span class="font-monospace text-primary fw-semibold" style="font-size: 0.88rem; white-space: nowrap;">
                                        <i class="fa-solid fa-at me-1 text-secondary" style="font-size: 0.82rem;"></i><?= htmlspecialchars($acc['username']) ?>
                                    </span>
                                </td>

                                <!-- 4. Số Dư Coin -->
                                <td>
                                    <span class="coin-badge" id="coin-badge-<?= $acc['id'] ?>" style="white-space: nowrap;">
                                        <i class="fa-solid fa-coins"></i> <?= number_format($acc['coin'], 0, ',', '.') ?> xu
                                    </span>
                                </td>

                                <!-- 5. Trạng Thái -->
                                <td>
                                    <?php if ($isExpired): ?>
                                        <span class="status-pill expired" id="status-pill-<?= $acc['id'] ?>" style="white-space: nowrap;">
                                            <span class="status-dot"></span> Hết hạn
                                        </span>
                                    <?php else: ?>
                                        <span class="status-pill active" id="status-pill-<?= $acc['id'] ?>" style="white-space: nowrap;">
                                            <span class="status-dot"></span> Hoạt động
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- 6. Thời Gian -->
                                <td>
                                    <span class="small text-secondary fw-medium" id="checked-time-<?= $acc['id'] ?>" style="white-space: nowrap;">
                                        <i class="fa-regular fa-clock me-1 text-muted"></i><?= $timeStr ?>
                                    </span>
                                </td>

                                <!-- 7. Thao Tác -->
                                <td class="text-end">
                                    <div class="d-inline-flex align-items-center gap-1" style="white-space: nowrap;">
                                        <!-- Nút làm mới số dư -->
                                        <button type="button" class="btn btn-light btn-sm border text-primary" onclick="refreshBalance(<?= $acc['id'] ?>, this)" title="Làm mới số dư thời gian thực">
                                            <i class="fa-solid fa-rotate"></i>
                                        </button>
                                        <!-- Nút sao chép token -->
                                        <button type="button" class="btn btn-light btn-sm border text-secondary" onclick="copyText('<?= htmlspecialchars($acc['token']) ?>', this)" title="Sao chép Token">
                                            <i class="fa-solid fa-copy"></i>
                                        </button>
                                        <!-- Nút xóa -->
                                        <form action="token.php" method="POST" class="d-inline" onsubmit="return confirmDeleteAccount(this);">
                                            <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                                            <input type="hidden" name="action" value="delete_token">
                                            <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                            <button type="submit" class="btn btn-light btn-sm border text-danger" title="Xóa tài khoản">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </form>
                                    </div>
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
                    btnElement.innerHTML = '<i class="fa-solid fa-check text-success"></i>';
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

        // 4.1 Điều khiển mở/đóng Custom Dropdown
        function togglePlatformDropdown(e) {
            if (e) e.stopPropagation();
            const menu = document.getElementById('platformDropdownMenu');
            const trigger = document.getElementById('platformTriggerBtn');
            if (menu && trigger) {
                const isOpen = menu.classList.contains('show');
                if (isOpen) {
                    menu.classList.remove('show');
                    trigger.classList.remove('open');
                } else {
                    menu.classList.add('show');
                    trigger.classList.add('open');
                }
            }
        }

        // Khởi tạo URL Logo các nền tảng từ Server
        const GOLIKE_LOGO_URL = <?= json_encode($golikeLogo) ?>;

        // 4.2 Chọn nền tảng từ Menu Dropdown
        function choosePlatform(plat, fullName, tagText, iconClass) {
            const select = document.getElementById('platformSelect');
            if (select) select.value = plat;

            const nameEl = document.getElementById('platformSelectedName');
            if (nameEl) nameEl.textContent = fullName;

            const tagEl = document.getElementById('platformTagPill');
            if (tagEl) tagEl.textContent = tagText;

            const iconEl = document.getElementById('platformIconIndicator');
            if (iconEl) {
                if (plat === 'golike') {
                    iconEl.classList.add('badge-no-frame');
                    iconEl.innerHTML = `<img src="${GOLIKE_LOGO_URL}" alt="Golike" class="platform-logo-rounded-md">`;
                } else {
                    iconEl.classList.remove('badge-no-frame');
                    if (iconClass && iconClass.startsWith('fa-')) {
                        iconEl.innerHTML = `<i class="${iconClass}"></i>`;
                    } else {
                        iconEl.innerHTML = iconClass;
                    }
                }
            }

            // Cập nhật trạng thái active trong dropdown
            document.querySelectorAll('.dropdown-platform-item').forEach(item => {
                if (item.getAttribute('data-plat') === plat) {
                    item.classList.add('active');
                } else {
                    item.classList.remove('active');
                }
            });

            // Đóng menu
            const menu = document.getElementById('platformDropdownMenu');
            const trigger = document.getElementById('platformTriggerBtn');
            if (menu) menu.classList.remove('show');
            if (trigger) trigger.classList.remove('open');

            // Cập nhật các label/placeholder tương ứng
            onPlatformChange();
        }

        // Đóng dropdown khi click ra ngoài
        document.addEventListener('click', function(e) {
            const menu = document.getElementById('platformDropdownMenu');
            const trigger = document.getElementById('platformTriggerBtn');
            if (menu && trigger && !trigger.contains(e.target) && !menu.contains(e.target)) {
                menu.classList.remove('show');
                trigger.classList.remove('open');
            }
        });

        // 4.3 Thay đổi thông tin khi chọn nền tảng qua Dropdown
        function onPlatformChange() {
            const select = document.getElementById('platformSelect');
            const platform = select ? select.value : 'golike';
            const label = document.getElementById('tokenInputLabel');
            const tagPill = document.getElementById('platformTagPill');
            const input = document.getElementById('token_input');
            const iconIndicator = document.getElementById('platformIconIndicator');
            const prevAvatar = document.getElementById('prevAvatar');

            const platformIcons = {
                'golike': `<img src="${GOLIKE_LOGO_URL}" alt="Golike" class="platform-logo-rounded-md">`,
                'tds': '<i class="fa-solid fa-bolt text-info"></i>',
                'ttc': '<i class="fa-solid fa-arrows-rotate text-success"></i>',
                'other': '<i class="fa-solid fa-globe text-secondary"></i>'
            };

            const platformAvatars = {
                'golike': 'platform-avatar-golike',
                'tds': 'platform-avatar-tds',
                'ttc': 'platform-avatar-ttc',
                'other': 'platform-avatar-other'
            };

            if (iconIndicator) {
                if (platform === 'golike') {
                    iconIndicator.classList.add('badge-no-frame');
                } else {
                    iconIndicator.classList.remove('badge-no-frame');
                }
                iconIndicator.innerHTML = platformIcons[platform] || '<i class="fa-solid fa-globe text-secondary"></i>';
            }

            if (prevAvatar) {
                if (platform === 'golike') {
                    prevAvatar.classList.add('icon-no-frame');
                } else {
                    prevAvatar.classList.remove('icon-no-frame');
                }
                prevAvatar.innerHTML = platformIcons[platform] || '<i class="fa-solid fa-globe text-secondary"></i>';
                prevAvatar.className = 'golike-avatar ' + (platformAvatars[platform] || 'platform-avatar-other') + (platform === 'golike' ? ' icon-no-frame' : '');
            }

            if (platform === 'golike') {
                if (label) label.innerHTML = '2. Mã Token Golike: <span class="text-danger">*</span>';
                if (tagPill) tagPill.textContent = 'JWT Bearer';
                if (input) input.placeholder = 'Dán token tại đây (ví dụ: Bearer eyJ0eXAiOiJKV1QiLC... hoặc eyJ0eXAiOi...)';
            } else if (platform === 'tds') {
                if (label) label.innerHTML = '2. Mã Token TDS: <span class="text-danger">*</span>';
                if (tagPill) tagPill.textContent = 'Access Token';
                if (input) input.placeholder = 'Dán mã Access Token lấy từ traodoisub.com tại đây...';
            } else if (platform === 'ttc') {
                if (label) label.innerHTML = '2. Mã Token TTC: <span class="text-danger">*</span>';
                if (tagPill) tagPill.textContent = 'Access Token';
                if (input) input.placeholder = 'Dán mã Access Token lấy từ tuongtaccheo.com tại đây...';
            } else {
                if (label) label.innerHTML = '2. Mã Token: <span class="text-danger">*</span>';
                if (tagPill) tagPill.textContent = 'Tùy biến';
                if (input) input.placeholder = 'Dán chuỗi JWT token hoặc access token của bạn tại đây...';
            }

            // Ẩn preview box khi đổi nền tảng
            const previewBox = document.getElementById('tokenPreviewBox');
            if (previewBox) previewBox.classList.add('d-none');
        }

        // 5. Dán token từ Clipboard
        function pasteToken() {
            navigator.clipboard.readText().then(text => {
                if (text) {
                    const input = document.getElementById('token_input');
                    if (input) {
                        input.value = text.trim();
                    }
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'info',
                        title: 'Đã dán token từ Clipboard!',
                        showConfirmButton: false,
                        timer: 1500
                    });
                }
            }).catch(() => {
                prompt('Hãy dán chuỗi token vào đây:', '');
            });
        }

        // 6. Kiểm tra Token Live trước khi lưu
        function checkTokenLive() {
            const tokenInput = document.getElementById('token_input');
            const token = tokenInput ? tokenInput.value.trim() : '';
            const platformSelect = document.getElementById('platformSelect');
            const platform = platformSelect ? platformSelect.value : 'golike';
            const btn = document.getElementById('btnCheckToken');
            const previewBox = document.getElementById('tokenPreviewBox');

            if (!token) {
                Swal.fire('Thiếu thông tin', 'Vui lòng nhập hoặc dán mã Token để kiểm tra.', 'warning');
                return;
            }

            const origHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Đang kiểm tra...';
            btn.disabled = true;

            const fd = new FormData();
            fd.append('ajax_action', 'check_token');
            fd.append('platform', platform);
            fd.append('token', token);

            fetch('token/golike.php', {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                btn.innerHTML = origHtml;
                btn.disabled = false;

                if (data.success && data.data) {
                    const acc = data.data;
                    document.getElementById('prevName').textContent = acc.name || '---';
                    document.getElementById('prevUsername').innerHTML = '<i class="fa-solid fa-at text-muted me-1"></i>' + (acc.username || '---');
                    document.getElementById('prevId').innerHTML = '<i class="fa-solid fa-id-badge me-1 text-primary"></i>ID: ' + (acc.id || '---');
                    document.getElementById('prevCoin').innerHTML = '<i class="fa-solid fa-coins me-1"></i>' + (acc.coin_formatted || '0 xu');
                    
                    const platformIcons = {
                        'golike': `<img src="${GOLIKE_LOGO_URL}" alt="Golike" class="platform-logo-rounded-md">`,
                        'tds': '<i class="fa-solid fa-bolt text-info"></i>',
                        'ttc': '<i class="fa-solid fa-arrows-rotate text-success"></i>',
                        'other': '<i class="fa-solid fa-globe text-secondary"></i>'
                    };
                    const prevAvatar = document.getElementById('prevAvatar');
                    if (prevAvatar) {
                        if (platform === 'golike') {
                            prevAvatar.classList.add('icon-no-frame');
                        } else {
                            prevAvatar.classList.remove('icon-no-frame');
                        }
                        prevAvatar.innerHTML = platformIcons[platform] || '<i class="fa-solid fa-globe text-secondary"></i>';
                        prevAvatar.className = 'golike-avatar platform-avatar-' + platform + (platform === 'golike' ? ' icon-no-frame' : '');
                    }
                    document.getElementById('prevPlatformBadge').textContent = platform.toUpperCase();

                    previewBox.classList.remove('d-none');
                    previewBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: `Xác thực ${platform.toUpperCase()} thành công: ${acc.name} (${acc.coin_formatted})`,
                        showConfirmButton: false,
                        timer: 2500
                    });
                } else {
                    previewBox.classList.add('d-none');
                    Swal.fire('Lỗi Token', data.message || 'Mã Token không chính xác hoặc đã hết hạn trên nền tảng đã chọn.', 'error');
                }
            })
            .catch(err => {
                btn.innerHTML = origHtml;
                btn.disabled = false;
                Swal.fire('Lỗi kết nối', 'Không thể kết nối API kiểm tra: ' + err.message, 'error');
            });
        }

        // 7. Làm mới số dư tài khoản
        function refreshBalance(id, btnElement) {
            const origHtml = btnElement.innerHTML;
            btnElement.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
            btnElement.disabled = true;

            const fd = new FormData();
            fd.append('ajax_action', 'refresh_account');
            fd.append('record_id', id);

            fetch('token/golike.php', {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                btnElement.innerHTML = origHtml;
                btnElement.disabled = false;

                if (data.success) {
                    const coinBadge = document.getElementById('coin-badge-' + id);
                    if (coinBadge) {
                        coinBadge.innerHTML = '<i class="fa-solid fa-coins me-1"></i>' + data.coin_formatted;
                    }
                    const statusPill = document.getElementById('status-pill-' + id);
                    if (statusPill) {
                        statusPill.className = 'status-pill active';
                        statusPill.innerHTML = '<span class="status-dot"></span> Hoạt động';
                    }
                    const timeEl = document.getElementById('checked-time-' + id);
                    if (timeEl) {
                        const now = new Date();
                        const timeStr = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0') + ' ' + String(now.getDate()).padStart(2, '0') + '/' + String(now.getMonth() + 1).padStart(2, '0') + '/' + now.getFullYear();
                        timeEl.innerHTML = '<i class="fa-regular fa-clock me-1 text-muted"></i>' + timeStr;
                    }
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: data.message,
                        showConfirmButton: false,
                        timer: 2000
                    });
                } else {
                    const statusPill = document.getElementById('status-pill-' + id);
                    if (statusPill && data.status === 'Expired') {
                        statusPill.className = 'status-pill expired';
                        statusPill.innerHTML = '<span class="status-dot"></span> Hết hạn';
                    }
                    Swal.fire('Cảnh báo', data.message, 'warning');
                }
            })
            .catch(err => {
                btnElement.innerHTML = origHtml;
                btnElement.disabled = false;
                Swal.fire('Lỗi kết nối', 'Lỗi khi làm mới số dư: ' + err.message, 'error');
            });
        }

        // 8. Xác nhận xóa tài khoản
        function confirmDeleteAccount(form) {
            event.preventDefault();
            Swal.fire({
                title: 'Xóa tài khoản này?',
                text: 'Bạn có chắc chắn muốn xóa tài khoản này khỏi danh sách quản lý token?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Đồng ý xóa',
                cancelButtonText: 'Hủy bỏ'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
            return false;
        }

        // 9. Xác nhận đăng xuất
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
