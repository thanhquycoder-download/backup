<?php
/**
 * ==========================================================
 * TRANG HỖ TRỢ KHÁCH HÀNG & QUẢN LÝ TICKET - THANHQUYTECH
 * File: support.php
 * Website: ThanhQuyTech
 * Cơ chế: Hệ thống Ticket đa luồng, trao đổi phản hồi 2 chiều giữa User và Admin
 * ==========================================================
 */

require_once __DIR__ . '/config/config.php';

require_login();

// 1. Lấy thông tin tài khoản hiện tại
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

// 2. Tự động kiểm tra và khởi tạo bảng support_tickets & support_messages nếu chưa có
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `support_tickets` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_uuid` CHAR(36) NOT NULL,
            `ticket_code` VARCHAR(50) NOT NULL UNIQUE,
            `subject` VARCHAR(255) NOT NULL,
            `category` ENUM('Billing', 'LicenseKey', 'CloudServer', 'GolikeTool', 'Account', 'Other') NOT NULL DEFAULT 'Other',
            `priority` ENUM('Low', 'Medium', 'High', 'Urgent') NOT NULL DEFAULT 'Medium',
            `status` ENUM('Pending', 'In Progress', 'Answered', 'Closed') NOT NULL DEFAULT 'Pending',
            `order_code` VARCHAR(50) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_st_user_uuid` (`user_uuid`),
            INDEX `idx_st_ticket_code` (`ticket_code`),
            INDEX `idx_st_status` (`status`),
            INDEX `idx_st_category` (`category`),
            INDEX `idx_st_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `support_messages` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `ticket_id` BIGINT UNSIGNED NOT NULL,
            `sender_uuid` CHAR(36) NOT NULL,
            `sender_role` ENUM('Member', 'Admin', 'Support') NOT NULL DEFAULT 'Member',
            `message` TEXT NOT NULL,
            `attachment` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_sm_ticket_id` (`ticket_id`),
            INDEX `idx_sm_sender_uuid` (`sender_uuid`),
            INDEX `idx_sm_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {
    // Bỏ qua nếu bảng đã chuẩn
}

// 3. XỬ LÝ CÁC HÀNH ĐỘNG GỬI YÊU CẦU & PHẢN HỒI (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Mã bảo mật CSRF không hợp lệ hoặc đã hết hạn.', 'Lỗi xác thực');
        header("Location: support.php");
        exit;
    }

    $action = $_POST['action'] ?? '';

    // A. TẠO TICKET HỖ TRỢ MỚI
    if ($action === 'create_ticket') {
        $subject   = trim($_POST['subject'] ?? '');
        $category  = trim($_POST['category'] ?? 'Other');
        $priority  = trim($_POST['priority'] ?? 'Medium');
        $orderCode = trim($_POST['order_code'] ?? '');
        $message   = trim($_POST['message'] ?? '');

        $allowedCategories = ['Billing', 'LicenseKey', 'CloudServer', 'GolikeTool', 'Account', 'Other'];
        $allowedPriorities = ['Low', 'Medium', 'High', 'Urgent'];

        if (!in_array($category, $allowedCategories)) $category = 'Other';
        if (!in_array($priority, $allowedPriorities)) $priority = 'Medium';

        if (empty($subject) || empty($message)) {
            set_flash('error', 'Vui lòng điền đầy đủ tiêu đề và nội dung chi tiết cần hỗ trợ.', 'Thiếu thông tin');
            header("Location: support.php");
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Sinh mã ticket duy nhất dạng TK-2609-XXXX
            $ticketCode = 'TK-' . date('ym') . '-' . random_int(1000, 9999);
            
            $stmtInsert = $pdo->prepare("
                INSERT INTO support_tickets (user_uuid, ticket_code, subject, category, priority, status, order_code, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'Pending', ?, NOW(), NOW())
            ");
            $stmtInsert->execute([
                $currentUser['uuid'],
                $ticketCode,
                $subject,
                $category,
                $priority,
                !empty($orderCode) ? $orderCode : null
            ]);

            $ticketId = $pdo->lastInsertId();

            // Ghi tin nhắn đầu tiên của ticket
            $stmtMsg = $pdo->prepare("
                INSERT INTO support_messages (ticket_id, sender_uuid, sender_role, message, created_at)
                VALUES (?, ?, 'Member', ?, NOW())
            ");
            $stmtMsg->execute([$ticketId, $currentUser['uuid'], $message]);

            $pdo->commit();

            set_flash('success', "Yêu cầu hỗ trợ <strong>#$ticketCode</strong> đã được tiếp nhận. Đội ngũ kỹ thuật sẽ hỗ trợ bạn trong ít phút!", 'Gửi thành công');
            header("Location: support.php?view=" . $ticketId);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash('error', 'Lỗi gửi yêu cầu hỗ trợ: ' . $e->getMessage(), 'Lỗi hệ thống');
            header("Location: support.php");
            exit;
        }
    }

    // B. GỬI PHẢN HỒI (REPLY) VÀO TICKET
    if ($action === 'reply_ticket') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $replyMsg = trim($_POST['message'] ?? '');

        if ($ticketId <= 0 || empty($replyMsg)) {
            set_flash('error', 'Nội dung phản hồi không được để trống.', 'Lỗi phản hồi');
            header("Location: support.php" . ($ticketId > 0 ? "?view=$ticketId" : ""));
            exit;
        }

        // Kiểm tra quyền đối với ticket
        $stmtCheck = $pdo->prepare("SELECT id, user_uuid, status FROM support_tickets WHERE id = ? LIMIT 1");
        $stmtCheck->execute([$ticketId]);
        $ticket = $stmtCheck->fetch();

        if (!$ticket || (!$isAdmin && $ticket['user_uuid'] !== $currentUser['uuid'])) {
            set_flash('error', 'Bạn không có quyền phản hồi yêu cầu hỗ trợ này.', 'Không có quyền');
            header("Location: support.php");
            exit;
        }

        if ($ticket['status'] === 'Closed' && !$isAdmin) {
            set_flash('warning', 'Yêu cầu hỗ trợ này đã được đóng. Vui lòng tạo ticket mới nếu cần hỗ trợ thêm.', 'Phiếu đã đóng');
            header("Location: support.php?view=$ticketId");
            exit;
        }

        try {
            $senderRole = $isAdmin ? 'Admin' : 'Member';
            
            $stmtReply = $pdo->prepare("
                INSERT INTO support_messages (ticket_id, sender_uuid, sender_role, message, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmtReply->execute([$ticketId, $currentUser['uuid'], $senderRole, $replyMsg]);

            // Cập nhật trạng thái ticket
            $newStatus = $isAdmin ? 'Answered' : 'Pending';
            $stmtUp = $pdo->prepare("
                UPDATE support_tickets 
                SET status = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmtUp->execute([$newStatus, $ticketId]);

            set_flash('success', 'Đã gửi câu trả lời phản hồi thành công!', 'Phản hồi thành công');
            header("Location: support.php?view=$ticketId");
            exit;
        } catch (Exception $e) {
            set_flash('error', 'Lỗi lưu tin nhắn: ' . $e->getMessage(), 'Lỗi hệ thống');
            header("Location: support.php?view=$ticketId");
            exit;
        }
    }

    // C. ĐÓNG TICKET HỖ TRỢ
    if ($action === 'close_ticket') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);

        $stmtCheck = $pdo->prepare("SELECT id, user_uuid FROM support_tickets WHERE id = ? LIMIT 1");
        $stmtCheck->execute([$ticketId]);
        $ticket = $stmtCheck->fetch();

        if (!$ticket || (!$isAdmin && $ticket['user_uuid'] !== $currentUser['uuid'])) {
            set_flash('error', 'Không tìm thấy yêu cầu cần đóng.', 'Lỗi thao tác');
            header("Location: support.php");
            exit;
        }

        $stmtClose = $pdo->prepare("UPDATE support_tickets SET status = 'Closed', updated_at = NOW() WHERE id = ?");
        $stmtClose->execute([$ticketId]);

        set_flash('info', 'Đã đóng yêu cầu hỗ trợ. Cảm ơn bạn đã tin tưởng ThanhQuyTech!', 'Đã đóng ticket');
        header("Location: support.php?view=$ticketId");
        exit;
    }

    // D. ADMIN CẬP NHẬT TRẠNG THÁI
    if ($action === 'admin_change_status' && $isAdmin) {
        $ticketId  = (int)($_POST['ticket_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? '');
        $allowed   = ['Pending', 'In Progress', 'Answered', 'Closed'];

        if (in_array($newStatus, $allowed) && $ticketId > 0) {
            $stmtStat = $pdo->prepare("UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmtStat->execute([$newStatus, $ticketId]);
            set_flash('success', "Đã chuyển trạng thái ticket sang: $newStatus", 'Cập nhật thành công');
        }
        header("Location: support.php?view=$ticketId");
        exit;
    }
}

// 4. LẤY DỮ LIỆU DANH SÁCH TICKET & THỐNG KÊ
$filterStatus = trim($_GET['status'] ?? 'all');
$whereClause = "";
$params = [];

if (!$isAdmin) {
    $whereClause = "WHERE st.user_uuid = ?";
    $params[] = $currentUser['uuid'];
    if ($filterStatus !== 'all') {
        $whereClause .= " AND st.status = ?";
        $params[] = $filterStatus;
    }
} else {
    if ($filterStatus !== 'all') {
        $whereClause = "WHERE st.status = ?";
        $params[] = $filterStatus;
    }
}

// Thống kê tổng số ticket
if (!$isAdmin) {
    $stmtCount = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END), 0) as pending,
            COALESCE(SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END), 0) as in_progress,
            COALESCE(SUM(CASE WHEN status = 'Answered' THEN 1 ELSE 0 END), 0) as answered,
            COALESCE(SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END), 0) as closed
        FROM support_tickets 
        WHERE user_uuid = ?
    ");
    $stmtCount->execute([$currentUser['uuid']]);
} else {
    $stmtCount = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END), 0) as pending,
            COALESCE(SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END), 0) as in_progress,
            COALESCE(SUM(CASE WHEN status = 'Answered' THEN 1 ELSE 0 END), 0) as answered,
            COALESCE(SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END), 0) as closed
        FROM support_tickets
    ");
}
$rawStats = $stmtCount->fetch() ?: [];
$stats = [
    'total'       => (int)($rawStats['total'] ?? 0),
    'pending'     => (int)($rawStats['pending'] ?? 0),
    'in_progress' => (int)($rawStats['in_progress'] ?? 0),
    'answered'    => (int)($rawStats['answered'] ?? 0),
    'closed'      => (int)($rawStats['closed'] ?? 0)
];

// Danh sách các tickets
$sqlList = "
    SELECT 
        st.*,
        u.name as user_name,
        u.username as user_username,
        u.avatar as user_avatar,
        (SELECT COUNT(*) FROM support_messages sm WHERE sm.ticket_id = st.id) as msg_count
    FROM support_tickets st
    JOIN users u ON st.user_uuid = u.uuid
    $whereClause
    ORDER BY 
        CASE 
            WHEN st.status = 'Pending' THEN 1
            WHEN st.status = 'In Progress' THEN 2
            WHEN st.status = 'Answered' THEN 3
            ELSE 4
        END,
        st.updated_at DESC
";
$stmtList = $pdo->prepare($sqlList);
$stmtList->execute($params);
$ticketList = $stmtList->fetchAll();

// Chi tiết ticket đang xem (nếu có tham số ?view=...)
$viewTicketId = (int)($_GET['view'] ?? 0);
$activeTicket = null;
$activeMessages = [];

if ($viewTicketId > 0) {
    $stmtActive = $pdo->prepare("
        SELECT st.*, u.name as user_name, u.username as user_username, u.avatar as user_avatar, u.email as user_email
        FROM support_tickets st
        JOIN users u ON st.user_uuid = u.uuid
        WHERE st.id = ? " . (!$isAdmin ? "AND st.user_uuid = ?" : "") . "
        LIMIT 1
    ");
    $activeParams = [$viewTicketId];
    if (!$isAdmin) $activeParams[] = $currentUser['uuid'];
    $stmtActive->execute($activeParams);
    $activeTicket = $stmtActive->fetch();

    if ($activeTicket) {
        $stmtMsgs = $pdo->prepare("
            SELECT sm.*, u.name as sender_name, u.username as sender_username, u.avatar as sender_avatar, u.role as sender_user_role
            FROM support_messages sm
            JOIN users u ON sm.sender_uuid = u.uuid
            WHERE sm.ticket_id = ?
            ORDER BY sm.created_at ASC
        ");
        $stmtMsgs->execute([$viewTicketId]);
        $activeMessages = $stmtMsgs->fetchAll();
    }
}

// Helpers cho UI
function getCategoryBadge(string $cat): string {
    switch ($cat) {
        case 'Billing': return '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fa-solid fa-credit-card me-1"></i> Nạp tiền / Thanh toán</span>';
        case 'LicenseKey': return '<span class="badge bg-warning-subtle text-warning border border-warning-subtle"><i class="fa-solid fa-key me-1"></i> Bản quyền Key VIP</span>';
        case 'CloudServer': return '<span class="badge bg-info-subtle text-info border border-info-subtle"><i class="fa-solid fa-cloud me-1"></i> Thuê Cloud VPS</span>';
        case 'GolikeTool': return '<span class="badge bg-primary-subtle text-primary border border-primary-subtle"><i class="fa-solid fa-robot me-1"></i> Tool Golike</span>';
        case 'Account': return '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"><i class="fa-solid fa-user me-1"></i> Tài khoản</span>';
        default: return '<span class="badge bg-light text-dark border"><i class="fa-solid fa-circle-question me-1"></i> Khác</span>';
    }
}

function getPriorityBadge(string $pri): string {
    switch ($pri) {
        case 'Urgent': return '<span class="badge bg-danger text-white"><i class="fa-solid fa-bolt me-1"></i> Khẩn cấp</span>';
        case 'High': return '<span class="badge bg-warning text-dark"><i class="fa-solid fa-triangle-exclamation me-1"></i> Cao</span>';
        case 'Medium': return '<span class="badge bg-primary-subtle text-primary"><i class="fa-solid fa-circle-check me-1"></i> Trung bình</span>';
        default: return '<span class="badge bg-secondary-subtle text-secondary">Thấp</span>';
    }
}

function getStatusBadge(string $status): string {
    switch ($status) {
        case 'Pending': return '<span class="badge bg-warning text-dark"><i class="fa-solid fa-hourglass-half me-1"></i> Chờ phản hồi</span>';
        case 'In Progress': return '<span class="badge bg-info text-dark"><i class="fa-solid fa-spinner fa-spin me-1"></i> Đang xử lý</span>';
        case 'Answered': return '<span class="badge bg-success text-white"><i class="fa-solid fa-circle-check me-1"></i> Đã phản hồi</span>';
        case 'Closed': return '<span class="badge bg-secondary text-white"><i class="fa-solid fa-lock me-1"></i> Đã đóng</span>';
        default: return '<span class="badge bg-light text-dark">Không rõ</span>';
    }
}

$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trung Tâm Hỗ Trợ Kỹ Thuật 24/7 - <?= APP_NAME ?></title>

    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3.3 CSS -->
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
            margin: 0;
            padding: 0;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: var(--radius-md);
            color: #475569;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: var(--transition);
            border: 1px solid transparent;
            cursor: pointer;
            width: 100%;
            background: transparent;
            text-align: left;
        }

        .sidebar-link:hover {
            color: var(--primary);
            background: #f1f5f9;
            border-color: #e2e8f0;
        }

        .sidebar-link.active {
            color: var(--primary);
            background: #eef2ff;
            border-color: #c7d2fe;
            font-weight: 700;
        }

        .sidebar-icon {
            width: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            flex-shrink: 0;
        }

        .sidebar-link.active .sidebar-icon {
            color: var(--primary);
        }

        .sidebar-title { flex-grow: 1; }

        .sidebar-arrow {
            font-size: 0.75rem;
            color: #94a3b8;
            transition: transform 0.25s ease;
        }

        .sidebar-link:not(.collapsed) .sidebar-arrow {
            transform: rotate(180deg);
        }

        .sidebar-submenu {
            list-style: none;
            margin: 4px 0 6px;
            padding: 4px 0 4px 34px;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .submenu-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 7px 12px;
            border-radius: 8px;
            font-size: 0.83rem;
            font-weight: 500;
            color: #64748b;
            text-decoration: none;
            transition: var(--transition);
        }

        .submenu-link:hover {
            color: var(--primary);
            background: #f8fafc;
        }

        .badge-history {
            font-size: 0.65rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 50px;
            background: #f1f5f9;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(4px);
            z-index: 1025;
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        .sidebar-backdrop.active {
            display: block;
            opacity: 1;
        }

        /* ==========================================================
         * 3. KHU VỰC NỘI DUNG CHÍNH (MAIN CONTENT)
         * ========================================================== */
        .app-main {
            margin-left: 260px;
            margin-top: 70px;
            padding: 28px 32px 60px;
            min-height: calc(100vh - 70px);
            background-color: var(--bg-body);
            transition: margin-left 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Hero Banner Hỗ Trợ */
        .support-hero-banner {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 60%, #4338ca 100%);
            border-radius: var(--radius-lg);
            padding: 32px 36px;
            color: #ffffff;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 35px -10px rgba(30, 27, 75, 0.35);
        }

        .support-hero-banner::before {
            content: '';
            position: absolute;
            top: -60px;
            right: -40px;
            width: 260px;
            height: 260px;
            background: radial-gradient(circle, rgba(6, 182, 212, 0.25) 0%, rgba(255, 255, 255, 0) 70%);
            border-radius: 50%;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(99, 102, 241, 0.3);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(165, 180, 252, 0.3);
            padding: 5px 14px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 800;
            color: #e0e7ff;
            margin-bottom: 12px;
        }

        .hero-title {
            font-size: 1.85rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            line-height: 1.25;
            margin-bottom: 8px;
        }

        .hero-subtitle {
            font-size: 0.95rem;
            color: #cbd5e1;
            max-width: 680px;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        /* Thẻ liên hệ nhanh */
        .quick-contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            margin-top: 10px;
        }

        .quick-contact-item {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #ffffff;
            transition: var(--transition);
        }

        .quick-contact-item:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.3);
        }

        .qc-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .qc-info { display: flex; flex-direction: column; }
        .qc-title { font-size: 0.76rem; color: #cbd5e1; font-weight: 600; text-transform: uppercase; }
        .qc-desc { font-size: 0.9rem; font-weight: 800; color: #ffffff; }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            padding: 18px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-card);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 25px -8px rgba(0, 0, 0, 0.08);
        }

        .stat-number {
            font-size: 1.65rem;
            font-weight: 800;
            color: var(--text-heading);
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .stat-icon-wrap {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }

        /* Thẻ Ticket Container */
        .tickets-container {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            overflow: hidden;
        }

        .tickets-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
        }

        .tickets-title {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        .filter-tabs {
            display: inline-flex;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 12px;
            gap: 4px;
            flex-wrap: wrap;
        }

        .filter-tab-item {
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 700;
            color: #64748b;
            text-decoration: none;
            transition: var(--transition);
        }

        .filter-tab-item.active {
            background: #ffffff;
            color: var(--primary);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
        }

        .btn-new-ticket {
            background: var(--gradient-primary);
            color: #ffffff;
            border: none;
            padding: 10px 20px;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);
            transition: var(--transition);
        }

        .btn-new-ticket:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4);
            color: #ffffff;
        }

        /* Bảng Ticket */
        .table-tickets {
            width: 100%;
            margin: 0;
            border-collapse: collapse;
        }

        .table-tickets th {
            background: #f8fafc;
            color: #64748b;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 14px 20px;
            border-bottom: 1px solid var(--card-border);
            white-space: nowrap;
        }

        .table-tickets td {
            padding: 16px 20px;
            border-bottom: 1px solid var(--card-border);
            vertical-align: middle;
            color: var(--text-body);
            font-size: 0.88rem;
        }

        .table-tickets tr:hover td {
            background-color: #f8fafc;
        }

        .ticket-code-link {
            font-family: monospace;
            font-weight: 800;
            color: var(--primary);
            font-size: 0.95rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .ticket-code-link:hover {
            text-decoration: underline;
        }

        .ticket-subject-text {
            font-weight: 700;
            color: var(--text-heading);
            margin-bottom: 4px;
            display: block;
        }

        /* ==========================================================
         * 4. GIAO DIỆN CHAT / CHI TIẾT TICKET (CONVERSATION THREAD)
         * ========================================================== */
        .ticket-chat-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .chat-top-header {
            padding: 20px 24px;
            background: #f8fafc;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
        }

        .chat-thread {
            padding: 24px;
            max-height: 520px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
            background: #fafafa;
        }

        .message-bubble-wrapper {
            display: flex;
            gap: 14px;
            max-width: 82%;
        }

        .message-bubble-wrapper.user-msg {
            align-self: flex-start;
        }

        .message-bubble-wrapper.admin-msg {
            align-self: flex-end;
            flex-direction: row-reverse;
        }

        .msg-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #ffffff;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            flex-shrink: 0;
        }

        .msg-content-box {
            display: flex;
            flex-direction: column;
        }

        .user-msg .msg-content-box { align-items: flex-start; }
        .admin-msg .msg-content-box { align-items: flex-end; }

        .msg-sender-meta {
            font-size: 0.78rem;
            color: #64748b;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .msg-bubble {
            padding: 14px 18px;
            border-radius: 18px;
            font-size: 0.92rem;
            line-height: 1.55;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            white-space: pre-line;
            word-break: break-word;
        }

        .user-msg .msg-bubble {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            color: #1e293b;
            border-bottom-left-radius: 4px;
        }

        .admin-msg .msg-bubble {
            background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
            color: #ffffff;
            border-bottom-right-radius: 4px;
        }

        .chat-input-area {
            padding: 20px 24px;
            background: #ffffff;
            border-top: 1px solid var(--card-border);
        }

        .btn-reply-send {
            background: var(--gradient-primary);
            color: #ffffff;
            border: none;
            padding: 10px 24px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.92rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-reply-send:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);
            color: #ffffff;
        }

        /* ==========================================================
         * 5. RESPONSIVE BREAKPOINTS & FULL-HEIGHT MOBILE DRAWER
         * ========================================================== */
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
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 767.98px) {
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
            .support-hero-banner { padding: 24px 18px; }
            .hero-title { font-size: 1.35rem; }
            .hero-subtitle { font-size: 0.85rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 14px; }
            .stat-number { font-size: 1.3rem; }
            .tickets-header { flex-direction: column; align-items: stretch; }
            .btn-new-ticket { width: 100%; justify-content: center; }
            .message-bubble-wrapper { max-width: 95%; }
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

        function toggleUserPopup(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            var popup = document.getElementById('userProfilePopup');
            if (popup) popup.classList.toggle('active');
        }

        function closeUserPopup() {
            var popup = document.getElementById('userProfilePopup');
            if (popup) popup.classList.remove('active');
        }

        document.addEventListener('click', function(e) {
            var popup = document.getElementById('userProfilePopup');
            var toggle = document.getElementById('userProfileToggle');
            if (popup && popup.classList.contains('active')) {
                if (!popup.contains(e.target) && !toggle.contains(e.target)) {
                    popup.classList.remove('active');
                }
            }
        });
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
                        <a href="referral.php" class="popup-menu-item">
                            <i class="fa-solid fa-share-nodes text-primary me-2"></i> Giới thiệu bạn bè
                        </a>
                        <a href="support.php" class="popup-menu-item">
                            <i class="fa-solid fa-headset text-success me-2"></i> Hỗ trợ kỹ thuật
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
                                <span><i class="fa-solid fa-fire text-danger me-1"></i> Tất cả nhiệm vụ</span>
                            </a>
                        </li>
                        <li>
                            <a href="/jobs/golike/tiktok" class="submenu-link">
                                <span><i class="fa-brands fa-tiktok me-1 text-dark"></i> TikTok</span>
                                <span class="badge-history"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                            </a>
                        </li>
                        <li>
                            <a href="/jobs/golike/facebook" class="submenu-link">
                                <span><i class="fa-brands fa-facebook me-1 text-primary"></i> Facebook</span>
                                <span class="badge-history"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
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
                            <a href="/jobs/golike/shopee" class="submenu-link">
                                <span><i class="fa-solid fa-bag-shopping me-1 text-warning"></i> Shopee</span>
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
                                <span><i class="fa-solid fa-store text-primary me-1"></i> Cửa hàng Account</span>
                            </a>
                        </li>
                        <li>
                            <a href="/products/accounts/facebook" class="submenu-link">
                                <span><i class="fa-brands fa-facebook me-1 text-primary"></i> Facebook</span>
                                <span class="badge-history"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                            </a>
                        </li>
                        <li>
                            <a href="/products/accounts/tiktok" class="submenu-link">
                                <span><i class="fa-brands fa-tiktok me-1 text-dark"></i> TikTok</span>
                                <span class="badge-history"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
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
            <!-- Hỗ trợ (Active) -->
            <li>
                <a href="support.php" class="sidebar-link active">
                    <span class="sidebar-icon"><i class="fa-solid fa-headset"></i></span>
                    <span class="sidebar-title">Hỗ trợ</span>
                    <span class="badge-history ms-auto"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử</span>
                </a>
            </li>
            <?php if ($isAdmin): ?>
            <!-- Admin Panel (Chỉ hiển thị cho Admin) -->
            <li>
                <a href="/admin/dashboard" class="sidebar-link text-danger fw-bold">
                    <span class="sidebar-icon text-danger"><i class="fa-solid fa-shield-halved"></i></span>
                    <span class="sidebar-title">Admin Panel</span>
                    <span class="badge bg-danger-subtle text-danger px-2 py-0 ms-auto" style="font-size: 0.7rem;">Admin</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </aside>

    <!-- Lớp phủ Backdrop cho Mobile -->
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeAppSidebar()"></div>

    <!-- ==========================================================
     * 3. KHU VỰC NỘI DUNG CHÍNH (MAIN CONTENT)
     * ========================================================== -->
    <main class="app-main">

        <!-- Banner Chào Mừng & Kênh Hỗ Trợ Nhanh -->
        <div class="support-hero-banner">
            <div class="hero-badge">
                <i class="fa-solid fa-headset"></i> Trung Tâm Hỗ Trợ Khách Hàng 24/7
            </div>
            <h1 class="hero-title">Giải Đáp Thắc Mắc & Trợ Giúp Kỹ Thuật</h1>
            <p class="hero-subtitle">
                Đội ngũ kỹ thuật viên ThanhQuyTech luôn túc trực 24/7 để hỗ trợ bạn giải quyết mọi vấn đề về nạp tiền, key bản quyền, máy chủ cloud và công cụ tự động hóa.
            </p>

            <div class="quick-contact-grid">
                <a href="https://zalo.me" target="_blank" class="quick-contact-item">
                    <div class="qc-icon" style="background: rgba(14, 165, 233, 0.25); color: #38bdf8;">
                        <i class="fa-solid fa-comment-dots"></i>
                    </div>
                    <div class="qc-info">
                        <span class="qc-title">Zalo Hỗ Trợ 24/7</span>
                        <span class="qc-desc">0963.xxx.xxx</span>
                    </div>
                </a>

                <a href="https://t.me" target="_blank" class="quick-contact-item">
                    <div class="qc-icon" style="background: rgba(59, 130, 246, 0.25); color: #60a5fa;">
                        <i class="fa-brands fa-telegram"></i>
                    </div>
                    <div class="qc-info">
                        <span class="qc-title">Kênh Telegram</span>
                        <span class="qc-desc">@ThanhQuySupport</span>
                    </div>
                </a>

                <div class="quick-contact-item">
                    <div class="qc-icon" style="background: rgba(16, 185, 129, 0.25); color: #34d399;">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <div class="qc-info">
                        <span class="qc-title">Thời Gian Phản Hồi</span>
                        <span class="qc-desc">&lt; 15 Phút</span>
                    </div>
                </div>

                <div class="quick-contact-item">
                    <div class="qc-icon" style="background: rgba(245, 158, 11, 0.25); color: #fbbf24;">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div class="qc-info">
                        <span class="qc-title">Bảo Mật Yêu Cầu</span>
                        <span class="qc-desc">100% Riêng Tư</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Khối Thống Kê Yêu Cầu Hỗ Trợ -->
        <div class="stats-grid">
            <div class="stat-card">
                <div>
                    <div class="stat-number"><?= number_format((int)($stats['total'] ?? 0)) ?></div>
                    <div class="stat-label"><?= $isAdmin ? 'Tổng ticket hệ thống' : 'Tổng yêu cầu của bạn' ?></div>
                </div>
                <div class="stat-icon-wrap" style="background: #eef2ff; color: #4f46e5;">
                    <i class="fa-solid fa-ticket"></i>
                </div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-number" style="color: #ea580c;"><?= number_format((int)($stats['pending'] ?? 0)) ?></div>
                    <div class="stat-label">Chờ tiếp nhận / Xử lý</div>
                </div>
                <div class="stat-icon-wrap" style="background: #fff7ed; color: #ea580c;">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-number" style="color: #16a34a;"><?= number_format((int)($stats['answered'] ?? 0)) ?></div>
                    <div class="stat-label">Đã có phản hồi</div>
                </div>
                <div class="stat-icon-wrap" style="background: #f0fdf4; color: #16a34a;">
                    <i class="fa-solid fa-comments"></i>
                </div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-number" style="color: #64748b;"><?= number_format((int)($stats['closed'] ?? 0)) ?></div>
                    <div class="stat-label">Đã giải quyết & đóng</div>
                </div>
                <div class="stat-icon-wrap" style="background: #f1f5f9; color: #64748b;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
            </div>
        </div>

        <!-- ==========================================================
         * 4. CHI TIẾT TICKET ĐANG XEM & KHUNG CHAT (NẾU CÓ ?view=...)
         * ========================================================== -->
        <?php if ($activeTicket): ?>
        <div class="ticket-chat-card">
            <div class="chat-top-header">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge bg-primary px-2 py-1 fw-bold" style="font-size: 0.82rem;">
                            #<?= htmlspecialchars($activeTicket['ticket_code']) ?>
                        </span>
                        <?= getStatusBadge($activeTicket['status']) ?>
                        <?= getPriorityBadge($activeTicket['priority']) ?>
                        <?= getCategoryBadge($activeTicket['category']) ?>
                    </div>
                    <h3 class="fw-bold text-dark mb-1" style="font-size: 1.25rem;">
                        <?= htmlspecialchars($activeTicket['subject']) ?>
                    </h3>
                    <div class="text-muted" style="font-size: 0.8rem;">
                        <span>Người gửi: <strong><?= htmlspecialchars($activeTicket['user_name']) ?></strong> (<?= htmlspecialchars($activeTicket['user_username']) ?>)</span>
                        <span class="mx-2">•</span>
                        <span>Khởi tạo lúc: <?= date('d/m/Y H:i', strtotime($activeTicket['created_at'])) ?></span>
                        <?php if (!empty($activeTicket['order_code'])): ?>
                            <span class="mx-2">•</span>
                            <span>Mã đơn: <strong class="text-primary"><?= htmlspecialchars($activeTicket['order_code']) ?></strong></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <a href="support.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1 fw-bold">
                        <i class="fa-solid fa-arrow-left me-1"></i> Quay lại
                    </a>

                    <?php if ($isAdmin): ?>
                    <!-- Form đổi trạng thái nhanh của Admin -->
                    <form method="POST" action="support.php" class="d-inline-flex align-items-center gap-2">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="admin_change_status">
                        <input type="hidden" name="ticket_id" value="<?= $activeTicket['id'] ?>">
                        <select name="status" class="form-select form-select-sm rounded-pill" onchange="this.form.submit()">
                            <option value="Pending" <?= $activeTicket['status'] === 'Pending' ? 'selected' : '' ?>>Chờ phản hồi</option>
                            <option value="In Progress" <?= $activeTicket['status'] === 'In Progress' ? 'selected' : '' ?>>Đang xử lý</option>
                            <option value="Answered" <?= $activeTicket['status'] === 'Answered' ? 'selected' : '' ?>>Đã phản hồi</option>
                            <option value="Closed" <?= $activeTicket['status'] === 'Closed' ? 'selected' : '' ?>>Đã đóng</option>
                        </select>
                    </form>
                    <?php endif; ?>

                    <?php if ($activeTicket['status'] !== 'Closed'): ?>
                    <form method="POST" action="support.php" onsubmit="return confirm('Bạn có chắc chắn muốn đóng yêu cầu hỗ trợ này?');">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="close_ticket">
                        <input type="hidden" name="ticket_id" value="<?= $activeTicket['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1 fw-bold">
                            <i class="fa-solid fa-lock me-1"></i> Đóng ticket
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Khung trò chuyện (Chat Thread) -->
            <div class="chat-thread" id="chatThread">
                <?php if (empty($activeMessages)): ?>
                    <div class="text-center py-4 text-muted">Chưa có tin nhắn nào trong yêu cầu này.</div>
                <?php else: ?>
                    <?php foreach ($activeMessages as $msg): ?>
                        <?php 
                            $isFromAdmin = ($msg['sender_role'] === 'Admin' || $msg['sender_role'] === 'Support');
                        ?>
                        <div class="message-bubble-wrapper <?= $isFromAdmin ? 'admin-msg' : 'user-msg' ?>">
                            <img src="<?= htmlspecialchars($msg['sender_avatar']) ?>" alt="Avatar" class="msg-avatar" onerror="this.src='assets/images/default-avatar.svg'">
                            <div class="msg-content-box">
                                <div class="msg-sender-meta">
                                    <strong class="<?= $isFromAdmin ? 'text-primary' : 'text-dark' ?>">
                                        <?= htmlspecialchars($msg['sender_name']) ?>
                                    </strong>
                                    <?php if ($isFromAdmin): ?>
                                        <span class="badge bg-primary-subtle text-primary px-2 py-0" style="font-size: 0.65rem;">Kỹ thuật viên</span>
                                    <?php endif; ?>
                                    <span><?= date('H:i, d/m/Y', strtotime($msg['created_at'])) ?></span>
                                </div>
                                <div class="msg-bubble">
                                    <?= nl2br(htmlspecialchars($msg['message'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Khung Nhập Phản Hồi -->
            <?php if ($activeTicket['status'] !== 'Closed' || $isAdmin): ?>
            <div class="chat-input-area">
                <form method="POST" action="support.php">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                    <input type="hidden" name="action" value="reply_ticket">
                    <input type="hidden" name="ticket_id" value="<?= $activeTicket['id'] ?>">

                    <div class="mb-3">
                        <label class="form-label fw-bold text-dark" style="font-size: 0.88rem;">
                            <i class="fa-solid fa-reply text-primary me-1"></i> Phản hồi vào yêu cầu này:
                        </label>
                        <textarea name="message" class="form-control" rows="3" placeholder="Nhập câu hỏi, mô tả thêm vấn đề hoặc phản hồi của bạn..." required></textarea>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <div class="text-muted" style="font-size: 0.78rem;">
                            <i class="fa-solid fa-circle-info text-info me-1"></i> Phản hồi sẽ được gửi ngay tới ban quản trị & cập nhật vào luồng ticket.
                        </div>
                        <button type="submit" class="btn-reply-send">
                            <i class="fa-solid fa-paper-plane"></i> Gửi phản hồi
                        </button>
                    </div>
                </form>
            </div>
            <?php else: ?>
            <div class="p-3 bg-light text-center text-muted border-top" style="font-size: 0.9rem;">
                <i class="fa-solid fa-lock me-1"></i> Yêu cầu này đã được đóng. Nếu vẫn cần hỗ trợ, bạn vui lòng bấm nút gửi yêu cầu mới bên dưới.
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ==========================================================
         * 5. DANH SÁCH CÁC TICKET HỖ TRỢ (TABLE & CARDS)
         * ========================================================== -->
        <div class="tickets-container">
            <div class="tickets-header">
                <div>
                    <h2 class="tickets-title">
                        <i class="fa-solid fa-list-check text-primary"></i> Lịch Sử Yêu Cầu Hỗ Trợ
                    </h2>
                    <div class="text-muted" style="font-size: 0.8rem; margin-top: 2px;">
                        Theo dõi tiến trình giải quyết và trao đổi trực tiếp với nhân viên kỹ thuật
                    </div>
                </div>

                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <!-- Tabs Lọc Trạng Thái -->
                    <div class="filter-tabs">
                        <a href="support.php?status=all" class="filter-tab-item <?= $filterStatus === 'all' ? 'active' : '' ?>">Tất cả</a>
                        <a href="support.php?status=Pending" class="filter-tab-item <?= $filterStatus === 'Pending' ? 'active' : '' ?>">Chờ xử lý</a>
                        <a href="support.php?status=In Progress" class="filter-tab-item <?= $filterStatus === 'In Progress' ? 'active' : '' ?>">Đang làm</a>
                        <a href="support.php?status=Answered" class="filter-tab-item <?= $filterStatus === 'Answered' ? 'active' : '' ?>">Đã phản hồi</a>
                        <a href="support.php?status=Closed" class="filter-tab-item <?= $filterStatus === 'Closed' ? 'active' : '' ?>">Đã đóng</a>
                    </div>

                    <!-- Nút Mở Modal Tạo Ticket -->
                    <button type="button" class="btn-new-ticket" data-bs-toggle="modal" data-bs-target="#newTicketModal">
                        <i class="fa-solid fa-plus"></i> Gửi yêu cầu mới
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table-tickets">
                    <thead>
                        <tr>
                            <th>Mã Ticket</th>
                            <?php if ($isAdmin): ?>
                            <th>Người gửi</th>
                            <?php endif; ?>
                            <th>Chủ đề & Vấn đề</th>
                            <th>Danh mục</th>
                            <th>Ưu tiên</th>
                            <th>Trạng thái</th>
                            <th>Cập nhật</th>
                            <th class="text-end">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ticketList)): ?>
                        <tr>
                            <td colspan="<?= $isAdmin ? 8 : 7 ?>" class="text-center py-5">
                                <div class="py-4">
                                    <div class="mb-3 text-muted" style="font-size: 2.5rem;"><i class="fa-regular fa-folder-open"></i></div>
                                    <div class="fw-bold text-dark mb-1">Chưa có yêu cầu hỗ trợ nào</div>
                                    <div class="text-muted" style="font-size: 0.85rem;">Nếu bạn gặp khó khăn trong quá trình sử dụng hệ thống, hãy bấm "Gửi yêu cầu mới".</div>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($ticketList as $t): ?>
                            <tr>
                                <td>
                                    <a href="support.php?view=<?= $t['id'] ?>" class="ticket-code-link">
                                        #<?= htmlspecialchars($t['ticket_code']) ?>
                                    </a>
                                    <?php if ($t['msg_count'] > 1): ?>
                                        <span class="badge bg-light text-muted border ms-1" title="<?= $t['msg_count'] ?> tin nhắn">
                                            <i class="fa-solid fa-comment-dots"></i> <?= $t['msg_count'] ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($isAdmin): ?>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <img src="<?= htmlspecialchars($t['user_avatar']) ?>" alt="Avatar" class="rounded-circle" style="width: 28px; height: 28px; object-fit: cover;" onerror="this.src='assets/images/default-avatar.svg'">
                                        <div>
                                            <div class="fw-bold text-dark" style="font-size: 0.84rem;"><?= htmlspecialchars($t['user_name']) ?></div>
                                            <div class="text-muted" style="font-size: 0.72rem;"><?= htmlspecialchars($t['user_username']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <a href="support.php?view=<?= $t['id'] ?>" class="ticket-subject-text text-decoration-none">
                                        <?= htmlspecialchars($t['subject']) ?>
                                    </a>
                                    <?php if (!empty($t['order_code'])): ?>
                                        <div class="text-muted" style="font-size: 0.75rem;">
                                            <i class="fa-solid fa-link text-primary me-1"></i> Đơn: <?= htmlspecialchars($t['order_code']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= getCategoryBadge($t['category']) ?></td>
                                <td><?= getPriorityBadge($t['priority']) ?></td>
                                <td><?= getStatusBadge($t['status']) ?></td>
                                <td>
                                    <div style="font-size: 0.82rem; font-weight: 600; color: #475569;">
                                        <?= date('d/m/Y', strtotime($t['updated_at'])) ?>
                                    </div>
                                    <div class="text-muted" style="font-size: 0.72rem;">
                                        <?= date('H:i', strtotime($t['updated_at'])) ?>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <a href="support.php?view=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fw-bold" style="font-size: 0.8rem;">
                                        <i class="fa-solid fa-comments me-1"></i> Xem & Trao đổi
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- ==========================================================
     * 6. MODAL GỬI YÊU CẦU HỖ TRỢ MỚI
     * ========================================================== -->
    <div class="modal fade" id="newTicketModal" tabindex="-1" aria-labelledby="newTicketModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">
                <div class="modal-header bg-light border-bottom px-4 py-3">
                    <h5 class="modal-title fw-bold text-dark" id="newTicketModalLabel">
                        <i class="fa-solid fa-paper-plane text-primary me-2"></i> Gửi Yêu Cầu Hỗ Trợ Mới
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="support.php">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                    <input type="hidden" name="action" value="create_ticket">

                    <div class="modal-body p-4">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark" style="font-size: 0.86rem;">
                                    <i class="fa-solid fa-layer-group text-primary me-1"></i> Danh mục cần hỗ trợ <span class="text-danger">*</span>
                                </label>
                                <select name="category" class="form-select rounded-3" required>
                                    <option value="Billing">Nạp tiền & Lỗi giao dịch ngân hàng</option>
                                    <option value="LicenseKey">Bản quyền Key VIP & Lỗi kích hoạt</option>
                                    <option value="CloudServer">Máy chủ Cloud treo & Kết nối VPS</option>
                                    <option value="GolikeTool">Tool Golike & Lỗi tự động chạy job</option>
                                    <option value="Account">Tài khoản & Đổi mật khẩu</option>
                                    <option value="Other" selected>Vấn đề khác</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark" style="font-size: 0.86rem;">
                                    <i class="fa-solid fa-flag text-warning me-1"></i> Mức độ ưu tiên <span class="text-danger">*</span>
                                </label>
                                <select name="priority" class="form-select rounded-3" required>
                                    <option value="Low">Thấp - Câu hỏi tư vấn chung</option>
                                    <option value="Medium" selected>Trung bình - Cần giải đáp trong ngày</option>
                                    <option value="High">Cao - Gián đoạn sử dụng dịch vụ</option>
                                    <option value="Urgent">Khẩn cấp - Lỗi nạp tiền / Key VIP không hoạt động</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark" style="font-size: 0.86rem;">
                                <i class="fa-solid fa-heading text-primary me-1"></i> Tiêu đề ngắn gọn tóm tắt vấn đề <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="subject" class="form-control rounded-3" placeholder="Ví dụ: Nạp tiền chưa cộng số dư sau 10 phút, Lỗi key bản quyền..." required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark" style="font-size: 0.86rem;">
                                <i class="fa-solid fa-barcode text-secondary me-1"></i> Mã đơn hàng / Mã giao dịch liên quan (Nếu có)
                            </label>
                            <input type="text" name="order_code" class="form-control rounded-3" placeholder="Ví dụ: NAP849201, #ORD-982144, TQ-REF-VIP...">
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-bold text-dark" style="font-size: 0.86rem;">
                                <i class="fa-solid fa-message text-primary me-1"></i> Mô tả chi tiết vấn đề & Yêu cầu của bạn <span class="text-danger">*</span>
                            </label>
                            <textarea name="message" class="form-control rounded-3" rows="5" placeholder="Vui lòng mô tả chi tiết: Thời gian xảy ra lỗi, thông báo lỗi xuất hiện, các bước bạn đã thử..." required></textarea>
                        </div>
                        <div class="form-text" style="font-size: 0.78rem;">
                            <i class="fa-solid fa-circle-check text-success me-1"></i> Yêu cầu của bạn sẽ được bảo mật và phản hồi trực tiếp qua trang này.
                        </div>
                    </div>

                    <div class="modal-footer bg-light px-4 py-3">
                        <button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Hủy bỏ</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold" style="background: var(--gradient-primary); border: none;">
                            <i class="fa-solid fa-paper-plane me-1"></i> Tiếp nhận yêu cầu
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5.3.3 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- SweetAlert2 (Tùy chọn hiển thị Flash message đẹp) -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        <?php if ($flash): ?>
        Swal.fire({
            icon: '<?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warning' ? 'warning' : 'success') ?>',
            title: '<?= htmlspecialchars($flash['title'] ?: 'Thông báo') ?>',
            html: '<?= addslashes($flash['message']) ?>',
            confirmButtonColor: '#4f46e5',
            confirmButtonText: 'Đã hiểu'
        });
        <?php endif; ?>

        // Cuộn xuống tin nhắn mới nhất nếu đang xem chi tiết chat
        var chatThread = document.getElementById('chatThread');
        if (chatThread) {
            chatThread.scrollTop = chatThread.scrollHeight;
        }

        function confirmLogout() {
            Swal.fire({
                title: 'Đăng xuất tài khoản?',
                text: 'Bạn có chắc chắn muốn thoát khỏi phiên làm việc này?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Đăng xuất ngay',
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
