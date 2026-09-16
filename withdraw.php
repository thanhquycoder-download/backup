<?php
/**
 * ==========================================================
 * TRANG RÚT TIỀN TÀI KHOẢN VỀ NGÂN HÀNG (WITHDRAW)
 * DỰA TRÊN CẤU TRÚC VÀ GIAO DIỆN CHUẨN 1:1 CỦA DEPOSIT.PHP
 * File: withdraw.php & payments/withdraw.php
 * Website: ThanhQuyTech
 * ==========================================================
 */

require_once __DIR__ . '/config/config.php';

// Bắt buộc người dùng phải đăng nhập
require_login();

if (!function_exists('time_ago')) {
    function time_ago($datetime): string {
        if (empty($datetime)) return '';
        $timestamp = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
        if (!$timestamp) return '';
        $diff = time() - $timestamp;
        if ($diff < 60) return 'Vừa xong';
        $minutes = floor($diff / 60);
        if ($minutes < 60) return $minutes . ' phút trước';
        $hours = floor($diff / 3600);
        if ($hours < 24) return $hours . ' giờ trước';
        $days = floor($diff / 86400);
        if ($days < 30) return $days . ' ngày trước';
        return date('d/m/Y H:i', $timestamp);
    }
}

// ----------------------------------------------------------
// 1. TỰ ĐỘNG KHỞI TẠO BẢNG WITHDRAWALS NẾU CHƯA CÓ
// ----------------------------------------------------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `withdrawals` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_uuid` CHAR(36) NOT NULL,
            `withdraw_code` VARCHAR(50) NOT NULL UNIQUE,
            `bank_name` VARCHAR(100) NOT NULL,
            `bank_code` VARCHAR(50) DEFAULT NULL,
            `account_number` VARCHAR(50) NOT NULL,
            `account_name` VARCHAR(100) NOT NULL,
            `amount` DECIMAL(15, 2) NOT NULL,
            `fee` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
            `net_amount` DECIMAL(15, 2) NOT NULL,
            `user_note` VARCHAR(255) DEFAULT NULL,
            `admin_note` VARCHAR(255) DEFAULT NULL,
            `status` ENUM('Pending', 'Success', 'Failed', 'Cancelled') NOT NULL DEFAULT 'Pending',
            `proof_image` VARCHAR(255) DEFAULT NULL,
            `processed_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_wd_user_uuid` (`user_uuid`),
            INDEX `idx_wd_code` (`withdraw_code`),
            INDEX `idx_wd_status` (`status`),
            INDEX `idx_wd_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {
    error_log("Withdrawal Table Setup Error: " . $e->getMessage());
}

// ----------------------------------------------------------
// 2. LẤY DỮ LIỆU TÀI KHOẢN NGƯỜI DÙNG ĐANG ĐĂNG NHẬP
// ----------------------------------------------------------
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE uuid = ? LIMIT 1");
$stmtUser->execute([$_SESSION['user_uuid']]);
$currentUser = $stmtUser->fetch();

if (!$currentUser) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$isAdmin = ($currentUser['role'] === 'Admin');

// Danh sách ngân hàng và ví điện tử Việt Nam
$supportedBanks = [
    ['code' => 'MB', 'name' => 'MBBank (Ngân Hàng Quân Đội)'],
    ['code' => 'VCB', 'name' => 'Vietcombank (Ngoại Thương Việt Nam)'],
    ['code' => 'TCB', 'name' => 'Techcombank (Kỹ Thương Việt Nam)'],
    ['code' => 'ACB', 'name' => 'ACB (Á Châu)'],
    ['code' => 'BIDV', 'name' => 'BIDV (Đầu Tư & Phát Triển Việt Nam)'],
    ['code' => 'CTG', 'name' => 'VietinBank (Công Thương Việt Nam)'],
    ['code' => 'TPB', 'name' => 'TPBank (Tiên Phong)'],
    ['code' => 'VPB', 'name' => 'VPBank (Việt Nam Thịnh Vượng)'],
    ['code' => 'STB', 'name' => 'Sacombank (Sài Gòn Thương Tín)'],
    ['code' => 'HDB', 'name' => 'HDBank (Phát Triển TP.HCM)'],
    ['code' => 'VIB', 'name' => 'VIB (Quốc Tế Việt Nam)'],
    ['code' => 'SHB', 'name' => 'SHB (Sài Gòn - Hà Nội)'],
    ['code' => 'MSB', 'name' => 'MSB (Hàng Hải)'],
    ['code' => 'OCB', 'name' => 'OCB (Phương Đông)'],
    ['code' => 'LPB', 'name' => 'LPBank (Lộc Phát Việt Nam)'],
    ['code' => 'MOMO', 'name' => 'Ví Điện Tử MoMo'],
    ['code' => 'ZALOPAY', 'name' => 'Ví Điện Tử ZaloPay'],
];

// Định tuyến đường dẫn
$redirectRoute = strpos($_SERVER['REQUEST_URI'] ?? '', '/payments/withdraw') !== false ? '/payments/withdraw' : 'withdraw.php';

// ----------------------------------------------------------
// 3. XỬ LÝ POST: TẠO LỆNH RÚT TIỀN HOẶC HỦY LỆNH
// ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submittedToken)) {
        set_flash('danger', 'Lỗi bảo mật', 'Mã phiên CSRF không hợp lệ hoặc đã hết hạn. Vui lòng tải lại trang.');
        header("Location: " . $redirectRoute);
        exit;
    }

    $action = trim($_POST['action'] ?? '');

    // 3.1. ACTION: TẠO YÊU CẦU RÚT TIỀN
    if ($action === 'create_withdrawal') {
        $bankCode = trim($_POST['bank_code'] ?? '');
        $bankName = trim($_POST['bank_name'] ?? '');
        $accountNumber = preg_replace('/[^0-9A-Za-z]/', '', trim($_POST['account_number'] ?? ''));
        $accountName = mb_strtoupper(trim($_POST['account_name'] ?? ''), 'UTF-8');
        $rawAmount = trim($_POST['amount'] ?? '0');
        $userNote = trim($_POST['user_note'] ?? '');

        $amount = (float)str_replace(['.', ',', ' '], '', $rawAmount);

        if (empty($bankCode) || empty($bankName)) {
            set_flash('danger', 'Thiếu thông tin', 'Vui lòng chọn ngân hàng hoặc ví điện tử thụ hưởng.');
            header("Location: " . $redirectRoute);
            exit;
        }

        if (empty($accountNumber) || strlen($accountNumber) < 5) {
            set_flash('danger', 'Số tài khoản không hợp lệ', 'Vui lòng nhập chính xác số tài khoản ngân hàng hoặc số ví nhận tiền.');
            header("Location: " . $redirectRoute);
            exit;
        }

        if (empty($accountName) || mb_strlen($accountName, 'UTF-8') < 3) {
            set_flash('danger', 'Tên chủ tài khoản trống', 'Vui lòng nhập họ và tên chủ tài khoản nhận tiền.');
            header("Location: " . $redirectRoute);
            exit;
        }

        $minWithdraw = 50000.00;
        $maxWithdraw = 50000000.00;

        if ($amount < $minWithdraw) {
            set_flash('warning', 'Hạn mức không hợp lệ', 'Số tiền rút tối thiểu mỗi lần là ' . number_format($minWithdraw, 0, ',', '.') . ' ₫.');
            header("Location: " . $redirectRoute);
            exit;
        }

        if ($amount > $maxWithdraw) {
            set_flash('warning', 'Vượt quá hạn mức', 'Số tiền rút tối đa cho một giao dịch là ' . number_format($maxWithdraw, 0, ',', '.') . ' ₫.');
            header("Location: " . $redirectRoute);
            exit;
        }

        $userBalance = (float)$currentUser['balance'];
        if ($amount > $userBalance) {
            set_flash('warning', 'Số dư không đủ', 'Số dư hiện tại của bạn (' . number_format($userBalance, 0, ',', '.') . ' ₫) không đủ để thực hiện rút ' . number_format($amount, 0, ',', '.') . ' ₫.');
            header("Location: " . $redirectRoute);
            exit;
        }

        $fee = 0.00;
        $netAmount = $amount - $fee;
        $withdrawCode = 'RUT' . date('ymd') . rand(10000, 99999);

        try {
            $pdo->beginTransaction();

            // 1. Trừ tiền số dư ngay lập tức
            $newBalance = $userBalance - $amount;
            $stmtUpdateUser = $pdo->prepare("UPDATE users SET balance = ? WHERE uuid = ?");
            $stmtUpdateUser->execute([$newBalance, $currentUser['uuid']]);

            // 2. Ghi nhận vào bảng withdrawals
            $stmtInsertWd = $pdo->prepare("
                INSERT INTO `withdrawals` 
                (`user_uuid`, `withdraw_code`, `bank_name`, `bank_code`, `account_number`, `account_name`, `amount`, `fee`, `net_amount`, `user_note`, `status`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
            ");
            $stmtInsertWd->execute([
                $currentUser['uuid'],
                $withdrawCode,
                $bankName,
                $bankCode,
                $accountNumber,
                $accountName,
                $amount,
                $fee,
                $netAmount,
                $userNote
            ]);

            // 3. Ghi log biến động số dư
            $stmtInsertTrans = $pdo->prepare("
                INSERT INTO `transactions`
                (`user_uuid`, `code`, `type`, `amount`, `balance_before`, `balance_after`, `status`, `note`)
                VALUES (?, ?, 'Withdraw', ?, ?, ?, 'Pending', ?)
            ");
            $transNote = "Yêu cầu rút tiền #" . $withdrawCode . " về " . $bankName . " - STK: " . $accountNumber;
            $stmtInsertTrans->execute([
                $currentUser['uuid'],
                $withdrawCode,
                $amount,
                $userBalance,
                $newBalance,
                $transNote
            ]);

            $pdo->commit();

            set_flash('success', 'Tạo yêu cầu rút tiền thành công', 'Lệnh rút ' . number_format($amount, 0, ',', '.') . ' ₫ (Mã #' . $withdrawCode . ') đã được tạo. Ban quản trị sẽ giải ngân trong 5-30 phút.');
            header("Location: " . $redirectRoute);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Lỗi tạo lệnh rút tiền: " . $e->getMessage());
            set_flash('danger', 'Lỗi hệ thống', 'Đã có lỗi xảy ra khi tạo lệnh rút tiền. Vui lòng thử lại.');
            header("Location: " . $redirectRoute);
            exit;
        }
    }

    // 3.2. ACTION: HỦY YÊU CẦU RÚT TIỀN (KHI ĐANG CHỜ DUYỆT)
    if ($action === 'cancel_withdrawal') {
        $withdrawId = (int)($_POST['withdraw_id'] ?? 0);
        if ($withdrawId <= 0) {
            set_flash('danger', 'Lỗi', 'Lệnh rút tiền không tồn tại.');
            header("Location: " . $redirectRoute);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $stmtWd = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ? AND user_uuid = ? FOR UPDATE");
            $stmtWd->execute([$withdrawId, $currentUser['uuid']]);
            $wdItem = $stmtWd->fetch();

            if (!$wdItem) {
                $pdo->rollBack();
                set_flash('danger', 'Lỗi', 'Không tìm thấy thông tin lệnh rút tiền này.');
                header("Location: " . $redirectRoute);
                exit;
            }

            if ($wdItem['status'] !== 'Pending') {
                $pdo->rollBack();
                set_flash('warning', 'Không thể hủy', 'Lệnh rút này đã được duyệt hoặc đã đóng, không thể hủy bỏ.');
                header("Location: " . $redirectRoute);
                exit;
            }

            $refundAmount = (float)$wdItem['amount'];

            // Cập nhật trạng thái
            $stmtCancel = $pdo->prepare("UPDATE withdrawals SET status = 'Cancelled' WHERE id = ?");
            $stmtCancel->execute([$withdrawId]);

            // Hoàn trả lại số dư cho người dùng
            $stmtCurBal = $pdo->prepare("SELECT balance FROM users WHERE uuid = ? FOR UPDATE");
            $stmtCurBal->execute([$currentUser['uuid']]);
            $curBal = (float)$stmtCurBal->fetchColumn();

            $newBal = $curBal + $refundAmount;
            $stmtRefundUser = $pdo->prepare("UPDATE users SET balance = ? WHERE uuid = ?");
            $stmtRefundUser->execute([$newBal, $currentUser['uuid']]);

            // Ghi log hoàn tiền
            $refundCode = 'REF' . date('ymd') . rand(10000, 99999);
            $stmtInsertRefTrans = $pdo->prepare("
                INSERT INTO `transactions`
                (`user_uuid`, `code`, `type`, `amount`, `balance_before`, `balance_after`, `status`, `note`)
                VALUES (?, ?, 'Refund', ?, ?, ?, 'Success', ?)
            ");
            $stmtInsertRefTrans->execute([
                $currentUser['uuid'],
                $refundCode,
                $refundAmount,
                $curBal,
                $newBal,
                'Hoàn tiền do người dùng hủy lệnh rút #' . $wdItem['withdraw_code']
            ]);

            $pdo->commit();

            set_flash('success', 'Hủy lệnh rút thành công', 'Lệnh rút tiền #' . $wdItem['withdraw_code'] . ' đã được hủy. Số tiền ' . number_format($refundAmount, 0, ',', '.') . ' ₫ đã hoàn về ví của bạn.');
            header("Location: " . $redirectRoute);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Lỗi hủy lệnh rút: " . $e->getMessage());
            set_flash('danger', 'Lỗi hệ thống', 'Không thể hủy lệnh rút lúc này. Vui lòng thử lại.');
            header("Location: " . $redirectRoute);
            exit;
        }
    }
}

// ----------------------------------------------------------
// 4. LẤY LỊCH SỬ RÚT TIỀN & THỐNG KÊ
// ----------------------------------------------------------
$stmtHistory = $pdo->prepare("
    SELECT * FROM withdrawals 
    WHERE user_uuid = ? 
    ORDER BY id DESC 
    LIMIT 100
");
$stmtHistory->execute([$currentUser['uuid']]);
$withdrawHistory = $stmtHistory->fetchAll();

// Tính toán 4 thẻ thống kê
$totalWithdrawn = 0.00;
$successCount = 0;
$pendingCount = 0;

foreach ($withdrawHistory as $item) {
    if ($item['status'] === 'Success') {
        $totalWithdrawn += (float)$item['amount'];
        $successCount++;
    } elseif ($item['status'] === 'Pending') {
        $pendingCount++;
    }
}

$flash = get_flash();
$csrfToken = get_csrf_token();
$dynamicHost = $_SERVER['HTTP_HOST'] ?? 'thanhquytech.vn';

// Chữ ký thật trong suốt của Phan Thành Quý
$signatureSource = __DIR__ . '/assets/images/signature.png';
$signatureLocalTrans = __DIR__ . '/assets/images/signature_transparent.png';
$signatureBase64 = '';
if (file_exists($signatureLocalTrans) && filesize($signatureLocalTrans) > 0) {
    $signatureBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($signatureLocalTrans));
} elseif (file_exists($signatureSource)) {
    $signatureBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($signatureSource));
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <base href="/">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Rút Tiền Tài Khoản Về Ngân Hàng - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico">

    <!-- Google Fonts: Plus Jakarta Sans & Fira Code -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Fira+Code:wght@400;500;600&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- html2pdf.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

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
            --gradient-success: linear-gradient(135deg, #059669 0%, #10b981 50%, #34d399 100%);
            --gradient-dark: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
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

        /* 1. Header */
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

        .header-left { display: flex; align-items: center; gap: 14px; }
        .sidebar-toggle-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: #ffffff;
            color: var(--text-body);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
        }
        .sidebar-toggle-btn:hover { background: #f1f5f9; color: var(--primary); }

        .brand-logo { display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .brand-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: var(--gradient-primary);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.25);
        }
        .brand-text { font-size: 1.2rem; font-weight: 800; color: var(--text-heading); letter-spacing: -0.3px; }
        .brand-text span { color: var(--primary); }

        .header-right { display: flex; align-items: center; gap: 12px; }

        .header-balance-card {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            border: 1px solid var(--card-border);
            padding: 6px 14px;
            border-radius: 50px;
            text-decoration: none;
            transition: var(--transition);
        }
        .header-balance-card:hover { border-color: var(--primary); background: #f0fdf4; }
        .balance-wallet-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #ecfdf5;
            color: #10b981;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
        }
        .balance-text-group { display: flex; flex-direction: column; }
        .balance-title { font-size: 0.68rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }
        .balance-val { font-size: 0.9rem; font-weight: 800; color: #166534; font-family: 'Fira Code', monospace; }

        .user-profile-container { position: relative; }
        .user-profile-toggle {
            background: transparent;
            border: none;
            padding: 0;
            cursor: pointer;
            display: flex;
            align-items: center;
        }
        .user-avatar-small {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e0e7ff;
        }
        .user-profile-popup {
            position: absolute;
            top: 52px;
            right: 0;
            width: 270px;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.15);
            border: 1px solid var(--card-border);
            padding: 16px;
            display: none;
            z-index: 1050;
        }
        .user-profile-popup.active { display: block; animation: fadeInPopup 0.2s ease; }
        @keyframes fadeInPopup { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }

        .popup-menu-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            color: var(--text-body);
            text-decoration: none;
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 600;
            transition: var(--transition);
        }
        .popup-menu-item:hover { background: #f1f5f9; color: var(--primary); }

        /* 2. Sidebar */
        .app-sidebar {
            position: fixed;
            top: 70px;
            left: 0;
            bottom: 0;
            width: 260px;
            background: #ffffff;
            border-right: 1px solid var(--card-border);
            padding: 20px 14px;
            overflow-y: auto;
            z-index: 1030;
            transition: transform 0.3s ease;
        }
        .sidebar-category {
            font-size: 0.72rem;
            font-weight: 800;
            color: #94a3b8;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            padding: 12px 14px 6px;
        }
        .sidebar-nav-list { list-style: none; margin: 0; padding: 0; }
        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            color: #475569;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            border-radius: 10px;
            transition: var(--transition);
            width: 100%;
            background: transparent;
            border: none;
            text-align: left;
        }
        .sidebar-link:hover { color: var(--primary); background: #f8fafc; }
        .sidebar-link.active {
            background: #eff6ff;
            color: var(--primary);
            font-weight: 700;
        }
        .sidebar-arrow { margin-left: auto; font-size: 0.75rem; transition: transform 0.2s ease; }
        .sidebar-link:not(.collapsed) .sidebar-arrow { transform: rotate(180deg); }
        .sidebar-submenu { list-style: none; padding: 4px 0 4px 34px; }
        .submenu-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 7px 12px;
            color: #64748b;
            text-decoration: none;
            font-size: 0.84rem;
            font-weight: 500;
            border-radius: 8px;
            transition: var(--transition);
        }
        .submenu-link:hover { color: var(--primary); background: #f8fafc; }
        .submenu-link.active { color: var(--primary); background: #e0e7ff; font-weight: 700; }
        .badge-history {
            font-size: 0.65rem;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #64748b;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(4px);
            z-index: 1020;
        }
        .sidebar-backdrop.active { display: block; }

        /* 3. Main Layout */
        .app-main {
            margin-top: 70px;
            margin-left: 260px;
            padding: 30px;
            min-height: calc(100vh - 70px);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: margin-left 0.3s ease;
        }
        body.sidebar-collapsed .app-sidebar { transform: translateX(-100%); }
        body.sidebar-collapsed .app-main { margin-left: 0; }

        .content-container { width: 100%; max-width: 1200px; margin: 0 auto; }

        /* Breadcrumb */
        .breadcrumb-custom {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.84rem;
            color: var(--text-muted);
            margin-bottom: 20px;
        }
        .breadcrumb-custom a { color: var(--text-muted); text-decoration: none; font-weight: 600; }
        .breadcrumb-custom a:hover { color: var(--primary); }

        /* Hero Banner */
        .withdraw-hero {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 60%, #4338ca 100%);
            border-radius: var(--radius-lg);
            padding: 32px 36px;
            color: #ffffff;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 30px -10px rgba(49, 46, 129, 0.3);
        }
        .withdraw-hero::after {
            content: '';
            position: absolute;
            top: -40px;
            right: -40px;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            pointer-events: none;
        }
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 700;
            margin-bottom: 12px;
            color: #fde047;
        }
        .hero-title { font-size: 1.7rem; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 8px; }
        .hero-desc { font-size: 0.92rem; color: #c7d2fe; max-width: 680px; margin: 0; line-height: 1.5; }

        /* 4 Thẻ Thống Kê Chuẩn 1:1 */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: var(--shadow-card);
            transition: var(--transition);
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0, 0, 0, 0.06); }
        .card-balance-highlight {
            background: #f0fdf4 !important;
            border: 1.5px solid #bbf7d0 !important;
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        .stat-info { display: flex; flex-direction: column; }
        .stat-label { font-size: 0.74rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); }
        .stat-val { font-size: 1.25rem; font-weight: 800; color: var(--text-heading); font-family: 'Fira Code', monospace; }

        /* Layout Khối Form Rút Tiền */
        .withdraw-layout { margin-bottom: 30px; }
        .withdraw-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 28px 30px;
            box-shadow: var(--shadow-card);
        }
        .card-header-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f1f5f9;
        }
        .card-title-text {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Nút chọn nhanh số tiền pills */
        .amount-quick-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
        }
        .amount-pill-btn {
            border: 1px solid var(--card-border);
            background: #ffffff;
            color: #475569;
            padding: 7px 14px;
            border-radius: 50px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
        }
        .amount-pill-btn:hover { background: #f1f5f9; color: var(--primary); border-color: var(--primary); }
        .amount-pill-btn.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.25);
        }

        /* Khung nhập tiền cao cấp Fintech */
        .premium-amount-box {
            display: flex;
            align-items: center;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 8px 16px;
            transition: var(--transition);
        }
        .premium-amount-box:focus-within {
            border-color: var(--primary);
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }
        .currency-symbol-badge {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #e0e7ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            margin-right: 12px;
        }
        .amount-field-inner { flex-grow: 1; }
        .amount-field-label { font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }
        .amount-input-control {
            border: none;
            background: transparent;
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--text-heading);
            width: 100%;
            outline: none;
            font-family: 'Fira Code', monospace;
        }
        .currency-tag-pill {
            font-size: 0.85rem;
            font-weight: 800;
            color: var(--text-muted);
            background: #e2e8f0;
            padding: 4px 10px;
            border-radius: 20px;
        }

        .btn-gradient-primary {
            background: var(--gradient-primary);
            color: #ffffff;
            border: none;
            border-radius: 14px;
            padding: 14px 28px;
            font-size: 1rem;
            font-weight: 800;
            width: 100%;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn-gradient-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4);
            color: #ffffff;
        }

        /* Form Controls */
        .form-label-custom {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--text-heading);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .form-control-custom, .form-select-custom {
            border: 1.5px solid var(--card-border);
            border-radius: 12px;
            padding: 11px 16px;
            font-size: 0.92rem;
            font-weight: 600;
            color: var(--text-heading);
            transition: var(--transition);
            width: 100%;
            background-color: #ffffff;
        }
        .form-control-custom:focus, .form-select-custom:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.12);
            outline: none;
        }

        /* Thẻ Bảng Lịch Sử (Chuẩn 1:1 theo Dash-Card của deposit.php) */
        .dash-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 24px 28px;
            box-shadow: var(--shadow-card);
            margin-bottom: 30px;
        }
        .history-count-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8fafc;
            border: 1px solid var(--card-border);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.82rem;
        }
        .count-badge-icon { color: var(--primary); }
        .count-badge-label { color: var(--text-muted); font-weight: 600; }
        .count-badge-number { font-weight: 800; color: var(--text-heading); font-family: 'Fira Code', monospace; }

        .history-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .history-table th {
            background: #f8fafc;
            color: #475569;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 13px 14px;
            border-bottom: 2px solid #e2e8f0;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        .history-table td {
            padding: 14px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.88rem;
            white-space: nowrap;
            vertical-align: middle;
        }
        .history-table tr:hover td { background: #f8faff; }

        /* Badges trạng thái chuẩn 1:1 */
        .badge-status-pending { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; padding: 4px 10px; border-radius: 50px; font-weight: 700; font-size: 0.76rem; display: inline-flex; align-items: center; gap: 4px; }
        .badge-status-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; padding: 4px 10px; border-radius: 50px; font-weight: 700; font-size: 0.76rem; display: inline-flex; align-items: center; gap: 4px; }
        .badge-status-cancelled { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; padding: 4px 10px; border-radius: 50px; font-weight: 700; font-size: 0.76rem; display: inline-flex; align-items: center; gap: 4px; }
        .badge-status-failed { background: #fee2e2; color: #b91c1c; border: 1px solid #fecdd3; padding: 4px 10px; border-radius: 50px; font-weight: 700; font-size: 0.76rem; display: inline-flex; align-items: center; gap: 4px; }

        /* Nút hành động trong bảng chuẩn 1:1 */
        .btn-action-view {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.76rem;
            font-weight: 700;
            transition: var(--transition);
            box-shadow: 0 1px 3px rgba(37, 99, 235, 0.08);
            text-decoration: none;
            cursor: pointer;
        }
        .btn-action-view:hover {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 3px 8px rgba(37, 99, 235, 0.25);
            transform: translateY(-1px);
        }
        .btn-action-cancel {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            background: #fff1f2;
            color: #e11d48;
            border: 1px solid #fecdd3;
            padding: 5px 11px;
            border-radius: 50px;
            font-size: 0.76rem;
            font-weight: 700;
            transition: var(--transition);
            box-shadow: 0 1px 3px rgba(225, 29, 72, 0.08);
            text-decoration: none;
            cursor: pointer;
        }
        .btn-action-cancel:hover {
            background: #e11d48;
            color: #ffffff;
            border-color: #e11d48;
            box-shadow: 0 3px 8px rgba(225, 29, 72, 0.25);
            transform: translateY(-1px);
        }

        /* 4. Phiếu Chi Điện Tử Modal (Chuẩn 1:1 theo Deposit Invoice) */
        .invoice-card {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 8px !important;
            padding: 14px 18px !important;
            color: #0f172a !important;
            width: 100% !important;
            margin: 0 !important;
            font-size: 0.82rem !important;
            line-height: 1.35 !important;
        }
        .invoice-barcode-wrap {
            text-align: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 4px 10px;
            margin: 4px auto 6px auto;
            max-width: 260px;
        }
        .invoice-barcode-img {
            width: 180px;
            height: 24px;
            display: inline-block;
            image-rendering: pixelated;
        }
        .invoice-meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 8px;
        }
        .meta-col {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 7px 10px;
        }
        .meta-title {
            font-size: 0.78rem;
            font-weight: 800;
            color: #1e3a8a;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 3px;
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        .meta-table { width: 100%; font-size: 0.77rem; border-collapse: collapse; }
        .meta-table td { padding: 2px 2px; vertical-align: top; border: none !important; }
        .meta-label { width: 38%; color: #64748b; white-space: nowrap; }
        .meta-val { color: #0f172a; }

        .invoice-table {
            width: 100% !important;
            border-collapse: collapse !important;
            border: 1.5px solid #475569 !important;
            margin: 6px 0 !important;
            font-size: 0.78rem !important;
        }
        .invoice-table th {
            background: #f1f5f9 !important;
            color: #0f172a !important;
            font-weight: 700 !important;
            padding: 5px 6px !important;
            border: 1px solid #64748b !important;
            text-align: center !important;
        }
        .invoice-table td {
            padding: 4px 6px !important;
            border: 1px solid #94a3b8 !important;
            color: #1e293b !important;
        }

        /* Con dấu đỏ SVG & Chữ ký */
        .red-stamp-seal {
            width: 105px;
            height: 105px;
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%) rotate(-6deg);
            opacity: 0.9;
        }
        .signature-stroke-img {
            max-height: 60px;
            max-width: 155px;
            mix-blend-mode: multiply;
            filter: contrast(1.2);
            display: inline-block;
        }

        /* 5. SVG Stroke Draw Animation Dialog Modal */
        .svg-dialog-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(5px);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
        }
        .svg-dialog-overlay.active { opacity: 1; visibility: visible; }
        .svg-dialog-box {
            background: #ffffff;
            border-radius: 24px;
            width: 100%;
            max-width: 440px;
            padding: 30px 24px;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.25);
            transform: translateY(20px) scale(0.95);
            transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .svg-dialog-overlay.active .svg-dialog-box { transform: translateY(0) scale(1); }
        .svg-icon-container { display: flex; justify-content: center; align-items: center; margin-bottom: 16px; }
        .svg-draw-icon { width: 80px; height: 80px; }

        .svg-circle {
            stroke-dasharray: 215;
            stroke-dashoffset: 215;
            animation: drawStroke 0.6s cubic-bezier(0.65, 0, 0.45, 1) forwards;
            stroke-width: 4;
            fill: none;
        }
        .svg-check {
            stroke-dasharray: 60;
            stroke-dashoffset: 60;
            animation: drawStroke 0.4s cubic-bezier(0.65, 0, 0.45, 1) 0.5s forwards;
            stroke-width: 4.5;
            stroke-linecap: round;
            stroke-linejoin: round;
            fill: none;
        }
        .svg-error .line-1 {
            stroke: #ef4444;
            stroke-dasharray: 50;
            stroke-dashoffset: 50;
            animation: drawStroke 0.3s cubic-bezier(0.65, 0, 0.45, 1) 0.45s forwards;
            stroke-width: 4.5;
            stroke-linecap: round;
        }
        .svg-error .line-2 {
            stroke: #ef4444;
            stroke-dasharray: 50;
            stroke-dashoffset: 50;
            animation: drawStroke 0.3s cubic-bezier(0.65, 0, 0.45, 1) 0.6s forwards;
            stroke-width: 4.5;
            stroke-linecap: round;
        }
        .svg-question {
            stroke-dasharray: 100;
            stroke-dashoffset: 100;
            animation: drawStroke 0.5s ease-out 0.4s forwards;
            stroke-width: 4.5;
            stroke-linecap: round;
            stroke-linejoin: round;
            fill: none;
        }
        .svg-question-dot { opacity: 0; animation: fadeInDot 0.25s ease-out 0.85s forwards; }
        .svg-triangle {
            stroke-dasharray: 200;
            stroke-dashoffset: 200;
            animation: drawStroke 0.6s ease-out forwards;
            stroke-width: 4;
            fill: none;
        }
        .svg-exclamation-line {
            stroke-dasharray: 30;
            stroke-dashoffset: 30;
            animation: drawStroke 0.3s 0.45s ease-out forwards;
            stroke-width: 4.5;
            stroke-linecap: round;
        }
        .svg-exclamation-dot {
            transform: scale(0);
            transform-origin: 40px 56px;
            animation: scaleDot 0.25s 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }

        @keyframes drawStroke { to { stroke-dashoffset: 0; } }
        @keyframes fadeInDot { to { opacity: 1; } }
        @keyframes scaleDot { from { transform: scale(0); opacity: 1; } to { transform: scale(1); opacity: 1; } }

        .svg-success .svg-circle { stroke: #10b981; }
        .svg-success .svg-check { stroke: #10b981; }
        .svg-error .svg-circle { stroke: #ef4444; }
        .svg-confirm .svg-circle { stroke: #0284c7; }
        .svg-confirm .svg-question { stroke: #0284c7; }
        .svg-confirm .svg-question-dot { fill: #0284c7; }
        .svg-warning .svg-circle { stroke: #f59e0b; }
        .svg-warning .svg-triangle { stroke: #f59e0b; }
        .svg-warning .svg-exclamation-line { stroke: #f59e0b; }
        .svg-warning .svg-exclamation-dot { fill: #f59e0b; }

        /* Responsive Mobile */
        @media (max-width: 991px) {
            .app-sidebar { transform: translateX(-100%); }
            .app-sidebar.sidebar-open { transform: translateX(0); }
            .app-main { margin-left: 0 !important; padding: 20px 14px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .withdraw-hero { padding: 22px 20px; }
            .hero-title { font-size: 1.35rem; }
            .withdraw-card, .dash-card { padding: 20px 16px; }
        }
        @media (max-width: 576px) {
            .stats-grid { grid-template-columns: 1fr; }
        }

        /* Footer */
        .app-footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.84rem;
            color: var(--text-muted);
            flex-wrap: wrap;
            gap: 12px;
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
                <div class="brand-text">
                    ThanhQuy<span>Tech</span>
                </div>
            </a>
        </div>

        <div class="header-right">
            <!-- Nạp tiền nhanh -->
            <a href="/payments/deposit" class="header-balance-card" title="Nạp thêm tiền vào ví">
                <div class="balance-wallet-icon">
                    <i class="fa-solid fa-wallet"></i>
                </div>
                <div class="balance-text-group">
                    <span class="balance-title">Số dư ví</span>
                    <span class="balance-val"><?= number_format((float)$currentUser['balance'], 0, ',', '.') ?> ₫</span>
                </div>
            </a>

            <!-- Avatar & Profile Dropdown -->
            <div class="user-profile-container" id="userDropdownContainer">
                <button type="button" class="user-profile-toggle" id="userProfileToggle" onclick="toggleUserPopup(event)">
                    <img src="<?= htmlspecialchars($currentUser['avatar'] ?: 'assets/images/default-avatar.svg') ?>" 
                         alt="Avatar" 
                         class="user-avatar-small"
                         onerror="this.onerror=null; this.src='assets/images/default-avatar.svg';">
                </button>

                <div class="user-profile-popup" id="userProfilePopup">
                    <div class="d-flex align-items-center gap-3 pb-3 border-bottom mb-2">
                        <img src="<?= htmlspecialchars($currentUser['avatar'] ?: 'assets/images/default-avatar.svg') ?>" 
                             alt="Avatar" 
                             style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid #e0e7ff;"
                             onerror="this.onerror=null; this.src='assets/images/default-avatar.svg';">
                        <div style="min-width: 0; flex-grow: 1;">
                            <div class="fw-bold text-dark text-truncate" style="font-size: 0.95rem;"><?= htmlspecialchars($currentUser['name'] ?: $currentUser['username']) ?></div>
                            <div class="text-muted small text-truncate">@<?= htmlspecialchars(ltrim($currentUser['username'], '@')) ?></div>
                            <span class="badge bg-primary-subtle text-primary mt-1" style="font-size: 0.68rem;">UID: #<?= htmlspecialchars($currentUser['uid']) ?></span>
                        </div>
                    </div>
                    <div class="d-flex flex-column gap-1">
                        <a href="profile.php" class="popup-menu-item">
                            <i class="fa-solid fa-user-gear me-2 text-secondary"></i> Tài khoản của tôi
                        </a>
                        <a href="/payments/deposit" class="popup-menu-item">
                            <i class="fa-solid fa-circle-arrow-down me-2 text-success"></i> Nạp tiền ví
                        </a>
                        <a href="/payments/withdraw" class="popup-menu-item text-primary fw-bold">
                            <i class="fa-solid fa-circle-arrow-up me-2 text-primary"></i> Rút tiền ngân hàng
                        </a>
                        <?php if ($isAdmin): ?>
                        <a href="/admin/dashboard" class="popup-menu-item text-danger fw-bold">
                            <i class="fa-solid fa-shield-halved me-2 text-danger"></i> Quản trị Admin
                        </a>
                        <?php endif; ?>
                        <hr class="my-2 border-secondary-subtle">
                        <a href="logout.php" onclick="closeUserPopup(); confirmLogout(); return false;" class="popup-menu-item text-danger">
                            <i class="fa-solid fa-right-from-bracket me-2"></i> Đăng xuất
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- ==========================================================
     * 2. THANH MENU BÊN TRÁI (SIDEBAR CHUẨN 1:1 DEPOSIT.PHP)
     * ========================================================== -->
    <aside class="app-sidebar" id="appSidebar">
        <div class="sidebar-category">TỔNG QUAN</div>
        <ul class="sidebar-nav-list">
            <li>
                <a href="index.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-house"></i></span>
                    <span class="sidebar-title">Bảng điều khiển</span>
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

            <!-- Payment (Đang mở - Rút tiền active) -->
            <li>
                <button class="sidebar-link" type="button" data-bs-toggle="collapse" data-bs-target="#submenuPayment" aria-expanded="true">
                    <span class="sidebar-icon"><i class="fa-solid fa-fw fa-credit-card"></i></span>
                    <span class="sidebar-title">Payment</span>
                    <i class="fa-solid fa-chevron-down sidebar-arrow"></i>
                </button>
                <div class="collapse show" id="submenuPayment">
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
                            <a href="/payments/withdraw" class="submenu-link active">
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
     * 3. NỘI DUNG CHÍNH (APP MAIN CHUẨN 1:1 DEPOSIT.PHP)
     * ========================================================== -->
    <main class="app-main">
        <div class="content-container">

            <!-- BREADCRUMB -->
            <div class="breadcrumb-custom">
                <a href="index.php"><i class="fa-solid fa-house me-1"></i> Trang chủ</a>
                <i class="fa-solid fa-chevron-right"></i>
                <span>Thanh toán</span>
                <i class="fa-solid fa-chevron-right"></i>
                <span class="text-primary">Rút tiền tài khoản</span>
            </div>

            <!-- HERO BANNER (ĐỒNG BỘ ĐẸP MẮT) -->
            <div class="withdraw-hero">
                <div class="hero-badge">
                    <i class="fa-solid fa-bolt-lightning text-warning"></i> Cổng Rút Tiền Tự Động 24/7
                </div>
                <h1 class="hero-title">Rút Tiền Về Tài Khoản Ngân Hàng</h1>
                <p class="hero-desc">
                    Hệ thống giải ngân tự động 24/7 về số tài khoản ngân hàng hoặc ví điện tử cá nhân của bạn. Miễn phí chuyển khoản 100%, thời gian xử lý nhanh chóng từ 5 đến 30 phút.
                </p>
            </div>

            <!-- 4 THẺ THỐNG KÊ NHANH (CHUẨN 1:1 DEPOSIT.PHP) -->
            <div class="stats-grid">
                <!-- Thẻ 1: Số Dư Khả Dụng -->
                <div class="stat-card card-balance-highlight">
                    <div class="stat-icon" style="background: #ecfdf5; color: #10b981; border: 1.5px solid #a7f3d0; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.15);">
                        <i class="fa-solid fa-wallet"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label" style="color: #15803d;">Số Dư Khả Dụng</div>
                        <div class="stat-val" style="color: #166534;"><?= number_format((float)$currentUser['balance'], 0, ',', '.') ?> ₫</div>
                    </div>
                </div>

                <!-- Thẻ 2: Tổng Tiền Đã Rút -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: #eef2ff; color: #4f46e5; border: 1.5px solid #c7d2fe; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.15);">
                        <i class="fa-solid fa-circle-arrow-up"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Tổng Tiền Đã Rút</div>
                        <div class="stat-val text-primary"><?= number_format($totalWithdrawn, 0, ',', '.') ?> ₫</div>
                    </div>
                </div>

                <!-- Thẻ 3: Lệnh Rút Thành Công -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: #ecfeff; color: #0891b2; border: 1.5px solid #a5f3fc; box-shadow: 0 4px 10px rgba(6, 182, 212, 0.15);">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Giao Dịch Thành Công</div>
                        <div class="stat-val" style="color: #0e7490;"><?= number_format($successCount) ?> đơn</div>
                    </div>
                </div>

                <!-- Thẻ 4: Đang Chờ Giải Ngân -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fffbeb; color: #d97706; border: 1.5px solid #fde68a; box-shadow: 0 4px 10px rgba(217, 119, 6, 0.15);">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Đang Chờ Giải Ngân</div>
                        <div class="stat-val" style="color: #b45309;"><?= number_format($pendingCount) ?> lệnh</div>
                    </div>
                </div>
            </div>

            <!-- KHỐI CHÍNH: FORM TẠO YÊU CẦU RÚT TIỀN (CHUẨN 1:1 DEPOSIT-CARD) -->
            <div class="withdraw-layout">
                <div class="withdraw-card">
                    <div class="card-header-title">
                        <div class="card-title-text">
                            <i class="fa-solid fa-money-bill-transfer text-primary"></i>
                            <span>Tạo Yêu Cầu Rút Tiền</span>
                        </div>
                        <span class="badge bg-success-subtle text-success fw-bold px-3 py-1">
                            <i class="fa-solid fa-shield-halved me-1"></i> Miễn phí giải ngân 100%
                        </span>
                    </div>

                    <form action="<?= htmlspecialchars($redirectRoute) ?>" method="POST" id="withdrawForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="create_withdrawal">
                        <input type="hidden" name="bank_name" id="selectedBankName" value="MBBank (Ngân Hàng Quân Đội)">

                        <!-- BƯỚC 1: CHỌN NGÂN HÀNG THỤ HƯỞNG -->
                        <div class="mb-4">
                            <label for="bankSelect" class="form-label-custom">
                                <i class="fa-solid fa-building-columns text-primary"></i>
                                <span>1. Chọn ngân hàng / ví điện tử nhận tiền:</span> <span class="text-danger">*</span>
                            </label>
                            <select class="form-select-custom" id="bankSelect" name="bank_code" onchange="updateBankSelection(this)" required>
                                <?php foreach ($supportedBanks as $b): ?>
                                    <option value="<?= htmlspecialchars($b['code']) ?>" data-fullname="<?= htmlspecialchars($b['name']) ?>" <?= $b['code'] === 'MB' ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($b['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- BƯỚC 2: NHẬP SỐ TÀI KHOẢN VÀ CHỦ TÀI KHOẢN -->
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="accountNumberInput" class="form-label-custom">
                                    <i class="fa-solid fa-credit-card text-primary"></i>
                                    <span>2. Số tài khoản / Số ví nhận:</span> <span class="text-danger">*</span>
                                </label>
                                <input type="text" 
                                       class="form-control-custom font-monospace" 
                                       id="accountNumberInput" 
                                       name="account_number" 
                                       placeholder="Ví dụ: 10004397102" 
                                       required 
                                       autocomplete="off">
                            </div>
                            <div class="col-md-6">
                                <label for="accountNameInput" class="form-label-custom">
                                    <i class="fa-solid fa-user-check text-primary"></i>
                                    <span>3. Tên chủ tài khoản:</span> <span class="text-danger">*</span>
                                </label>
                                <input type="text" 
                                       class="form-control-custom text-uppercase font-monospace" 
                                       id="accountNameInput" 
                                       name="account_name" 
                                       placeholder="Ví dụ: PHAN THANH QUY" 
                                       required 
                                       autocomplete="off"
                                       oninput="this.value = this.value.toUpperCase()">
                            </div>
                        </div>

                        <!-- BƯỚC 3: NHẬP SỐ TIỀN MUỐN RÚT -->
                        <div class="mb-4">
                            <label for="amountInput" class="form-label-custom">
                                <i class="fa-solid fa-dong-sign text-primary"></i>
                                <span>4. Số tiền muốn rút (VND):</span> <span class="text-danger">*</span>
                            </label>

                            <!-- Các nút chọn nhanh số tiền bo tròn pill đẹp mắt -->
                            <div class="amount-quick-pills">
                                <button type="button" class="amount-pill-btn active" onclick="selectQuickAmount(50000, this)">50.000 ₫</button>
                                <button type="button" class="amount-pill-btn" onclick="selectQuickAmount(100000, this)">100.000 ₫</button>
                                <button type="button" class="amount-pill-btn" onclick="selectQuickAmount(200000, this)">200.000 ₫</button>
                                <button type="button" class="amount-pill-btn" onclick="selectQuickAmount(500000, this)">500.000 ₫</button>
                                <button type="button" class="amount-pill-btn" onclick="selectQuickAmount(1000000, this)">1.000.000 ₫</button>
                                <button type="button" class="amount-pill-btn" onclick="selectQuickAmount(2000000, this)">2.000.000 ₫</button>
                                <button type="button" class="amount-pill-btn text-primary border-primary" onclick="selectQuickAmount(<?= (float)$currentUser['balance'] ?>, this)">
                                    <i class="fa-solid fa-bolt me-1"></i> Rút toàn bộ số dư
                                </button>
                            </div>

                            <!-- Khung nhập tiền cao cấp Fintech -->
                            <div class="premium-amount-box mb-2">
                                <div class="currency-symbol-badge">
                                    <i class="fa-solid fa-dong-sign"></i>
                                </div>
                                <div class="amount-field-inner">
                                    <label for="amountInput" class="amount-field-label">Số tiền muốn rút</label>
                                    <input type="text" 
                                           class="amount-input-control" 
                                           id="amountInput" 
                                           name="amount" 
                                           value="50.000" 
                                           placeholder="50.000" 
                                           required 
                                           autocomplete="off" 
                                           oninput="formatCurrencyInput(this)">
                                </div>
                                <span class="currency-tag-pill">VND</span>
                            </div>

                            <div class="d-flex justify-content-between text-muted small mt-2 px-1">
                                <span><i class="fa-solid fa-circle-info text-primary me-1"></i> Tối thiểu: <strong class="text-dark">50.000 ₫</strong></span>
                                <span>Tối đa: <strong class="text-dark">50.000.000 ₫</strong></span>
                            </div>
                        </div>

                        <!-- GHI CHÚ NẾU CÓ -->
                        <div class="mb-4">
                            <label for="userNoteInput" class="form-label-custom">
                                <i class="fa-regular fa-comment-dots text-secondary"></i>
                                <span>5. Ghi chú rút tiền (Tùy chọn):</span>
                            </label>
                            <input type="text" 
                                   class="form-control-custom" 
                                   id="userNoteInput" 
                                   name="user_note" 
                                   placeholder="Ví dụ: Rút tiền thanh toán, rút tiền hoa hồng...">
                        </div>

                        <!-- NÚT TẠO LỆNH -->
                        <div class="mt-4">
                            <button type="submit" class="btn-gradient-primary">
                                <i class="fa-solid fa-paper-plane fs-5"></i>
                                <span>Tạo Yêu Cầu Rút Tiền & Gửi Duyệt</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ================= BẢNG LỊCH SỬ RÚT TIỀN (CHUẨN 1:1 THEO DEPOSIT.PHP) ================= -->
            <div class="dash-card">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <h4 class="fw-bold text-dark mb-1">
                            <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Lịch Sử Giao Dịch Rút Tiền
                        </h4>
                        <p class="text-muted small mb-0">Theo dõi chi tiết mã đơn rút, ngân hàng thụ hưởng và trạng thái giải ngân tự động của bạn</p>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="history-count-badge">
                            <span class="count-badge-icon">
                                <i class="fa-solid fa-receipt"></i>
                            </span>
                            <span class="count-badge-label">Tổng đơn rút:</span>
                            <span class="count-badge-number"><?= count($withdrawHistory) ?></span>
                        </div>
                        <a href="<?= htmlspecialchars($redirectRoute) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold">
                            <i class="fa-solid fa-arrows-rotate me-1"></i> Làm mới
                        </a>
                    </div>
                </div>

                <?php if (empty($withdrawHistory)): ?>
                    <div class="text-center py-5">
                        <div class="p-3 bg-light rounded-circle d-inline-flex align-items-center justify-content-center text-muted mb-3" style="width: 70px; height: 70px;">
                            <i class="fa-solid fa-receipt fs-2 text-primary opacity-50"></i>
                        </div>
                        <h5 class="fw-bold text-dark">Bạn chưa có đơn rút tiền nào</h5>
                        <p class="text-muted small mb-3">Hãy chọn số tiền và nhập thông tin tài khoản ở phía trên để rút tiền về ngân hàng của bạn.</p>
                        <button type="button" class="btn btn-primary rounded-pill px-4" onclick="window.scrollTo({top: 300, behavior: 'smooth'})">
                            <i class="fa-solid fa-plus me-1"></i> Tạo lệnh rút ngay
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th class="text-center"><i class="fa-solid fa-hashtag text-primary me-1"></i> Mã đơn</th>
                                    <th class="text-center"><i class="fa-solid fa-building-columns text-primary me-1"></i> Ngân hàng thụ hưởng</th>
                                    <th class="text-center"><i class="fa-solid fa-coins text-primary me-1"></i> Số tiền rút</th>
                                    <th class="text-center"><i class="fa-solid fa-hand-holding-dollar text-primary me-1"></i> Thực nhận</th>
                                    <th class="text-center"><i class="fa-regular fa-clock text-primary me-1"></i> Thời gian tạo</th>
                                    <th class="text-center"><i class="fa-solid fa-circle-check text-primary me-1"></i> Trạng thái</th>
                                    <th class="text-center"><i class="fa-solid fa-gear text-primary me-1"></i> Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($withdrawHistory as $item): ?>
                                    <?php 
                                        $st = $item['status'];
                                        $badgeClass = 'badge-status-pending';
                                        $stText = 'Đang chờ duyệt';
                                        $stIcon = 'fa-clock';
                                        if ($st === 'Success') {
                                            $badgeClass = 'badge-status-success';
                                            $stText = 'Đã giải ngân';
                                            $stIcon = 'fa-circle-check';
                                        } elseif ($st === 'Cancelled') {
                                            $badgeClass = 'badge-status-cancelled';
                                            $stText = 'Đã hủy';
                                            $stIcon = 'fa-ban';
                                        } elseif ($st === 'Failed') {
                                            $badgeClass = 'badge-status-failed';
                                            $stText = 'Thất bại';
                                            $stIcon = 'fa-circle-xmark';
                                        }
                                    ?>
                                    <tr>
                                        <td class="text-center">
                                            <strong class="font-monospace text-primary" style="font-size: 0.95rem;">#<?= htmlspecialchars($item['withdraw_code']) ?></strong>
                                        </td>
                                        <td class="text-center">
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($item['bank_name']) ?></div>
                                            <small class="text-muted font-monospace d-block">STK: <?= htmlspecialchars($item['account_number']) ?> (<?= htmlspecialchars($item['account_name']) ?>)</small>
                                        </td>
                                        <td class="text-center">
                                            <span class="fw-bold text-danger">-<?= number_format((float)$item['amount'], 0, ',', '.') ?> ₫</span>
                                        </td>
                                        <td class="text-center">
                                            <span class="fw-bold text-success">+<?= number_format((float)$item['net_amount'], 0, ',', '.') ?> ₫</span>
                                        </td>
                                        <td class="text-center">
                                            <div class="fw-semibold text-dark" style="font-size: 0.85rem;"><?= date('d/m/Y H:i', strtotime($item['created_at'])) ?></div>
                                            <small class="text-muted" style="font-size: 0.75rem;"><?= time_ago($item['created_at']) ?></small>
                                        </td>
                                        <td class="text-center">
                                            <span class="<?= $badgeClass ?>">
                                                <i class="fa-solid <?= $stIcon ?> me-1"></i> <?= $stText ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-inline-flex gap-2 justify-content-center align-items-center flex-wrap">
                                                <?php if ($st === 'Pending'): ?>
                                                    <!-- Khi Đang chờ: Cho phép HỦY đơn và hoàn tiền ngay -->
                                                    <form action="<?= htmlspecialchars($redirectRoute) ?>" method="POST" class="d-inline" id="cancelWithdrawForm_<?= $item['id'] ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="cancel_withdrawal">
                                                        <input type="hidden" name="withdraw_id" value="<?= $item['id'] ?>">
                                                        <button type="button" class="btn-action-cancel" title="Hủy lệnh rút này" onclick="confirmCancelWithdraw('cancelWithdrawForm_<?= $item['id'] ?>', '<?= htmlspecialchars($item['withdraw_code']) ?>', '<?= number_format((float)$item['amount'], 0, ',', '.') ?> ₫')">
                                                            <i class="fa-solid fa-xmark"></i>
                                                            <span>Hủy đơn</span>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <!-- Khi Đã xử lý / Đã hủy: Nút xem Phiếu chi điện tử -->
                                                    <button type="button" 
                                                            class="btn-action-view" 
                                                            title="Xem phiếu chi chi tiết & xuất file PDF" 
                                                            onclick='showWithdrawInvoice(<?= htmlspecialchars(json_encode([
                                                                'code' => $item['withdraw_code'],
                                                                'amount' => (float)$item['amount'],
                                                                'net_amount' => (float)$item['net_amount'],
                                                                'fee' => (float)$item['fee'],
                                                                'amount_formatted' => number_format((float)$item['amount'], 0, ',', '.') . ' ₫',
                                                                'net_amount_formatted' => number_format((float)$item['net_amount'], 0, ',', '.') . ' ₫',
                                                                'bank_name' => $item['bank_name'],
                                                                'account_number' => $item['account_number'],
                                                                'account_name' => $item['account_name'],
                                                                'user_note' => $item['user_note'] ?: 'Rút tiền tài khoản',
                                                                'created_at' => date('d/m/Y H:i:s', strtotime($item['created_at'])),
                                                                'status' => $item['status'],
                                                                'customer_name' => !empty($currentUser['name']) ? $currentUser['name'] : (!empty($currentUser['username']) ? ltrim($currentUser['username'], '@') : 'Khách hàng'),
                                                                'customer_username' => ltrim($currentUser['username'] ?? '', '@'),
                                                                'customer_email' => $currentUser['email'] ?? '',
                                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>)'>
                                                        <i class="fa-solid fa-file-invoice"></i>
                                                        <span>Chi tiết</span>
                                                    </button>
                                                <?php endif; ?>
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

        <!-- FOOTER -->
        <footer class="app-footer">
            <div>
                © <?= date('Y') ?> <strong><?= htmlspecialchars(APP_NAME) ?></strong>. Bản quyền thuộc về hệ thống dịch vụ công nghệ tự động.
            </div>
            <div class="d-flex gap-3">
                <a href="support.php" class="text-muted text-decoration-none small">Hỗ trợ 24/7</a>
                <a href="terms.php" class="text-muted text-decoration-none small">Điều khoản sử dụng</a>
            </div>
        </footer>
    </main>

    <!-- ==========================================================
     * MODAL PHIẾU CHI TIỀN / HÓA ĐƠN RÚT TIỀN (CHUẨN 1:1 DEPOSIT.PHP)
     * ========================================================== -->
    <div class="modal fade" id="depositInvoiceModal" tabindex="-1" aria-labelledby="depositInvoiceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 18px; overflow: hidden;">
                <div class="modal-header bg-light border-0 py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="p-2 rounded-circle bg-primary bg-opacity-10 text-primary">
                            <i class="fa-solid fa-file-invoice fs-5"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-dark mb-0" id="depositInvoiceModalLabel">Phiếu Chi Tiền Điện Tử</h5>
                            <small class="text-muted">Chứng từ xác thực giải ngân chính thức</small>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-danger rounded-pill px-3 fw-bold shadow-sm" onclick="exportCurrentInvoiceToPdf()">
                            <i class="fa-solid fa-file-pdf me-1"></i> Xuất file PDF
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-bold" onclick="printCurrentInvoice()">
                            <i class="fa-solid fa-print me-1"></i> In phiếu chi
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body p-3 p-md-4 bg-light bg-opacity-50">
                    <div class="invoice-card" id="invoiceCardPrintArea">
                        <!-- 1. Quốc hiệu - Tiêu ngữ & Thông tin Công ty (Tối ưu gọn gàng) -->
                        <div class="d-flex justify-content-between align-items-start pb-2 border-bottom" style="gap: 15px;">
                            <div class="text-start" style="flex: 1 1 auto; min-width: 0;">
                                <div class="fw-bold text-primary text-uppercase" style="font-size: 0.88rem; letter-spacing: 0.3px; line-height: 1.25; margin-bottom: 2px;">
                                    CÔNG TY TNHH CÔNG NGHỆ SỐ THÀNH QUÝ TECH
                                </div>
                                <div style="font-size: 0.76rem; color: #475569; margin-bottom: 2px;">Mã số thuế (Tax Code): <strong class="text-dark">0318954321</strong></div>
                                <div style="font-size: 0.75rem; color: #475569; margin-bottom: 2px;">Địa chỉ: Tầng 12, Tòa nhà Công Nghệ Số, P. Bến Nghé, Quận 1, TP. Hồ Chí Minh</div>
                                <div style="font-size: 0.75rem; color: #475569;">SĐT: <strong>0355879036</strong> | Website: <strong id="invCompanyWebsite"><?= htmlspecialchars($dynamicHost) ?></strong></div>
                            </div>
                            <div class="text-end" style="flex: 0 0 auto; white-space: nowrap;">
                                <div class="fw-bold text-uppercase text-dark" style="font-size: 0.82rem; letter-spacing: 0.3px;">
                                    CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM
                                </div>
                                <div class="fw-bold text-dark" style="font-size: 0.8rem; margin-top: 2px;">
                                    Độc lập - Tự do - Hạnh phúc
                                </div>
                                <div style="width: 120px; height: 1.5px; background: #0f172a; margin: 3px 0 3px auto;"></div>
                                <div class="text-muted fst-italic" style="font-size: 0.74rem;" id="invModalLocationDate">
                                    TP. Hồ Chí Minh, ngày ... tháng ... năm ...
                                </div>
                            </div>
                        </div>

                        <!-- 2. Tiêu đề Phiếu Chi & Barcode Siêu Thị Chuẩn Siêu Nét -->
                        <div class="text-center my-2">
                            <div class="fw-bold text-dark text-uppercase" style="font-size: 1.02rem; letter-spacing: 0.8px; margin-bottom: 1px;">
                                PHIẾU CHI TIỀN / GIẢI NGÂN TÀI KHOẢN
                            </div>
                            <div class="text-muted" style="font-size: 0.72rem; margin-bottom: 3px;">(Bản thể hiện chứng từ điện tử phục vụ hạch toán & đối soát doanh nghiệp)</div>
                            <div class="d-flex justify-content-center align-items-center gap-2 text-muted" style="font-size: 0.76rem; margin-bottom: 3px;">
                                <span>Ký hiệu (Serial): <strong class="text-dark">TQ/26PC</strong></span>
                                <span>|</span>
                                <span>Số chứng từ (No.): <strong class="text-primary font-monospace" style="font-size: 0.84rem;" id="invModalCode">#---</strong></span>
                                <span>|</span>
                                <span id="invModalStatusBadge"><span class="badge bg-success text-white px-2 py-0 rounded-pill">ĐÃ HOÀN THÀNH</span></span>
                            </div>
                            
                            <!-- Mã vạch Barcode siêu thị: Canvas PNG nét chuẩn, không số bên dưới -->
                            <div class="invoice-barcode-wrap">
                                <div class="text-muted fw-bold" style="font-size: 0.65rem; letter-spacing: 0.8px; margin-bottom: 1px;">MÃ VẠCH TRA CỨU ĐỐI SOÁT (RETAIL BARCODE)</div>
                                <div id="invModalBarcodeSvg" style="line-height: 0;"></div>
                            </div>
                        </div>

                        <!-- 3. Thông tin Người nhận & Thụ hưởng (Chia 2 cột rõ nét) -->
                        <div class="invoice-meta-grid">
                            <div class="meta-col">
                                <div class="meta-title"><i class="fa-solid fa-user me-1"></i> NGƯỜI NHẬN TIỀN / KHÁCH HÀNG</div>
                                <table class="meta-table">
                                    <tr>
                                        <td class="meta-label">Họ và tên:</td>
                                        <td class="meta-val fw-bold" id="invModalCustomerName">...</td>
                                    </tr>
                                    <tr>
                                        <td class="meta-label">Tài khoản:</td>
                                        <td class="meta-val font-monospace" id="invModalCustomerUsername">@...</td>
                                    </tr>
                                    <tr>
                                        <td class="meta-label">Email:</td>
                                        <td class="meta-val" id="invModalCustomerEmail">...</td>
                                    </tr>
                                </table>
                            </div>
                            <div class="meta-col">
                                <div class="meta-title"><i class="fa-solid fa-building-columns me-1"></i> THÔNG TIN TÀI KHOẢN THỤ HƯỞNG</div>
                                <table class="meta-table">
                                    <tr>
                                        <td class="meta-label">Ngân hàng:</td>
                                        <td class="meta-val fw-bold" id="invModalBankName">...</td>
                                    </tr>
                                    <tr>
                                        <td class="meta-label">Số tài khoản:</td>
                                        <td class="meta-val fw-bold font-monospace text-primary" id="invModalAccountNumber">...</td>
                                    </tr>
                                    <tr>
                                        <td class="meta-label">Chủ tài khoản:</td>
                                        <td class="meta-val fw-bold text-uppercase text-dark" id="invModalAccountName">...</td>
                                    </tr>
                                    <tr>
                                        <td class="meta-label">Nội dung chi:</td>
                                        <td class="meta-val" id="invModalTransferContent">...</td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- 4. Bảng Kê Chi Tiết Khoản Chi Giải Ngân -->
                        <table class="invoice-table">
                            <thead>
                                <tr>
                                    <th style="width: 8%;">STT</th>
                                    <th style="width: 47%; text-align: left !important; padding-left: 10px !important;">Nội dung thanh toán / Giải ngân</th>
                                    <th style="width: 15%;">Số tiền rút</th>
                                    <th style="width: 15%;">Phí giao dịch</th>
                                    <th style="width: 15%;">Thực nhận</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td style="text-align: center;">1</td>
                                    <td style="text-align: left; padding-left: 10px;">
                                        <div class="fw-bold text-dark">Rút tiền từ số dư ví hệ thống về tài khoản cá nhân</div>
                                        <div class="text-muted" style="font-size: 0.72rem;">Giải ngân chuyển khoản nhanh Napas 24/7</div>
                                    </td>
                                    <td style="text-align: right;" class="font-monospace fw-bold" id="invModalUnitPrice">0 ₫</td>
                                    <td style="text-align: right;" class="font-monospace fw-bold text-success">0 ₫</td>
                                    <td style="text-align: right;" class="font-monospace fw-bold text-success" id="invModalAmountItem">0 ₫</td>
                                </tr>
                                <tr>
                                    <td colspan="4" style="text-align: right; font-weight: 700;">Cộng tiền chi (Sub Total):</td>
                                    <td style="text-align: right;" class="font-monospace fw-bold text-dark" id="invModalSubTotal">0 ₫</td>
                                </tr>
                                <tr>
                                    <td colspan="4" style="text-align: right; font-weight: 700;">Phí dịch vụ rút tiền:</td>
                                    <td style="text-align: right;" class="font-monospace fw-bold text-success">0 ₫ (Miễn phí)</td>
                                </tr>
                                <tr style="background: #f8fafc;">
                                    <td colspan="4" style="text-align: right; font-weight: 800; color: #b91c1c; text-transform: uppercase;">TỔNG TIỀN THỰC CHI (NET AMOUNT):</td>
                                    <td style="text-align: right; font-size: 0.88rem;" class="font-monospace fw-bold text-danger" id="invModalTotalAmount">0 ₫</td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Số tiền viết bằng chữ -->
                        <div class="p-2 border rounded bg-light mb-2 text-start" style="font-size: 0.76rem;">
                            <span class="text-muted">Số tiền viết bằng chữ:</span>
                            <strong class="text-dark fst-italic ms-1" id="invModalAmountWords">Không đồng chẵn.</strong>
                        </div>

                        <!-- 5. Ký tên & Đóng dấu điện tử (Giữ tỷ lệ chuẩn như deposit.php) -->
                        <div class="row text-center mt-2" style="font-size: 0.78rem;">
                            <div class="col-6">
                                <div class="fw-bold text-uppercase text-dark">NGƯỜI NHẬN TIỀN</div>
                                <div class="text-muted" style="font-size: 0.7rem; font-style: italic;">(Ký, ghi rõ họ tên)</div>
                                
                                <div style="height: 65px; display: flex; align-items: center; justify-content: center;" class="mt-1">
                                    <span class="badge bg-light text-muted border px-2 py-1" style="font-size: 0.7rem;">Xác thực tài khoản hệ thống</span>
                                </div>

                                <div class="fw-bold text-dark mt-1" id="invModalSignCustomerName" style="font-size: 0.86rem;">...</div>
                                <div class="text-muted" style="font-size: 0.72rem;">Chủ tài khoản thụ hưởng</div>
                            </div>

                            <div class="col-6 position-relative">
                                <div class="fw-bold text-uppercase text-dark">NGƯỜI ĐẠI DIỆN PHÁP LUẬT / ĐƠN VỊ CHI TRẢ</div>
                                <div class="text-muted" style="font-size: 0.7rem; font-style: italic;">(Ký số & đóng dấu điện tử)</div>
                                
                                <div style="height: 65px; position: relative; display: flex; align-items: center; justify-content: center;" class="mt-1">
                                    <!-- Con dấu đỏ chuẩn vector SVG Thành Quý Tech -->
                                    <div class="red-stamp-seal">
                                        <svg viewBox="0 0 160 160" width="105" height="105">
                                            <circle cx="80" cy="80" r="74" fill="none" stroke="#dc2626" stroke-width="3" />
                                            <circle cx="80" cy="80" r="70" fill="none" stroke="#dc2626" stroke-width="1" />
                                            <circle cx="80" cy="80" r="67" fill="none" stroke="#dc2626" stroke-width="1.5" stroke-dasharray="3,3" />
                                            
                                            <path id="curveTopWd" d="M 18,80 A 62,62 0 0,1 142,80" fill="none" />
                                            <text font-size="8.8" font-weight="900" fill="#dc2626" letter-spacing="1.2">
                                                <textPath href="#curveTopWd" startOffset="50%" text-anchor="middle">
                                                    ★ CÔNG TY TNHH CÔNG NGHỆ SỐ THÀNH QUÝ TECH ★
                                                </textPath>
                                            </text>
                                            
                                            <path id="curveBottomWd" d="M 138,80 A 58,58 0 0,1 22,80" fill="none" />
                                            <text font-size="8.5" font-weight="700" fill="#dc2626" letter-spacing="1">
                                                <textPath href="#curveBottomWd" startOffset="50%" text-anchor="middle">
                                                    ★ MST: 0318954321 ★ TP. HỒ CHÍ MINH
                                                </textPath>
                                            </text>
                                            
                                            <polygon points="80,57 82.5,63.5 89.5,63.5 84,68 86,74.5 80,70.5 74,74.5 76,68 70.5,63.5 77.5,63.5" fill="#dc2626" />
                                            <text x="80" y="91" font-size="10" font-weight="900" fill="#dc2626" text-anchor="middle" letter-spacing="0.5">ĐÃ XÁC NHẬN</text>
                                            <text x="80" y="103" font-size="8" font-weight="700" fill="#dc2626" text-anchor="middle">THANH TOÁN</text>
                                        </svg>
                                    </div>

                                    <!-- Chữ ký thật trong suốt của Phan Thành Quý -->
                                    <div style="position: relative; z-index: 2;" id="invModalSignatureImgContainer"></div>
                                </div>

                                <div class="fw-bold text-dark mt-1" style="font-size: 0.86rem;">Phan Thành Quý</div>
                                <div class="text-muted" style="font-size: 0.72rem;">Giám đốc điều hành / Người đại diện pháp luật</div>
                            </div>
                        </div>

                        <!-- 6. Footer Lời Cảm Ơn -->
                        <div class="mt-2 pt-2 border-top text-center text-muted" style="font-size: 0.72rem;">
                            <div><i class="fa-solid fa-shield-halved text-success me-1"></i> Chứng từ chi tiền điện tử khởi tạo hợp pháp theo quy định của pháp luật Việt Nam.</div>
                            <div>Mọi thắc mắc xin liên hệ SĐT: 0355879036 | Website: <span class="fw-semibold text-dark"><?= htmlspecialchars($dynamicHost) ?></span>. Cảm ơn quý khách!</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-2 px-4 justify-content-between">
                    <span class="text-muted small"><i class="fa-solid fa-shield-halved text-success me-1"></i> Chứng từ giải ngân bảo mật & xác thực</span>
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ==========================================================
     * DIALOG SVG ANIMATION (THÔNG BÁO & XÁC NHẬN ĐỒNG BỘ 1:1)
     * ========================================================== -->
    <div class="svg-dialog-overlay" id="svgDialogOverlay">
        <div class="svg-dialog-box">
            <div class="svg-icon-container" id="svgDialogIcon"></div>
            <h4 class="fw-bold mb-2 text-dark" id="svgDialogTitle">Thông báo</h4>
            <div class="text-muted small mb-4" id="svgDialogMessage"></div>
            <div class="d-flex justify-content-center gap-2" id="svgDialogActions">
                <button type="button" class="btn btn-primary px-4 rounded-pill" onclick="closeSvgDialog()">Xác nhận</button>
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
            const sidebar = document.getElementById('appSidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            if (sidebar) sidebar.classList.remove('sidebar-open');
            if (backdrop) backdrop.classList.remove('active');
        }

        // 2. User Popup Menu
        function toggleUserPopup(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            const popup = document.getElementById('userProfilePopup');
            if (popup) popup.classList.toggle('active');
        }

        function closeUserPopup() {
            const popup = document.getElementById('userProfilePopup');
            if (popup) popup.classList.remove('active');
        }

        document.addEventListener('click', function (e) {
            const container = document.getElementById('userDropdownContainer');
            if (container && !container.contains(e.target)) {
                closeUserPopup();
            }
        });

        // 3. Cập nhật tên ngân hàng thụ hưởng khi chọn select
        function updateBankSelection(select) {
            const selectedOpt = select.options[select.selectedIndex];
            const fullName = selectedOpt.getAttribute('data-fullname') || selectedOpt.text;
            document.getElementById('selectedBankName').value = fullName;
        }

        // 4. Nút chọn nhanh số tiền
        function selectQuickAmount(amount, btn) {
            document.querySelectorAll('.amount-pill-btn').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');
            var input = document.getElementById('amountInput');
            if (input) {
                input.value = new Intl.NumberFormat('vi-VN').format(amount);
            }
        }

        // 5. Định dạng tiền tệ trong ô nhập
        function formatCurrencyInput(input) {
            let val = input.value.replace(/\D/g, '');
            if (val === '') {
                input.value = '';
                return;
            }
            let formatted = new Intl.NumberFormat('vi-VN').format(val);
            input.value = formatted;
        }

        // ==========================================================
        // 6. DIALOG XÁC NHẬN HIỆU ỨNG SVG STROKE DRAW ANIMATION
        // ==========================================================
        const SVG_TEMPLATES = {
            success: `
                <svg class="svg-draw-icon svg-success" viewBox="0 0 80 80">
                    <circle class="svg-circle" cx="40" cy="40" r="34" />
                    <polyline class="svg-check" points="24,42 35,53 56,28" />
                </svg>
            `,
            error: `
                <svg class="svg-draw-icon svg-error" viewBox="0 0 80 80">
                    <circle class="svg-circle" cx="40" cy="40" r="34" />
                    <line class="line-1" x1="26" y1="26" x2="54" y2="54" />
                    <line class="line-2" x1="54" y1="26" x2="26" y2="54" />
                </svg>
            `,
            confirm: `
                <svg class="svg-draw-icon svg-confirm" viewBox="0 0 80 80">
                    <circle class="svg-circle" cx="40" cy="40" r="34" />
                    <path class="svg-question" d="M30,30 C30,22 50,22 50,32 C50,40 40,42 40,48" />
                    <circle class="svg-question-dot" cx="40" cy="56" r="3" />
                </svg>
            `,
            warning: `
                <svg class="svg-draw-icon svg-warning" viewBox="0 0 80 80">
                    <circle class="svg-circle" cx="40" cy="40" r="34" />
                    <line class="svg-exclamation-line" x1="40" y1="24" x2="40" y2="46" stroke="#f59e0b" stroke-width="4.5" stroke-linecap="round" />
                    <circle class="svg-exclamation-dot" cx="40" cy="56" r="3" fill="#f59e0b" />
                </svg>
            `
        };

        function closeSvgDialog() {
            const overlay = document.getElementById('svgDialogOverlay');
            if (overlay) overlay.classList.remove('active');
        }

        function showSvgAlert(message, title = 'Thông báo', type = 'success', onClose = null) {
            const overlay = document.getElementById('svgDialogOverlay');
            const iconEl = document.getElementById('svgDialogIcon');
            const titleEl = document.getElementById('svgDialogTitle');
            const messageEl = document.getElementById('svgDialogMessage');
            const actionsEl = document.getElementById('svgDialogActions');

            if (!overlay) return;

            let mappedType = type;
            if (type === 'danger') mappedType = 'error';
            if (type === 'info') mappedType = 'confirm';
            if (!SVG_TEMPLATES[mappedType]) mappedType = 'success';

            iconEl.innerHTML = SVG_TEMPLATES[mappedType];
            titleEl.textContent = title;
            messageEl.innerHTML = message;

            let btnClass = 'btn-primary';
            if (mappedType === 'error') btnClass = 'btn-danger';
            else if (mappedType === 'warning') btnClass = 'btn-warning text-dark';

            actionsEl.innerHTML = `
                <button type="button" class="btn ${btnClass} px-4 rounded-pill" id="svgCloseBtn">
                    <i class="fa-solid fa-check me-1"></i> Xác nhận
                </button>
            `;

            overlay.classList.add('active');
            document.getElementById('svgCloseBtn').onclick = () => {
                overlay.classList.remove('active');
                if (typeof onClose === 'function') onClose();
            };
        }

        function showSvgConfirm(message, title = 'Xác nhận', onConfirm = null, onCancel = null, confirmText = 'Xác nhận', confirmClass = 'btn-primary') {
            const overlay = document.getElementById('svgDialogOverlay');
            const iconEl = document.getElementById('svgDialogIcon');
            const titleEl = document.getElementById('svgDialogTitle');
            const messageEl = document.getElementById('svgDialogMessage');
            const actionsEl = document.getElementById('svgDialogActions');

            if (!overlay) return;

            iconEl.innerHTML = SVG_TEMPLATES.confirm;
            titleEl.textContent = title;
            messageEl.innerHTML = message;
            actionsEl.innerHTML = `
                <button type="button" class="btn btn-light border px-4 rounded-pill" id="svgCancelBtn">Hủy bỏ</button>
                <button type="button" class="btn ${confirmClass} px-4 rounded-pill" id="svgConfirmBtn">${confirmText}</button>
            `;

            overlay.classList.add('active');

            document.getElementById('svgCancelBtn').onclick = () => {
                overlay.classList.remove('active');
                if (typeof onCancel === 'function') onCancel();
            };

            document.getElementById('svgConfirmBtn').onclick = () => {
                overlay.classList.remove('active');
                if (typeof onConfirm === 'function') onConfirm();
            };
        }

        function confirmCancelWithdraw(formId, code, amountFormatted) {
            showSvgConfirm(
                `Bạn có chắc chắn muốn hủy yêu cầu rút tiền <strong>#${code}</strong> không?<br><span class="text-success small mt-2 d-block"><i class="fa-solid fa-rotate-left me-1"></i> Số tiền <strong>${amountFormatted}</strong> sẽ được hoàn trả ngay lập tức vào số dư ví của bạn.</span>`,
                'Xác nhận hủy lệnh rút',
                () => {
                    const form = document.getElementById(formId);
                    if (form) form.submit();
                },
                null,
                '<i class="fa-solid fa-ban me-1"></i> Hủy lệnh ngay',
                'btn-danger'
            );
        }

        function confirmLogout() {
            showSvgConfirm(
                'Bạn có chắc chắn muốn đăng xuất khỏi hệ thống không?',
                'Xác nhận đăng xuất',
                () => {
                    window.location.href = 'logout.php';
                },
                null,
                '<i class="fa-solid fa-right-from-bracket me-1"></i> Đăng xuất',
                'btn-danger'
            );
        }

        // Bắt sự kiện tạo lệnh rút tiền với kiểm tra hạn mức bằng SVG Dialog
        const userBalance = <?= (float)$currentUser['balance'] ?>;
        const withdrawFormEl = document.getElementById('withdrawForm');
        if (withdrawFormEl) {
            withdrawFormEl.addEventListener('submit', function(e) {
                const accNum = document.getElementById('accountNumberInput').value.trim();
                const accName = document.getElementById('accountNameInput').value.trim();
                const amtInput = document.getElementById('amountInput');
                const rawVal = parseInt(amtInput.value.replace(/\D/g, ''), 10) || 0;

                if (!accNum || accNum.length < 5) {
                    e.preventDefault();
                    showSvgAlert('Vui lòng nhập chính xác số tài khoản hoặc số ví nhận tiền.', 'Số tài khoản không hợp lệ', 'warning');
                    return false;
                }

                if (!accName || accName.length < 3) {
                    e.preventDefault();
                    showSvgAlert('Vui lòng nhập họ và tên chủ tài khoản nhận tiền.', 'Tên chủ tài khoản trống', 'warning');
                    return false;
                }

                if (rawVal < 50000) {
                    e.preventDefault();
                    showSvgAlert('Số tiền rút tối thiểu là <strong>50.000 ₫</strong>. Vui lòng kiểm tra lại!', 'Số tiền không đủ hạn mức', 'warning');
                    return false;
                }

                if (rawVal > userBalance) {
                    e.preventDefault();
                    showSvgAlert(`Số dư ví hiện có của bạn là <strong>${new Intl.NumberFormat('vi-VN').format(userBalance)} ₫</strong>, không đủ để rút số tiền này.`, 'Số dư không đủ', 'warning');
                    return false;
                }

                if (rawVal > 50000000) {
                    e.preventDefault();
                    showSvgAlert('Hạn mức rút tiền tối đa là <strong>50.000.000 ₫</strong> trên mỗi giao dịch.', 'Vượt quá hạn mức', 'warning');
                    return false;
                }
            });
        }

        // ==========================================================
        // 7. XỬ LÝ HIỂN THỊ PHIẾU CHI ĐIỆN TỬ & XUẤT PDF / IN A4
        // ==========================================================
        const USER_SIGNATURE_BASE64 = <?= json_encode($signatureBase64) ?>;
        let currentInvoiceData = null;

        function docSoTien(so) {
            if (!so || so == 0) return 'Không đồng chẵn.';
            const ChuSo = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];
            const Tien = ['', ' nghìn', ' triệu', ' tỷ', ' nghìn tỷ', ' triệu tỷ'];
            let soTien = Math.abs(parseInt(so, 10));
            if (isNaN(soTien)) return 'Không đồng chẵn.';

            function doc3So(baso) {
                let tram = Math.floor(baso / 100);
                let chuc = Math.floor((baso % 100) / 10);
                let donvi = baso % 10;
                let kq = '';
                if (tram === 0 && chuc === 0 && donvi === 0) return '';
                if (tram !== 0) {
                    kq += ChuSo[tram] + ' trăm ';
                    if (chuc === 0 && donvi !== 0) kq += 'lẻ ';
                }
                if (chuc !== 0 && chuc !== 1) {
                    kq += ChuSo[chuc] + ' mươi';
                    if (chuc === 0 && donvi !== 0) kq += ' linh ';
                }
                if (chuc === 1) kq += 'mười';
                switch (donvi) {
                    case 1:
                        if (chuc > 1) kq += ' mốt';
                        else kq += ' một';
                        break;
                    case 5:
                        if (chuc === 0) kq += ' năm';
                        else kq += ' lăm';
                        break;
                    default:
                        if (donvi !== 0) kq += ' ' + ChuSo[donvi];
                        break;
                }
                return kq.trim();
            }

            let viTri = [];
            let temp = soTien;
            while (temp > 0) {
                viTri.push(temp % 1000);
                temp = Math.floor(temp / 1000);
            }
            let ketQua = '';
            for (let j = viTri.length - 1; j >= 0; j--) {
                let doc = doc3So(viTri[j]);
                if (doc !== '') {
                    ketQua += doc + Tien[j] + ' ';
                }
            }
            ketQua = ketQua.trim();
            if (!ketQua) return 'Không đồng chẵn.';
            return ketQua.charAt(0).toUpperCase() + ketQua.slice(1) + ' đồng chẵn.';
        }

        function generateBarcodeSvg(code) {
            const seed = (code || 'RUT5411442').toString().replace(/\D/g, '') + '928475';
            const patterns = [
                '11011001100', '11001101100', '11001100110', '10010011000', '10010001100',
                '10001001100', '10011001000', '10011000100', '10001100100', '11001001000'
            ];
            let bitString = '11010010000';
            for (let i = 0; i < 7; i++) {
                const charCode = seed.charCodeAt(i % seed.length) % patterns.length;
                bitString += patterns[charCode];
            }
            bitString += '1100011101011';
            
            try {
                const canvas = document.createElement('canvas');
                const scale = 2;
                const barW = 2;
                const h = 26;
                const quiet = 10;
                const width = (quiet * 2) + (bitString.length * barW);
                
                canvas.width = width * scale;
                canvas.height = h * scale;
                const ctx = canvas.getContext('2d');
                ctx.scale(scale, scale);
                
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, width, h);
                
                ctx.fillStyle = '#0f172a';
                for (let i = 0; i < bitString.length; i++) {
                    if (bitString[i] === '1') {
                        ctx.fillRect(quiet + i * barW, 0, barW, h);
                    }
                }
                
                const pngUrl = canvas.toDataURL('image/png');
                return `<img src="${pngUrl}" alt="Retail Barcode ${code}" class="invoice-barcode-img" style="width: 180px; height: 24px; display: inline-block; image-rendering: pixelated; vertical-align: middle;" />`;
            } catch (e) {
                return '';
            }
        }

        function getVietnameseStatus(status) {
            const s = (status || '').toLowerCase();
            if (s === 'success') {
                return { text: 'ĐÃ HOÀN THÀNH', badgeClass: 'bg-success text-white', color: '#16a34a', icon: 'fa-circle-check' };
            }
            if (s === 'pending') {
                return { text: 'ĐANG CHỜ DUYỆT', badgeClass: 'bg-warning text-dark', color: '#d97706', icon: 'fa-clock' };
            }
            if (s === 'cancelled') {
                return { text: 'ĐÃ HỦY', badgeClass: 'bg-secondary text-white', color: '#64748b', icon: 'fa-ban' };
            }
            return { text: 'THẤT BẠI', badgeClass: 'bg-danger text-white', color: '#dc2626', icon: 'fa-circle-xmark' };
        }

        function showWithdrawInvoice(data) {
            if (!data) return;
            currentInvoiceData = data;

            // 1. Mã đơn & Địa điểm ngày tháng
            const codeEl = document.getElementById('invModalCode');
            if (codeEl) codeEl.innerText = '#' + data.code;

            const locDateEl = document.getElementById('invModalLocationDate');
            if (locDateEl) {
                if (data.created_at) {
                    try {
                        const parts = data.created_at.split(' ');
                        const datePart = parts[0];
                        if (datePart.includes('/')) {
                            const [d, m, y] = datePart.split('/');
                            locDateEl.innerText = `TP. Hồ Chí Minh, ngày ${d} tháng ${m} năm ${y}`;
                        } else {
                            locDateEl.innerText = 'TP. Hồ Chí Minh, ' + data.created_at;
                        }
                    } catch(e) {
                        locDateEl.innerText = 'TP. Hồ Chí Minh, ' + data.created_at;
                    }
                } else {
                    locDateEl.innerText = 'TP. Hồ Chí Minh, ngày ... tháng ... năm ...';
                }
            }

            // 2. Trạng thái badge
            const statusEl = document.getElementById('invModalStatusBadge');
            if (statusEl) {
                const vStatus = getVietnameseStatus(data.status);
                statusEl.innerHTML = `<span class="badge ${vStatus.badgeClass} px-2 py-0 rounded-pill fw-bold" style="font-size: 0.72rem;"><i class="fa-solid ${vStatus.icon} me-1"></i> ${vStatus.text}</span>`;
            }

            // 3. Thông tin người nhận
            const cleanUsername = (data.customer_username || '').replace(/^@+/, '');
            const customerName = data.customer_name || cleanUsername;

            document.getElementById('invModalCustomerName').innerText = customerName;
            document.getElementById('invModalCustomerUsername').innerText = '@' + cleanUsername;
            document.getElementById('invModalCustomerEmail').innerText = data.customer_email || 'Chưa cập nhật';
            document.getElementById('invModalSignCustomerName').innerText = customerName;

            // Cập nhật website động
            const webEl = document.getElementById('invCompanyWebsite');
            if (webEl) webEl.innerText = window.location.host || '<?= htmlspecialchars($dynamicHost) ?>';

            // 4. Thông tin ngân hàng thụ hưởng
            document.getElementById('invModalBankName').innerText = data.bank_name;
            document.getElementById('invModalAccountNumber').innerText = data.account_number;
            document.getElementById('invModalAccountName').innerText = data.account_name;
            document.getElementById('invModalTransferContent').innerText = data.user_note || 'Giải ngân rút tiền';

            // 5. Số tiền chi tiết
            document.getElementById('invModalUnitPrice').innerText = data.amount_formatted;
            document.getElementById('invModalAmountItem').innerText = data.net_amount_formatted;
            document.getElementById('invModalSubTotal').innerText = data.net_amount_formatted;
            document.getElementById('invModalTotalAmount').innerText = data.net_amount_formatted;
            document.getElementById('invModalAmountWords').innerText = docSoTien(data.net_amount);

            // 6. Chữ ký thật trong suốt của Phan Thành Quý
            const sigBox = document.getElementById('invModalSignatureImgContainer');
            if (sigBox) {
                if (USER_SIGNATURE_BASE64) {
                    sigBox.innerHTML = `<img src="${USER_SIGNATURE_BASE64}" alt="Chữ ký Phan Thành Quý" class="signature-stroke-img" />`;
                } else {
                    sigBox.innerHTML = `<span style="font-family: 'Brush Script MT', cursive; font-size: 22px; color: #1d4ed8; font-weight: bold;">Phan Thành Quý</span>`;
                }
            }

            // 7. Barcode siêu thị
            const barcodeBox = document.getElementById('invModalBarcodeSvg');
            if (barcodeBox) barcodeBox.innerHTML = generateBarcodeSvg(data.code);

            // 8. Mở Modal
            const modalEl = document.getElementById('depositInvoiceModal');
            if (modalEl) {
                let modalInstance = bootstrap.Modal.getInstance(modalEl);
                if (!modalInstance) modalInstance = new bootstrap.Modal(modalEl);
                modalInstance.show();
            }
        }

        // Xuất hóa đơn ra file PDF chuẩn Full-Width 1:1, khớp tuyệt đối 1 trang A4 duy nhất
        function exportCurrentInvoiceToPdf() {
            if (!currentInvoiceData) return;
            const data = currentInvoiceData;
            const element = document.getElementById('invoiceCardPrintArea');
            if (!element) return;

            const modalBody = document.querySelector('#depositInvoiceModal .modal-body');
            if (modalBody) modalBody.scrollTop = 0;

            const getJsPdfClass = () => {
                if (typeof window.jspdf !== 'undefined' && window.jspdf.jsPDF) return window.jspdf.jsPDF;
                if (typeof window.jsPDF === 'function') return window.jsPDF;
                return null;
            };

            const jsPdfClass = getJsPdfClass();

            if (typeof html2canvas !== 'undefined' && jsPdfClass) {
                html2canvas(element, {
                    scale: 2.5,
                    useCORS: true,
                    logging: false,
                    backgroundColor: '#ffffff'
                }).then(canvas => {
                    try {
                        const pdf = new jsPdfClass('p', 'mm', 'a4');
                        const pageWidth = pdf.internal.pageSize.getWidth();
                        const margin = 8;
                        const imgWidth = pageWidth - (margin * 2);
                        const imgHeight = (canvas.height * imgWidth) / canvas.width;
                        
                        const imgData = canvas.toDataURL('image/jpeg', 0.98);
                        pdf.addImage(imgData, 'JPEG', margin, margin, imgWidth, imgHeight);
                        pdf.save(`Phieu_Chi_${data.code}.pdf`);

                        showSvgAlert('Đã kết xuất và lưu phiếu chi giải ngân chuẩn A4 Full-Width về thiết bị thành công!', 'Xuất file PDF thành công', 'success');
                    } catch (e) {
                        console.error('jsPDF generation error:', e);
                        window.print();
                    }
                }).catch(err => {
                    console.error('html2canvas error:', err);
                    window.print();
                });
            } else {
                window.print();
            }
        }

        // In qua iframe ẩn chuyên dụng để triệt tiêu hoàn toàn trang trắng thừa
        function printCurrentInvoice() {
            const card = document.getElementById('invoiceCardPrintArea');
            if (!card) {
                window.print();
                return;
            }

            let printFrame = document.getElementById('invoicePrintIframe');
            if (!printFrame) {
                printFrame = document.createElement('iframe');
                printFrame.id = 'invoicePrintIframe';
                printFrame.style.position = 'fixed';
                printFrame.style.right = '0';
                printFrame.style.bottom = '0';
                printFrame.style.width = '0';
                printFrame.style.height = '0';
                printFrame.style.border = '0';
                printFrame.style.visibility = 'hidden';
                document.body.appendChild(printFrame);
            }

            const doc = printFrame.contentWindow.document;
            doc.open();
            doc.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <title>Phieu_Chi_${currentInvoiceData ? currentInvoiceData.code : ''}</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        @page { size: A4 portrait; margin: 6mm 8mm; }
                        * { box-sizing: border-box; }
                        html, body {
                            margin: 0;
                            padding: 0;
                            background: #ffffff;
                            font-family: system-ui, -apple-system, sans-serif;
                            -webkit-print-color-adjust: exact !important;
                            print-color-adjust: exact !important;
                        }
                        .invoice-card {
                            background: #ffffff !important;
                            border: 1px solid #cbd5e1 !important;
                            border-radius: 8px !important;
                            padding: 14px 18px !important;
                            color: #0f172a !important;
                            width: 100% !important;
                            margin: 0 !important;
                            font-size: 0.82rem !important;
                            line-height: 1.35 !important;
                        }
                        .invoice-barcode-wrap {
                            text-align: center;
                            background: #f8fafc;
                            border: 1px solid #e2e8f0;
                            border-radius: 6px;
                            padding: 4px 10px;
                            margin: 4px auto 6px auto;
                            max-width: 260px;
                        }
                        .invoice-barcode-img {
                            width: 180px;
                            height: 24px;
                            display: inline-block;
                            image-rendering: pixelated;
                        }
                        .invoice-meta-grid {
                            display: grid;
                            grid-template-columns: 1fr 1fr;
                            gap: 10px;
                            margin-bottom: 8px;
                        }
                        .meta-col {
                            background: #f8fafc;
                            border: 1px solid #cbd5e1;
                            border-radius: 6px;
                            padding: 7px 10px;
                        }
                        .meta-title {
                            font-size: 0.78rem;
                            font-weight: 800;
                            color: #1e3a8a;
                            border-bottom: 1px solid #cbd5e1;
                            padding-bottom: 3px;
                            margin-bottom: 5px;
                            text-transform: uppercase;
                        }
                        .meta-table { width: 100%; font-size: 0.77rem; border-collapse: collapse; }
                        .meta-table td { padding: 2px 2px; vertical-align: top; border: none !important; }
                        .meta-label { width: 38%; color: #64748b; white-space: nowrap; }
                        .meta-val { color: #0f172a; }
                        .invoice-table {
                            width: 100% !important;
                            border-collapse: collapse !important;
                            border: 1.5px solid #475569 !important;
                            margin: 6px 0 !important;
                            font-size: 0.78rem !important;
                        }
                        .invoice-table th {
                            background: #f1f5f9 !important;
                            color: #0f172a !important;
                            font-weight: 700 !important;
                            padding: 5px 6px !important;
                            border: 1px solid #64748b !important;
                            text-align: center !important;
                        }
                        .invoice-table td {
                            padding: 4px 6px !important;
                            border: 1px solid #94a3b8 !important;
                            color: #1e293b !important;
                        }
                        .red-stamp-seal {
                            width: 105px;
                            height: 105px;
                            position: absolute;
                            left: 50%;
                            top: 50%;
                            transform: translate(-50%, -50%) rotate(-6deg);
                            opacity: 0.9;
                        }
                        .signature-stroke-img {
                            max-height: 60px;
                            max-width: 155px;
                            mix-blend-mode: multiply;
                        }
                    </style>
                </head>
                <body>
                    ${card.outerHTML}
                </body>
                </html>
            `);
            doc.close();

            setTimeout(() => {
                try {
                    printFrame.contentWindow.focus();
                    printFrame.contentWindow.print();
                } catch (e) {
                    window.print();
                }
            }, 250);
        }

        // Tự động bật thông báo Flash nếu có
        <?php if ($flash): ?>
            document.addEventListener('DOMContentLoaded', () => {
                showSvgAlert(
                    <?= json_encode($flash['message']) ?>,
                    <?= json_encode($flash['title'] ?: 'Thông báo') ?>,
                    <?= json_encode($flash['type'] === 'danger' ? 'error' : $flash['type']) ?>
                );
            });
        <?php endif; ?>
    </script>
</body>
</html>
