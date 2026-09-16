<?php
/**
 * ==========================================================
 * TRANG RÚT TIỀN TÀI KHOẢN TỰ ĐỘNG QUA TÀI KHOẢN NGÂN HÀNG (WITHDRAW)
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

// Danh sách các ngân hàng hỗ trợ rút tiền tại Việt Nam
$supportedBanks = [
    ['code' => 'MB', 'name' => 'MBBank (Ngân Hàng Quân Đội)', 'short' => 'MBBank'],
    ['code' => 'VCB', 'name' => 'Vietcombank (Ngoại Thương VN)', 'short' => 'Vietcombank'],
    ['code' => 'TCB', 'name' => 'Techcombank (Kỹ Thương VN)', 'short' => 'Techcombank'],
    ['code' => 'ACB', 'name' => 'ACB (Á Châu)', 'short' => 'ACB'],
    ['code' => 'BIDV', 'name' => 'BIDV (Đầu Tư & Phát Triển VN)', 'short' => 'BIDV'],
    ['code' => 'CTG', 'name' => 'VietinBank (Công Thương VN)', 'short' => 'VietinBank'],
    ['code' => 'TPB', 'name' => 'TPBank (Tiên Phong)', 'short' => 'TPBank'],
    ['code' => 'VPB', 'name' => 'VPBank (Việt Nam Thịnh Vượng)', 'short' => 'VPBank'],
    ['code' => 'STB', 'name' => 'Sacombank (Sài Gòn Thương Tín)', 'short' => 'Sacombank'],
    ['code' => 'HDB', 'name' => 'HDBank (Phát Triển TP.HCM)', 'short' => 'HDBank'],
    ['code' => 'VIB', 'name' => 'VIB (Quốc Tế VN)', 'short' => 'VIB'],
    ['code' => 'SHB', 'name' => 'SHB (Sài Gòn - Hà Nội)', 'short' => 'SHB'],
    ['code' => 'MSB', 'name' => 'MSB (Hàng Hải VN)', 'short' => 'MSB'],
    ['code' => 'OCB', 'name' => 'OCB (Phương Đông)', 'short' => 'OCB'],
    ['code' => 'LPB', 'name' => 'LPBank (Lộc Phát VN)', 'short' => 'LPBank'],
    ['code' => 'MOMO', 'name' => 'Ví Điện Tử MoMo', 'short' => 'Ví MoMo'],
    ['code' => 'ZALOPAY', 'name' => 'Ví Điện Tử ZaloPay', 'short' => 'Ví ZaloPay'],
];

// Định tuyến đường dẫn quay lại
$redirectRoute = strpos($_SERVER['REQUEST_URI'] ?? '', '/payments/withdraw') !== false ? '/payments/withdraw' : 'withdraw.php';

// ----------------------------------------------------------
// 3. XỬ LÝ POST: TẠO LỆNH RÚT TIỀN HOẶC HỦY LỆNH
// ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($submittedToken)) {
        set_flash('danger', 'Lỗi xác thực', 'Mã phiên bảo mật CSRF không hợp lệ hoặc đã hết hạn. Vui lòng tải lại trang.');
        header("Location: " . $redirectRoute);
        exit;
    }

    $action = trim($_POST['action'] ?? '');

    // ACTION: TẠO LỆNH RÚT TIỀN
    if ($action === 'create_withdrawal') {
        $bankCode = trim($_POST['bank_code'] ?? '');
        $bankName = trim($_POST['bank_name'] ?? '');
        $accountNumber = preg_replace('/[^0-9A-Za-z]/', '', trim($_POST['account_number'] ?? ''));
        $accountName = mb_strtoupper(trim($_POST['account_name'] ?? ''), 'UTF-8');
        $rawAmount = trim($_POST['amount'] ?? '0');
        $userNote = trim($_POST['user_note'] ?? '');

        $amount = (float)str_replace(['.', ',', ' '], '', $rawAmount);

        // Kiểm tra ngân hàng
        if (empty($bankCode) || empty($bankName)) {
            set_flash('danger', 'Thiếu thông tin', 'Vui lòng chọn ngân hàng hoặc ví điện tử nhận tiền.');
            header("Location: " . $redirectRoute);
            exit;
        }

        // Kiểm tra số tài khoản và chủ tài khoản
        if (empty($accountNumber) || strlen($accountNumber) < 5) {
            set_flash('danger', 'Số tài khoản không hợp lệ', 'Vui lòng nhập chính xác số tài khoản ngân hàng hoặc số điện thoại ví.');
            header("Location: " . $redirectRoute);
            exit;
        }

        if (empty($accountName) || mb_strlen($accountName, 'UTF-8') < 3) {
            set_flash('danger', 'Tên chủ tài khoản trống', 'Vui lòng nhập họ và tên chủ tài khoản nhận tiền (không dấu hoặc có dấu).');
            header("Location: " . $redirectRoute);
            exit;
        }

        // Kiểm tra hạn mức rút tiền
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

        // Kiểm tra số dư tài khoản
        $userBalance = (float)$currentUser['balance'];
        if ($amount > $userBalance) {
            set_flash('warning', 'Số dư không đủ', 'Số dư hiện tại của bạn (' . number_format($userBalance, 0, ',', '.') . ' ₫) không đủ để thực hiện rút ' . number_format($amount, 0, ',', '.') . ' ₫.');
            header("Location: " . $redirectRoute);
            exit;
        }

        // Phí rút tiền (Miễn phí 0đ)
        $fee = 0.00;
        $netAmount = $amount - $fee;

        // Sinh mã giao dịch rút tiền độc nhất: RUT + yymmdd + 5 số ngẫu nhiên
        $withdrawCode = 'RUT' . date('ymd') . rand(10000, 99999);

        // Bắt đầu Transaction CSDL đảm bảo toàn vẹn
        try {
            $pdo->beginTransaction();

            // 1. Trừ tiền tài khoản người dùng ngay lập tức
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

            // 3. Ghi log biến động số dư vào bảng transactions
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

            set_flash('success', 'Tạo lệnh rút tiền thành công', 'Yêu cầu rút ' . number_format($amount, 0, ',', '.') . ' ₫ (Mã #' . $withdrawCode . ') đã được gửi đến ban quản trị để giải ngân.');
            header("Location: " . $redirectRoute);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Lỗi tạo lệnh rút tiền: " . $e->getMessage());
            set_flash('danger', 'Lỗi xử lý', 'Đã có lỗi hệ thống xảy ra khi xử lý yêu cầu rút tiền. Vui lòng thử lại sau.');
            header("Location: " . $redirectRoute);
            exit;
        }
    }

    // ACTION: HỦY LỆNH RÚT TIỀN (CHỈ ÁP DỤNG CHO TRẠNG THÁI PENDING)
    if ($action === 'cancel_withdrawal') {
        $withdrawId = (int)($_POST['withdraw_id'] ?? 0);
        if ($withdrawId <= 0) {
            set_flash('danger', 'Lỗi', 'Lệnh rút tiền không tồn tại.');
            header("Location: " . $redirectRoute);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Khóa dòng withdrawal để kiểm tra trạng thái
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
                set_flash('warning', 'Không thể hủy', 'Lệnh rút tiền này đã được giải ngân hoặc đã đóng, không thể hủy bỏ.');
                header("Location: " . $redirectRoute);
                exit;
            }

            $refundAmount = (float)$wdItem['amount'];

            // 1. Cập nhật trạng thái withdrawal thành Cancelled
            $stmtCancel = $pdo->prepare("UPDATE withdrawals SET status = 'Cancelled' WHERE id = ?");
            $stmtCancel->execute([$withdrawId]);

            // 2. Hoàn tiền lại cho người dùng
            $stmtCurBal = $pdo->prepare("SELECT balance FROM users WHERE uuid = ? FOR UPDATE");
            $stmtCurBal->execute([$currentUser['uuid']]);
            $curBal = (float)$stmtCurBal->fetchColumn();

            $newBal = $curBal + $refundAmount;
            $stmtRefundUser = $pdo->prepare("UPDATE users SET balance = ? WHERE uuid = ?");
            $stmtRefundUser->execute([$newBal, $currentUser['uuid']]);

            // 3. Ghi log hoàn tiền vào bảng transactions
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
                'Hoàn lại tiền do người dùng hủy lệnh rút #' . $wdItem['withdraw_code']
            ]);

            $pdo->commit();

            set_flash('success', 'Hủy lệnh rút thành công', 'Lệnh rút tiền #' . $wdItem['withdraw_code'] . ' đã hủy. Số tiền ' . number_format($refundAmount, 0, ',', '.') . ' ₫ đã được hoàn trả về số dư tài khoản của bạn.');
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
// 4. LẤY DANH SÁCH LỊCH SỬ RÚT TIỀN & THỐNG KÊ
// ----------------------------------------------------------
$stmtHistory = $pdo->prepare("
    SELECT * FROM withdrawals 
    WHERE user_uuid = ? 
    ORDER BY id DESC 
    LIMIT 100
");
$stmtHistory->execute([$currentUser['uuid']]);
$history = $stmtHistory->fetchAll();

// Thống kê nhanh của người dùng
$totalWithdrawn = 0.00;
$totalPendingWithdraw = 0.00;
$totalTransactionsCount = count($history);

foreach ($history as $item) {
    if ($item['status'] === 'Success') {
        $totalWithdrawn += (float)$item['amount'];
    } elseif ($item['status'] === 'Pending') {
        $totalPendingWithdraw += (float)$item['amount'];
    }
}

// Lấy flash message nếu có
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

    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- FontAwesome 6 Pro -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.5/dist/sweetalert2.min.css" rel="stylesheet">

    <!-- jsPDF & html2canvas -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --primary-light: #6366f1;
            --secondary: #0ea5e9;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
            --dark: #0f172a;
            --dark-surface: #1e293b;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --app-bg: #f8fafc;
            --sidebar-width: 280px;
            --header-height: 72px;
            --border-radius: 16px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--app-bg);
            color: var(--text-main);
            min-height: 100vh;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
        }

        /* 1. Header Styles */
        .app-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: var(--header-height);
            background: #ffffff;
            border-bottom: 1px solid var(--card-border);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .sidebar-toggle-btn {
            background: #f1f5f9;
            border: 1px solid var(--card-border);
            color: var(--dark);
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .sidebar-toggle-btn:hover {
            background: #e2e8f0;
            color: var(--primary);
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
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.15rem;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.25);
        }

        .brand-name {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--dark);
            letter-spacing: -0.5px;
        }

        .brand-name span {
            color: var(--primary);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .header-balance-card {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f8fafc;
            border: 1px solid var(--card-border);
            padding: 6px 14px;
            border-radius: 30px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .header-balance-card:hover {
            border-color: var(--primary-light);
            background: #ffffff;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.08);
        }

        .balance-wallet-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }

        .balance-text-group {
            display: flex;
            flex-direction: column;
        }

        .balance-title {
            font-size: 0.72rem;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
        }

        .balance-val {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--dark);
        }

        .user-profile-container {
            position: relative;
        }

        .user-profile-toggle {
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
        }

        .user-avatar-small {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e0e7ff;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
        }

        .user-profile-popup {
            position: absolute;
            top: 54px;
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

        .user-profile-popup.active {
            display: block;
            animation: fadeInPopup 0.2s ease;
        }

        @keyframes fadeInPopup {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .popup-menu-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            color: var(--text-main);
            text-decoration: none;
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 600;
            transition: all 0.15s ease;
        }

        .popup-menu-item:hover {
            background: #f1f5f9;
            color: var(--primary);
        }

        /* 2. Sidebar Styles */
        .app-sidebar {
            position: fixed;
            top: var(--header-height);
            left: 0;
            bottom: 0;
            width: var(--sidebar-width);
            background: #ffffff;
            border-right: 1px solid var(--card-border);
            padding: 24px 16px;
            overflow-y: auto;
            z-index: 990;
            transition: transform 0.3s ease;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(4px);
            z-index: 980;
        }

        .sidebar-backdrop.active {
            display: block;
        }

        .nav-section-title {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.8px;
            margin: 18px 12px 8px;
        }

        .nav-item-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 16px;
            color: var(--text-main);
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.92rem;
            transition: all 0.2s ease;
            margin-bottom: 4px;
        }

        .nav-item-link i {
            font-size: 1.1rem;
            width: 22px;
            color: var(--text-muted);
            transition: color 0.2s ease;
        }

        .nav-item-link:hover {
            background: #f1f5f9;
            color: var(--primary);
        }

        .nav-item-link:hover i {
            color: var(--primary);
        }

        .nav-item-link.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }

        .nav-item-link.active i {
            color: #ffffff;
        }

        .submenu-list {
            padding-left: 20px;
            margin-bottom: 6px;
        }

        .submenu-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.86rem;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.15s ease;
            margin-bottom: 2px;
        }

        .submenu-link:hover {
            color: var(--primary);
            background: #f8fafc;
        }

        .submenu-link.active {
            color: var(--primary);
            background: #eef2ff;
            font-weight: 700;
        }

        /* 3. Main Content Layout */
        .app-main {
            margin-top: var(--header-height);
            margin-left: var(--sidebar-width);
            padding: 30px;
            flex-grow: 1;
            transition: margin-left 0.3s ease;
        }

        body.sidebar-collapsed .app-sidebar {
            transform: translateX(-100%);
        }

        body.sidebar-collapsed .app-main {
            margin-left: 0;
        }

        /* Breadcrumbs & Page Header */
        .page-header-box {
            margin-bottom: 28px;
        }

        .page-title {
            font-size: 1.65rem;
            font-weight: 800;
            color: var(--dark);
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-subtitle {
            color: var(--text-muted);
            font-size: 0.92rem;
            margin-top: 4px;
        }

        /* Stats Cards Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: #ffffff;
            border-radius: var(--border-radius);
            border: 1px solid var(--card-border);
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
        }

        .stat-icon-wrap {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }

        .stat-icon-balance { background: #e0f2fe; color: #0284c7; }
        .stat-icon-success { background: #dcfce7; color: #16a34a; }
        .stat-icon-pending { background: #fef3c7; color: #d97706; }
        .stat-icon-count { background: #ede9fe; color: #7c3aed; }

        .stat-info-wrap {
            display: flex;
            flex-direction: column;
        }

        .stat-label {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .stat-value {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--dark);
            letter-spacing: -0.5px;
            margin-top: 2px;
        }

        /* Main Form & Preview Card Layout */
        .withdraw-layout-grid {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 24px;
            margin-bottom: 32px;
        }

        @media (max-width: 1200px) {
            .withdraw-layout-grid {
                grid-template-columns: 1fr;
            }
        }

        .withdraw-card {
            background: #ffffff;
            border-radius: var(--border-radius);
            border: 1px solid var(--card-border);
            padding: 28px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
        }

        .card-header-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
        }

        .card-title-text {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Virtual ATM Card Preview */
        .virtual-card {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);
            border-radius: 20px;
            padding: 24px;
            color: #ffffff;
            box-shadow: 0 15px 30px rgba(49, 46, 129, 0.35);
            position: relative;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .virtual-card::before {
            content: '';
            position: absolute;
            top: -60px;
            right: -60px;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            pointer-events: none;
        }

        .virtual-card::after {
            content: '';
            position: absolute;
            bottom: -80px;
            left: -40px;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: rgba(99, 102, 241, 0.2);
            pointer-events: none;
        }

        .card-top-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 26px;
        }

        .card-chip {
            width: 44px;
            height: 32px;
            background: linear-gradient(135deg, #fcd34d 0%, #f59e0b 100%);
            border-radius: 6px;
            position: relative;
            border: 1px solid #fbbf24;
        }

        .card-chip::after {
            content: '';
            position: absolute;
            inset: 6px 10px;
            border: 1px solid rgba(0, 0, 0, 0.15);
            border-radius: 2px;
        }

        .card-bank-badge {
            font-size: 0.95rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            background: rgba(255, 255, 255, 0.15);
            padding: 4px 12px;
            border-radius: 20px;
            backdrop-filter: blur(4px);
        }

        .card-number-display {
            font-family: 'Courier New', Courier, monospace;
            font-size: 1.35rem;
            letter-spacing: 2.5px;
            font-weight: 700;
            margin-bottom: 20px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .card-bottom-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .card-holder-title {
            font-size: 0.65rem;
            text-transform: uppercase;
            color: #cbd5e1;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .card-holder-name {
            font-size: 0.95rem;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
        }

        .card-amount-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            color: #cbd5e1;
            letter-spacing: 1px;
            text-align: right;
            font-weight: 600;
        }

        .card-amount-val {
            font-size: 1.15rem;
            font-weight: 800;
            color: #34d399;
            text-align: right;
        }

        /* Summary Info List */
        .summary-info-box {
            background: #f8fafc;
            border: 1px solid var(--card-border);
            border-radius: 14px;
            padding: 16px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 0.88rem;
            border-bottom: 1px dashed #e2e8f0;
        }

        .summary-row:last-child {
            border-bottom: none;
            padding-top: 12px;
            font-size: 1rem;
            font-weight: 800;
        }

        /* Form Inputs */
        .form-label-custom {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-control-custom, .form-select-custom {
            border: 1.5px solid var(--card-border);
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 0.92rem;
            font-weight: 600;
            color: var(--dark);
            transition: all 0.2s ease;
            width: 100%;
            background-color: #ffffff;
        }

        .form-control-custom:focus, .form-select-custom:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.12);
            outline: none;
        }

        /* Quick Amount Pills */
        .amount-quick-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .amount-pill-btn {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            color: var(--dark);
            border-radius: 30px;
            padding: 7px 14px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .amount-pill-btn:hover {
            background: #e2e8f0;
            border-color: #cbd5e1;
            color: var(--primary);
        }

        .amount-pill-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.25);
        }

        /* Premium Amount Box */
        .premium-amount-box {
            display: flex;
            align-items: center;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 8px 16px;
            transition: all 0.2s ease;
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
            font-size: 1.1rem;
            margin-right: 12px;
        }

        .amount-field-inner {
            flex-grow: 1;
        }

        .amount-field-label {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .amount-input-control {
            border: none;
            background: transparent;
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--dark);
            width: 100%;
            padding: 0;
            outline: none;
        }

        .currency-tag-pill {
            font-size: 0.85rem;
            font-weight: 800;
            color: var(--text-muted);
            background: #e2e8f0;
            padding: 4px 10px;
            border-radius: 20px;
        }

        .btn-gradient-submit {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #ffffff;
            border: none;
            border-radius: 14px;
            padding: 14px 28px;
            font-size: 1rem;
            font-weight: 800;
            width: 100%;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-gradient-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4);
            color: #ffffff;
        }

        /* History Table Styles */
        .history-card {
            background: #ffffff;
            border-radius: var(--border-radius);
            border: 1px solid var(--card-border);
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.02);
        }

        .custom-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .custom-table th {
            background: #f8fafc;
            color: var(--text-muted);
            font-size: 0.76rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 14px 16px;
            border-bottom: 1px solid var(--card-border);
        }

        .custom-table td {
            padding: 14px 16px;
            font-size: 0.88rem;
            color: var(--dark);
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .custom-table tr:hover td {
            background-color: #fafbfc;
        }

        .btn-action-cancel {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fca5a5;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-action-cancel:hover {
            background: #dc2626;
            color: #ffffff;
        }

        .btn-action-view {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-action-view:hover {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }

        /* ==========================================================
         * DIALOG MODAL SVG STROKE DRAW ANIMATION (CHUẨN 1:1 ĐỒNG BỘ)
         * ========================================================== */
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

        .svg-dialog-overlay.active {
            opacity: 1;
            visibility: visible;
        }

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

        .svg-dialog-overlay.active .svg-dialog-box {
            transform: translateY(0) scale(1);
        }

        .svg-icon-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 16px;
        }

        .svg-draw-icon {
            width: 80px;
            height: 80px;
        }

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

        .svg-question-dot {
            opacity: 0;
            animation: fadeInDot 0.25s ease-out 0.85s forwards;
        }

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

        /* Responsive Mobile & Tablet */
        @media (max-width: 991px) {
            .app-sidebar {
                transform: translateX(-100%);
            }
            .app-sidebar.sidebar-open {
                transform: translateX(0);
            }
            .app-main {
                margin-left: 0;
                padding: 20px 15px;
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
            <!-- Xem số dư & nạp tiền -->
            <a href="/payments/deposit" class="header-balance-card" title="Nạp thêm tiền">
                <div class="balance-wallet-icon">
                    <i class="fa-solid fa-wallet"></i>
                </div>
                <div class="balance-text-group">
                    <span class="balance-title">Số dư ví</span>
                    <span class="balance-val"><?= number_format((float)$currentUser['balance'], 0, ',', '.') ?> ₫</span>
                </div>
            </a>

            <!-- Avatar & Dropdown Popup Menu -->
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
                            <div class="fw-bold text-dark text-truncate" style="font-size: 0.95rem;"><?= htmlspecialchars($currentUser['name']) ?></div>
                            <div class="text-muted small text-truncate">@<?= htmlspecialchars($currentUser['username']) ?></div>
                            <span class="badge bg-primary-subtle text-primary mt-1" style="font-size: 0.68rem;">UID: #<?= htmlspecialchars($currentUser['uid']) ?></span>
                        </div>
                    </div>
                    <div class="d-flex flex-column gap-1">
                        <a href="profile.php" class="popup-menu-item">
                            <i class="fa-solid fa-user-gear me-2 text-secondary"></i> Tài khoản của tôi
                        </a>
                        <a href="/payments/deposit" class="popup-menu-item">
                            <i class="fa-solid fa-circle-arrow-up me-2 text-success"></i> Nạp tiền ví
                        </a>
                        <a href="/payments/withdraw" class="popup-menu-item text-primary fw-bold">
                            <i class="fa-solid fa-circle-arrow-down me-2 text-primary"></i> Rút tiền ngân hàng
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

    <!-- Backdrop khi mở menu Mobile -->
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeAppSidebar()"></div>

    <!-- ==========================================================
     * 2. THANH MENU BÊN TRÁI (SIDEBAR)
     * ========================================================== -->
    <aside class="app-sidebar" id="appSidebar">
        <div class="nav-section-title">Tổng quan</div>
        <a href="index.php" class="nav-item-link">
            <i class="fa-solid fa-house"></i>
            <span>Bảng điều khiển</span>
        </a>

        <div class="nav-section-title">Dịch vụ & Tiện ích</div>
        <a href="buy-key.php" class="nav-item-link">
            <i class="fa-solid fa-key"></i>
            <span>Mua Key Bản Quyền</span>
        </a>
        <a href="cloud.php" class="nav-item-link">
            <i class="fa-solid fa-cloud"></i>
            <span>Thuê Máy Chủ Cloud</span>
        </a>
        <a href="token.php" class="nav-item-link">
            <i class="fa-solid fa-cube"></i>
            <span>Quản lý Token</span>
        </a>

        <div class="nav-section-title">Tài chính & Thanh toán</div>
        <div class="submenu-list">
            <a href="/payments/deposit" class="submenu-link">
                <i class="fa-solid fa-circle-plus"></i>
                <span>Nạp tiền tự động</span>
            </a>
            <a href="/payments/withdraw" class="submenu-link active">
                <i class="fa-solid fa-money-bill-transfer"></i>
                <span>Rút tiền ngân hàng</span>
            </a>
        </div>

        <div class="nav-section-title">Cá nhân & Hỗ trợ</div>
        <a href="profile.php" class="nav-item-link">
            <i class="fa-solid fa-user"></i>
            <span>Hồ sơ cá nhân</span>
        </a>
        <a href="referral.php" class="nav-item-link">
            <i class="fa-solid fa-users"></i>
            <span>Giới thiệu nhận quà</span>
        </a>
        <a href="support.php" class="nav-item-link">
            <i class="fa-solid fa-headset"></i>
            <span>Hỗ trợ kỹ thuật</span>
        </a>
        <a href="settings.php" class="nav-item-link">
            <i class="fa-solid fa-gear"></i>
            <span>Cài đặt hệ thống</span>
        </a>
    </aside>

    <!-- ==========================================================
     * 3. NỘI DUNG CHÍNH (APP MAIN)
     * ========================================================== -->
    <main class="app-main">
        <div class="container-fluid p-0">

            <!-- Tiêu đề trang -->
            <div class="page-header-box">
                <h1 class="page-title">
                    <i class="fa-solid fa-money-bill-transfer text-primary"></i>
                    <span>Rút Tiền Về Ngân Hàng</span>
                </h1>
                <p class="page-subtitle">
                    Yêu cầu rút tiền từ số dư tài khoản về ngân hàng cá nhân hoặc ví điện tử (Miễn phí giải ngân 100%).
                </p>
            </div>

            <!-- Thẻ thống kê tài chính -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon-wrap stat-icon-balance">
                        <i class="fa-solid fa-wallet"></i>
                    </div>
                    <div class="stat-info-wrap">
                        <span class="stat-label">Số dư khả dụng</span>
                        <span class="stat-value text-primary"><?= number_format((float)$currentUser['balance'], 0, ',', '.') ?> ₫</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon-wrap stat-icon-success">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div class="stat-info-wrap">
                        <span class="stat-label">Đã rút thành công</span>
                        <span class="stat-value text-success"><?= number_format($totalWithdrawn, 0, ',', '.') ?> ₫</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon-wrap stat-icon-pending">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div class="stat-info-wrap">
                        <span class="stat-label">Đang chờ xử lý</span>
                        <span class="stat-value text-warning"><?= number_format($totalPendingWithdraw, 0, ',', '.') ?> ₫</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon-wrap stat-icon-count">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                    <div class="stat-info-wrap">
                        <span class="stat-label">Tổng lệnh rút</span>
                        <span class="stat-value"><?= number_format($totalTransactionsCount) ?></span>
                    </div>
                </div>
            </div>

            <!-- Khung Form Rút Tiền & Thẻ Preview Virtual ATM -->
            <div class="withdraw-layout-grid">

                <!-- CỘT 1: FORM TẠO LỆNH RÚT TIỀN -->
                <div class="withdraw-card">
                    <div class="card-header-title">
                        <div class="card-title-text">
                            <i class="fa-solid fa-paper-plane text-primary"></i>
                            <span>Khởi Tạo Yêu Cầu Rút Tiền</span>
                        </div>
                        <span class="badge bg-success-subtle text-success fw-bold px-3 py-1">
                            <i class="fa-solid fa-shield-check me-1"></i> Miễn phí 0 ₫
                        </span>
                    </div>

                    <form action="<?= htmlspecialchars($redirectRoute) ?>" method="POST" id="withdrawalForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="create_withdrawal">
                        <input type="hidden" name="bank_name" id="selectedBankName" value="MBBank (Ngân Hàng Quân Đội)">

                        <!-- BƯỚC 1: CHỌN NGÂN HÀNG HOẶC VÍ ĐIỆN TỬ -->
                        <div class="mb-4">
                            <label for="bankSelect" class="form-label-custom">
                                <i class="fa-solid fa-building-columns text-primary"></i>
                                <span>1. Chọn ngân hàng / Ví điện tử thụ hưởng:</span> <span class="text-danger">*</span>
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
                                       autocomplete="off" 
                                       oninput="updateCardNumber(this)">
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
                                       oninput="updateCardHolder(this)">
                            </div>
                        </div>

                        <!-- BƯỚC 3: NHẬP SỐ TIỀN CẦN RÚT -->
                        <div class="mb-4">
                            <label for="amountInput" class="form-label-custom">
                                <i class="fa-solid fa-dong-sign text-primary"></i>
                                <span>4. Số tiền muốn rút (VND):</span> <span class="text-danger">*</span>
                            </label>

                            <!-- Nút chọn nhanh số tiền -->
                            <div class="amount-quick-pills">
                                <button type="button" class="amount-pill-btn active" onclick="selectWithdrawAmount(50000, this)">50.000 ₫</button>
                                <button type="button" class="amount-pill-btn" onclick="selectWithdrawAmount(100000, this)">100.000 ₫</button>
                                <button type="button" class="amount-pill-btn" onclick="selectWithdrawAmount(200000, this)">200.000 ₫</button>
                                <button type="button" class="amount-pill-btn" onclick="selectWithdrawAmount(500000, this)">500.000 ₫</button>
                                <button type="button" class="amount-pill-btn" onclick="selectWithdrawAmount(1000000, this)">1.000.000 ₫</button>
                                <button type="button" class="amount-pill-btn text-primary border-primary" onclick="selectWithdrawAmount(<?= (float)$currentUser['balance'] ?>, this)">
                                    <i class="fa-solid fa-bolt me-1"></i> Rút toàn bộ số dư
                                </button>
                            </div>

                            <!-- Khung nhập tiền cao cấp Fintech -->
                            <div class="premium-amount-box mb-2">
                                <div class="currency-symbol-badge">
                                    <i class="fa-solid fa-dong-sign"></i>
                                </div>
                                <div class="amount-field-inner">
                                    <label for="amountInput" class="amount-field-label">Số tiền rút</label>
                                    <input type="text" 
                                           class="amount-input-control" 
                                           id="amountInput" 
                                           name="amount" 
                                           value="50.000" 
                                           placeholder="50.000" 
                                           required 
                                           autocomplete="off" 
                                           oninput="formatWithdrawInput(this)">
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
                                <span>5. Ghi chú chuyển tiền (Tùy chọn):</span>
                            </label>
                            <input type="text" 
                                   class="form-control-custom" 
                                   id="userNoteInput" 
                                   name="user_note" 
                                   placeholder="Ví dụ: Rút tiền thưởng hoa hồng, rút thanh toán...">
                        </div>

                        <!-- NÚT BẤM GỬI LỆNH -->
                        <div class="mt-4">
                            <button type="submit" class="btn-gradient-submit" id="submitWithdrawBtn">
                                <i class="fa-solid fa-paper-plane fs-5"></i>
                                <span>Xác Nhận & Gửi Yêu Cầu Rút Tiền</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- CỘT 2: THẺ PREVIEW ATM & TỔNG KẾT GIAO DỊCH -->
                <div>
                    <!-- THẺ ATM MÔ PHỎNG THỜI GIAN THỰC -->
                    <div class="virtual-card">
                        <div class="card-top-row">
                            <div class="card-chip"></div>
                            <div class="card-bank-badge" id="previewBankBadge">MBBANK</div>
                        </div>
                        <div class="card-number-display" id="previewCardNumber">•••• •••• •••• ••••</div>
                        <div class="card-bottom-row">
                            <div>
                                <div class="card-holder-title">Chủ tài khoản</div>
                                <div class="card-holder-name text-truncate" style="max-width: 170px;" id="previewCardHolder">NGUOI THU HUONG</div>
                            </div>
                            <div>
                                <div class="card-amount-label">Thực nhận</div>
                                <div class="card-amount-val" id="previewCardAmount">50.000 ₫</div>
                            </div>
                        </div>
                    </div>

                    <!-- BẢNG TỔNG KẾT ĐỐI SOÁT -->
                    <div class="summary-info-box">
                        <div class="fw-bold text-dark mb-3 pb-2 border-bottom" style="font-size: 0.95rem;">
                            <i class="fa-solid fa-receipt text-primary me-2"></i> Chi tiết thanh toán giải ngân
                        </div>
                        <div class="summary-row">
                            <span class="text-muted">Số dư ví hiện tại:</span>
                            <span class="fw-bold text-dark"><?= number_format((float)$currentUser['balance'], 0, ',', '.') ?> ₫</span>
                        </div>
                        <div class="summary-row">
                            <span class="text-muted">Số tiền muốn rút:</span>
                            <span class="fw-bold text-primary" id="summaryWithdrawAmount">50.000 ₫</span>
                        </div>
                        <div class="summary-row">
                            <span class="text-muted">Phí giao dịch rút:</span>
                            <span class="fw-bold text-success">0 ₫ (Miễn phí)</span>
                        </div>
                        <div class="summary-row">
                            <span class="text-muted">Thời gian giải ngân:</span>
                            <span class="fw-semibold text-dark">5 - 30 phút (24/7)</span>
                        </div>
                        <div class="summary-row text-dark">
                            <span>Số dư sau khi rút:</span>
                            <span class="text-danger" id="summaryRemainBalance">
                                <?= number_format(max(0, (float)$currentUser['balance'] - 50000), 0, ',', '.') ?> ₫
                            </span>
                        </div>
                    </div>

                    <!-- Khung lưu ý -->
                    <div class="mt-3 p-3 bg-white border rounded-3 small text-muted">
                        <div class="fw-bold text-dark mb-1"><i class="fa-solid fa-circle-exclamation text-warning me-1"></i> Lưu ý khi rút tiền:</div>
                        <div>• Vui lòng điền đúng 100% Số tài khoản và Tên chủ tài khoản để tránh treo lệnh.</div>
                        <div>• Bạn có thể hủy lệnh bất kỳ lúc nào nếu lệnh vẫn đang ở trạng thái <strong>Đang chờ duyệt</strong>.</div>
                    </div>
                </div>

            </div>

            <!-- BẢNG LỊCH SỬ RÚT TIỀN CỦA BẠN -->
            <div class="history-card">
                <div class="card-header-title">
                    <div class="card-title-text">
                        <i class="fa-solid fa-clock-rotate-left text-primary"></i>
                        <span>Lịch Sử Giao Dịch Rút Tiền Của Bạn</span>
                    </div>
                    <span class="badge bg-light text-muted border px-3 py-1"><?= count($history) ?> giao dịch</span>
                </div>

                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Mã đơn</th>
                                <th>Ngân hàng thụ hưởng</th>
                                <th>Số tài khoản / Tên người nhận</th>
                                <th>Số tiền rút</th>
                                <th>Thực nhận</th>
                                <th>Thời gian</th>
                                <th>Trạng thái</th>
                                <th class="text-center">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($history)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="fa-solid fa-receipt fs-1 mb-3 d-block opacity-25"></i>
                                        Bạn chưa thực hiện yêu cầu rút tiền nào.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($history as $item): ?>
                                    <?php 
                                        $st = $item['status'];
                                        $badgeClass = 'bg-warning text-dark';
                                        $stText = 'Đang chờ duyệt';
                                        $stIcon = 'fa-clock';
                                        if ($st === 'Success') {
                                            $badgeClass = 'bg-success text-white';
                                            $stText = 'Đã hoàn thành';
                                            $stIcon = 'fa-circle-check';
                                        } elseif ($st === 'Cancelled') {
                                            $badgeClass = 'bg-secondary text-white';
                                            $stText = 'Đã hủy';
                                            $stIcon = 'fa-ban';
                                        } elseif ($st === 'Failed') {
                                            $badgeClass = 'bg-danger text-white';
                                            $stText = 'Thất bại';
                                            $stIcon = 'fa-circle-xmark';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="fw-bold font-monospace text-primary">#<?= htmlspecialchars($item['withdraw_code']) ?></span>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($item['bank_name']) ?></div>
                                            <?php if (!empty($item['bank_code'])): ?>
                                                <span class="badge bg-primary-subtle text-primary" style="font-size: 0.7rem;"><?= htmlspecialchars($item['bank_code']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="font-monospace fw-bold text-dark"><?= htmlspecialchars($item['account_number']) ?></div>
                                            <div class="small text-muted text-uppercase"><?= htmlspecialchars($item['account_name']) ?></div>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-danger">-<?= number_format((float)$item['amount'], 0, ',', '.') ?> ₫</span>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-success">+<?= number_format((float)$item['net_amount'], 0, ',', '.') ?> ₫</span>
                                        </td>
                                        <td>
                                            <div class="small fw-semibold text-dark"><?= date('H:i d/m/Y', strtotime($item['created_at'])) ?></div>
                                            <div class="small text-muted" style="font-size: 0.72rem;"><?= time_ago($item['created_at']) ?></div>
                                        </td>
                                        <td>
                                            <span class="badge <?= $badgeClass ?> px-2 py-1 rounded-pill fw-bold" style="font-size: 0.74rem;">
                                                <i class="fa-solid <?= $stIcon ?> me-1"></i> <?= $stText ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($st === 'Pending'): ?>
                                                <!-- Khi Đang chờ: Cho phép Hủy lệnh và nhận lại tiền ngay -->
                                                <form action="<?= htmlspecialchars($redirectRoute) ?>" method="POST" class="d-inline" id="cancelWithdrawForm_<?= $item['id'] ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="cancel_withdrawal">
                                                    <input type="hidden" name="withdraw_id" value="<?= $item['id'] ?>">
                                                    <button type="button" class="btn-action-cancel" title="Hủy lệnh rút tiền này" onclick="confirmCancelWithdraw('cancelWithdrawForm_<?= $item['id'] ?>', '<?= htmlspecialchars($item['withdraw_code']) ?>', '<?= number_format((float)$item['amount'], 0, ',', '.') ?> ₫')">
                                                        <i class="fa-solid fa-ban"></i>
                                                        <span>Hủy lệnh</span>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <!-- Khi Đã xử lý / Đã hủy: Nút xem Phiếu chi điện tử -->
                                                <button type="button" 
                                                        class="btn-action-view" 
                                                        title="Xem phiếu chi & in hóa đơn"
                                                        onclick='showWithdrawalVoucher(<?= htmlspecialchars(json_encode([
                                                            'code' => $item['withdraw_code'],
                                                            'amount' => (float)$item['amount'],
                                                            'net_amount' => (float)$item['net_amount'],
                                                            'fee' => (float)$item['fee'],
                                                            'amount_formatted' => number_format((float)$item['amount'], 0, ',', '.') . ' ₫',
                                                            'net_amount_formatted' => number_format((float)$item['net_amount'], 0, ',', '.') . ' ₫',
                                                            'fee_formatted' => number_format((float)$item['fee'], 0, ',', '.') . ' ₫',
                                                            'bank_name' => $item['bank_name'],
                                                            'account_number' => $item['account_number'],
                                                            'account_name' => $item['account_name'],
                                                            'status' => $item['status'],
                                                            'created_at' => date('d/m/Y H:i', strtotime($item['created_at'])),
                                                            'customer_name' => $currentUser['name'],
                                                            'customer_username' => $currentUser['username'],
                                                            'customer_email' => $currentUser['email']
                                                        ])) ?>)'>
                                                    <i class="fa-solid fa-file-invoice-dollar text-primary"></i>
                                                    <span>Phiếu chi</span>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <!-- ==========================================================
     * MODAL PHIẾU CHI TIỀN ĐIỆN TỬ DOANH NGHIỆP (A4 FULL-WIDTH)
     * ========================================================== -->
    <div class="modal fade" id="withdrawalVoucherModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" style="max-width: 780px;">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
                <div class="modal-header bg-dark text-white border-0 py-3 px-4 justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-file-invoice-dollar text-warning fs-5"></i>
                        <h5 class="modal-title fw-bold mb-0">Phiếu Chi Tiền Giải Ngân Điện Tử</h5>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-3" onclick="exportWithdrawVoucherToPdf()">
                            <i class="fa-solid fa-file-pdf me-1"></i> Xuất PDF
                        </button>
                        <button type="button" class="btn btn-sm btn-light rounded-pill px-3" onclick="printWithdrawVoucher()">
                            <i class="fa-solid fa-print me-1"></i> In A4
                        </button>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body p-4 bg-light" style="max-height: 82vh; overflow-y: auto;">
                    <!-- KHU VỰC IN PHIẾU CHI -->
                    <div id="voucherPrintArea" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 16px 20px; color: #0f172a; font-size: 0.84rem; line-height: 1.4;">
                        
                        <!-- 1. Header Quốc Hiệu & Đơn Vị -->
                        <div class="d-flex justify-content-between align-items-start pb-2 border-bottom mb-2">
                            <div>
                                <div class="fw-bold text-uppercase text-dark" style="font-size: 0.92rem; letter-spacing: 0.5px;">CÔNG TY TNHH CÔNG NGHỆ SỐ THÀNH QUÝ TECH</div>
                                <div class="text-muted small">MST: <span class="fw-semibold text-dark">0318954321</span> | SĐT: <span class="fw-semibold text-dark">0355879036</span></div>
                                <div class="text-muted small">Địa chỉ: Tòa nhà Bitexco Financial, Q.1, TP. Hồ Chí Minh</div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-uppercase" style="font-size: 0.8rem; letter-spacing: 0.5px;">CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</div>
                                <div class="fst-italic small text-muted">Độc lập - Tự do - Hạnh phúc</div>
                                <div class="small text-muted mt-1" id="voucherDate">TP. Hồ Chí Minh, ngày ... tháng ... năm ...</div>
                            </div>
                        </div>

                        <!-- 2. Tiêu Đề Phiếu Chi -->
                        <div class="text-center my-3">
                            <h4 class="fw-bold text-uppercase text-danger mb-1" style="letter-spacing: 1px; font-size: 1.35rem;">PHIẾU CHI TIỀN ĐIỆN TỬ</h4>
                            <div class="text-muted small">Mã chứng từ: <span class="fw-bold text-dark font-monospace" id="voucherCode">#RUT000000</span> | Trạng thái: <span id="voucherStatusBadge"></span></div>
                            
                            <!-- Mã vạch đối soát bán lẻ Canvas -->
                            <div class="my-2" id="voucherBarcodeContainer"></div>
                        </div>

                        <!-- 3. Thông Tin Khách Hàng & Ngân Hàng Nhận -->
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <div class="p-2 border rounded bg-light" style="font-size: 0.8rem;">
                                    <div class="fw-bold text-uppercase text-primary border-bottom pb-1 mb-1">NGƯỜI NHẬN TIỀN / KHÁCH HÀNG</div>
                                    <div>• Họ và tên: <strong class="text-dark" id="voucherCustomerName">...</strong></div>
                                    <div>• Tài khoản: <span class="text-dark" id="voucherCustomerUsername">@...</span></div>
                                    <div>• Email: <span class="text-dark" id="voucherCustomerEmail">...</span></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 border rounded bg-light" style="font-size: 0.8rem;">
                                    <div class="fw-bold text-uppercase text-primary border-bottom pb-1 mb-1">THÔNG TIN TÀI KHOẢN THỤ HƯỞNG</div>
                                    <div>• Ngân hàng: <strong class="text-dark" id="voucherBankName">...</strong></div>
                                    <div>• Số tài khoản: <strong class="font-monospace text-primary" id="voucherAccountNumber">...</strong></div>
                                    <div>• Chủ tài khoản: <strong class="text-dark text-uppercase" id="voucherAccountName">...</strong></div>
                                </div>
                            </div>
                        </div>

                        <!-- 4. Bảng Kê Chi Tiết Giải Ngân -->
                        <table class="table table-bordered mb-2" style="font-size: 0.82rem;">
                            <thead class="table-secondary text-center">
                                <tr>
                                    <th style="width: 10%;">STT</th>
                                    <th style="width: 50%;">Nội Dung Khoản Chi</th>
                                    <th style="width: 20%;">Số Tiền Rút</th>
                                    <th style="width: 20%;">Thực Nhận</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="text-center">1</td>
                                    <td>Giải ngân rút tiền từ số dư tài khoản về ngân hàng cá nhân</td>
                                    <td class="text-end fw-bold" id="voucherAmount">0 ₫</td>
                                    <td class="text-end fw-bold text-success" id="voucherNetAmount">0 ₫</td>
                                </tr>
                                <tr>
                                    <td colspan="3" class="text-end fw-bold">Phí giao dịch giải ngân:</td>
                                    <td class="text-end fw-bold text-success">0 ₫ (Miễn phí)</td>
                                </tr>
                                <tr class="table-light">
                                    <td colspan="3" class="text-end fw-bold text-danger text-uppercase">TỔNG CỘNG THỰC CHI:</td>
                                    <td class="text-end fw-bold text-danger fs-6" id="voucherTotalAmount">0 ₫</td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Số tiền viết bằng chữ -->
                        <div class="fst-italic text-dark mb-3 small">
                            • Số tiền bằng chữ: <strong class="text-danger" id="voucherAmountWords">...</strong>
                        </div>

                        <!-- 5. Chữ Ký & Con Dấu Điện Tử -->
                        <div class="row text-center mt-3 pt-2 border-top">
                            <div class="col-6">
                                <div class="fw-bold text-uppercase" style="font-size: 0.82rem;">NGƯỜI NHẬN TIỀN</div>
                                <div class="text-muted small fst-italic">(Ký, ghi rõ họ tên)</div>
                                <div style="height: 65px; display: flex; align-items: center; justify-content: center;" class="mt-1">
                                    <span class="badge bg-light text-muted border">Xác thực OTP/Web</span>
                                </div>
                                <div class="fw-bold text-dark mt-1" id="voucherSignCustomerName">...</div>
                            </div>
                            <div class="col-6 position-relative">
                                <div class="fw-bold text-uppercase" style="font-size: 0.82rem;">GIÁM ĐỐC / ĐƠN VỊ CHI TRẢ</div>
                                <div class="text-muted small fst-italic">(Ký số & đóng dấu điện tử)</div>
                                <div style="height: 70px; position: relative; display: flex; align-items: center; justify-content: center;" class="mt-1">
                                    
                                    <!-- Con dấu tròn đỏ SVG -->
                                    <div style="position: absolute; width: 105px; height: 105px; opacity: 0.85; transform: rotate(-8deg); pointer-events: none;">
                                        <svg viewBox="0 0 160 160" width="105" height="105">
                                            <circle cx="80" cy="80" r="74" fill="none" stroke="#dc2626" stroke-width="3" />
                                            <circle cx="80" cy="80" r="70" fill="none" stroke="#dc2626" stroke-width="1" />
                                            <circle cx="80" cy="80" r="67" fill="none" stroke="#dc2626" stroke-width="1.5" stroke-dasharray="3,3" />
                                            <path id="wdCurveTop" d="M 18,80 A 62,62 0 0,1 142,80" fill="none" />
                                            <text font-size="8.8" font-weight="900" fill="#dc2626" letter-spacing="1.2">
                                                <textPath href="#wdCurveTop" startOffset="50%" text-anchor="middle">
                                                    ★ CÔNG TY TNHH CÔNG NGHỆ SỐ THÀNH QUÝ TECH ★
                                                </textPath>
                                            </text>
                                            <path id="wdCurveBottom" d="M 138,80 A 58,58 0 0,1 22,80" fill="none" />
                                            <text font-size="8.5" font-weight="700" fill="#dc2626" letter-spacing="1">
                                                <textPath href="#wdCurveBottom" startOffset="50%" text-anchor="middle">
                                                    ★ MST: 0318954321 ★ TP. HỒ CHÍ MINH
                                                </textPath>
                                            </text>
                                            <polygon points="80,57 82.5,63.5 89.5,63.5 84,68 86,74.5 80,70.5 74,74.5 76,68 70.5,63.5 77.5,63.5" fill="#dc2626" />
                                            <text x="80" y="91" font-size="10" font-weight="900" fill="#dc2626" text-anchor="middle" letter-spacing="0.5">ĐÃ XÁC NHẬN</text>
                                            <text x="80" y="103" font-size="8" font-weight="700" fill="#dc2626" text-anchor="middle">THANH TOÁN</text>
                                        </svg>
                                    </div>

                                    <!-- Chữ ký sống transparent của Phan Thành Quý -->
                                    <div style="position: relative; z-index: 2;" id="voucherSignatureContainer"></div>
                                </div>
                                <div class="fw-bold text-dark mt-1" style="font-size: 0.86rem;">Phan Thành Quý</div>
                                <div class="text-muted" style="font-size: 0.72rem;">Giám đốc điều hành / Người đại diện pháp luật</div>
                            </div>
                        </div>

                        <div class="mt-3 pt-2 border-top text-center text-muted" style="font-size: 0.72rem;">
                            <div>Chứng từ chi tiền điện tử bảo mật được xác thực trên hệ thống <?= htmlspecialchars(APP_NAME) ?>. Hotline: 0355879036.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-2 px-4 justify-content-between">
                    <span class="text-muted small"><i class="fa-solid fa-shield-check text-success me-1"></i> Chứng từ thanh toán xác thực hợp pháp</span>
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ==========================================================
     * DIALOG SVG ANIMATION (THÔNG BÁO & XÁC NHẬN ĐỒNG BỘ)
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
        // ==========================================================
        // 1. ĐIỀU KHIỂN SIDEBAR & USER POPUP MENU
        // ==========================================================
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

        document.addEventListener('click', function(e) {
            const container = document.getElementById('userDropdownContainer');
            if (container && !container.contains(e.target)) {
                closeUserPopup();
            }
        });

        // ==========================================================
        // 2. TƯƠNG TÁC THẺ ATM MÔ PHỎNG & FORM RÚT TIỀN
        // ==========================================================
        const userBalance = <?= (float)$currentUser['balance'] ?>;

        function updateBankSelection(select) {
            const selectedOpt = select.options[select.selectedIndex];
            const fullName = selectedOpt.getAttribute('data-fullname') || selectedOpt.text;
            const bankCode = selectedOpt.value;

            document.getElementById('selectedBankName').value = fullName;
            const previewBadge = document.getElementById('previewBankBadge');
            if (previewBadge) previewBadge.innerText = bankCode;
        }

        function updateCardNumber(input) {
            const val = input.value.trim();
            const preview = document.getElementById('previewCardNumber');
            if (!preview) return;
            if (val === '') {
                preview.innerText = '•••• •••• •••• ••••';
            } else {
                preview.innerText = val.replace(/(.{4})/g, '$1 ').trim();
            }
        }

        function updateCardHolder(input) {
            input.value = input.value.toUpperCase();
            const val = input.value.trim();
            const preview = document.getElementById('previewCardHolder');
            if (!preview) return;
            preview.innerText = val === '' ? 'NGUOI THU HUONG' : val;
        }

        function formatWithdrawInput(input) {
            let val = input.value.replace(/\D/g, '');
            if (val === '') {
                input.value = '';
                updateSummary(0);
                return;
            }
            let num = parseInt(val, 10);
            input.value = new Intl.NumberFormat('vi-VN').format(num);
            updateSummary(num);
        }

        function selectWithdrawAmount(amount, btn) {
            document.querySelectorAll('.amount-pill-btn').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');

            const input = document.getElementById('amountInput');
            if (input) {
                input.value = new Intl.NumberFormat('vi-VN').format(amount);
                updateSummary(amount);
            }
        }

        function updateSummary(amount) {
            const formatted = new Intl.NumberFormat('vi-VN').format(amount) + ' ₫';
            const remain = Math.max(0, userBalance - amount);
            const formattedRemain = new Intl.NumberFormat('vi-VN').format(remain) + ' ₫';

            const cardAmt = document.getElementById('previewCardAmount');
            if (cardAmt) cardAmt.innerText = formatted;

            const sumAmt = document.getElementById('summaryWithdrawAmount');
            if (sumAmt) sumAmt.innerText = formatted;

            const sumRemain = document.getElementById('summaryRemainBalance');
            if (sumRemain) sumRemain.innerText = formattedRemain;
        }

        // ==========================================================
        // 3. DIALOG HIỆU ỨNG SVG STROKE DRAW ANIMATION (CHUẨN 1:1)
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
                `Bạn có chắc chắn muốn hủy yêu cầu rút tiền <strong>#${code}</strong>?<br><span class="text-success small mt-2 d-block"><i class="fa-solid fa-rotate-left me-1"></i> Số tiền <strong>${amountFormatted}</strong> sẽ được hoàn trả ngay lập tức vào số dư tài khoản của bạn.</span>`,
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

        // Bắt sự kiện kiểm tra hợp lệ form rút tiền với SVG Dialog
        const withdrawalFormEl = document.getElementById('withdrawalForm');
        if (withdrawalFormEl) {
            withdrawalFormEl.addEventListener('submit', function(e) {
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
                    showSvgAlert('Hạn mức rút tiền tối đa là <strong>50.000.000 ₫</strong> cho mỗi giao dịch.', 'Vượt quá hạn mức', 'warning');
                    return false;
                }
            });
        }

        // ==========================================================
        // 4. XỬ LÝ PHIẾU CHI TIỀN ĐIỆN TỬ, IN ẤN & XUẤT PDF
        // ==========================================================
        const USER_SIGNATURE_BASE64 = <?= json_encode($signatureBase64) ?>;
        let currentVoucherData = null;

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

        function generateBarcodePng(code) {
            const seed = (code || 'RUT928475').toString().replace(/\D/g, '') + '192847';
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
                return `<img src="${pngUrl}" alt="Barcode ${code}" style="width: 180px; height: 24px; display: inline-block; image-rendering: pixelated;" />`;
            } catch (e) {
                return '';
            }
        }

        function showWithdrawalVoucher(data) {
            if (!data) return;
            currentVoucherData = data;

            document.getElementById('voucherCode').innerText = '#' + data.code;
            
            // Ngày tháng
            const locDateEl = document.getElementById('voucherDate');
            if (locDateEl && data.created_at) {
                const datePart = data.created_at.split(' ')[0];
                if (datePart.includes('/')) {
                    const [d, m, y] = datePart.split('/');
                    locDateEl.innerText = `TP. Hồ Chí Minh, ngày ${d} tháng ${m} năm ${y}`;
                } else {
                    locDateEl.innerText = 'TP. Hồ Chí Minh, ' + data.created_at;
                }
            }

            // Trạng thái
            const stEl = document.getElementById('voucherStatusBadge');
            if (stEl) {
                let badge = '<span class="badge bg-success text-white px-2 py-0 rounded-pill">ĐÃ HOÀN THÀNH</span>';
                if (data.status === 'Pending') badge = '<span class="badge bg-warning text-dark px-2 py-0 rounded-pill">ĐANG CHỜ DUYỆT</span>';
                else if (data.status === 'Cancelled') badge = '<span class="badge bg-secondary text-white px-2 py-0 rounded-pill">ĐÃ HỦY</span>';
                else if (data.status === 'Failed') badge = '<span class="badge bg-danger text-white px-2 py-0 rounded-pill">THẤT BẠI</span>';
                stEl.innerHTML = badge;
            }

            // Khách hàng
            const cleanUser = (data.customer_username || '').replace(/^@+/, '');
            document.getElementById('voucherCustomerName').innerText = data.customer_name || cleanUser;
            document.getElementById('voucherCustomerUsername').innerText = '@' + cleanUser;
            document.getElementById('voucherCustomerEmail').innerText = data.customer_email || 'Chưa cập nhật';
            document.getElementById('voucherSignCustomerName').innerText = data.customer_name || cleanUser;

            // Ngân hàng
            document.getElementById('voucherBankName').innerText = data.bank_name;
            document.getElementById('voucherAccountNumber').innerText = data.account_number;
            document.getElementById('voucherAccountName').innerText = data.account_name;

            // Số tiền
            document.getElementById('voucherAmount').innerText = data.amount_formatted;
            document.getElementById('voucherNetAmount').innerText = data.net_amount_formatted;
            document.getElementById('voucherTotalAmount').innerText = data.net_amount_formatted;
            document.getElementById('voucherAmountWords').innerText = docSoTien(data.net_amount);

            // Barcode
            document.getElementById('voucherBarcodeContainer').innerHTML = generateBarcodePng(data.code);

            // Chữ ký thật trong suốt
            const sigBox = document.getElementById('voucherSignatureContainer');
            if (sigBox) {
                if (USER_SIGNATURE_BASE64) {
                    sigBox.innerHTML = `<img src="${USER_SIGNATURE_BASE64}" alt="Chữ ký Phan Thành Quý" style="max-height: 60px; max-width: 155px; mix-blend-mode: multiply; filter: contrast(1.2); display: inline-block;" />`;
                } else {
                    sigBox.innerHTML = `<span style="font-family: 'Brush Script MT', cursive; font-size: 22px; color: #1d4ed8; font-weight: bold;">Phan Thành Quý</span>`;
                }
            }

            // Mở modal
            const modalEl = document.getElementById('withdrawalVoucherModal');
            if (modalEl) {
                let modalInstance = bootstrap.Modal.getInstance(modalEl);
                if (!modalInstance) modalInstance = new bootstrap.Modal(modalEl);
                modalInstance.show();
            }
        }

        // Xuất phiếu chi dạng PDF chuẩn Full-Width
        function exportWithdrawVoucherToPdf() {
            if (!currentVoucherData) return;
            const element = document.getElementById('voucherPrintArea');
            if (!element) return;

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
                    backgroundColor: '#ffffff'
                }).then(canvas => {
                    const pdf = new jsPdfClass('p', 'mm', 'a4');
                    const pageWidth = pdf.internal.pageSize.getWidth();
                    const margin = 8;
                    const imgWidth = pageWidth - (margin * 2);
                    const imgHeight = (canvas.height * imgWidth) / canvas.width;
                    const imgData = canvas.toDataURL('image/jpeg', 0.98);

                    pdf.addImage(imgData, 'JPEG', margin, margin, imgWidth, imgHeight);
                    pdf.save(`Phieu_Chi_${currentVoucherData.code}.pdf`);

                    showSvgAlert('Đã kết xuất và tải xuống phiếu chi giải ngân PDF thành công!', 'Xuất file thành công', 'success');
                }).catch(err => {
                    console.error(err);
                    window.print();
                });
            } else {
                window.print();
            }
        }

        // In phiếu chi A4 chuẩn 1:1 không trang trắng thừa
        function printWithdrawVoucher() {
            const card = document.getElementById('voucherPrintArea');
            if (!card) {
                window.print();
                return;
            }

            let printFrame = document.getElementById('voucherPrintIframe');
            if (!printFrame) {
                printFrame = document.createElement('iframe');
                printFrame.id = 'voucherPrintIframe';
                printFrame.style.position = 'fixed';
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
                    <title>Phieu_Chi_${currentVoucherData ? currentVoucherData.code : ''}</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        @page { size: A4 portrait; margin: 6mm 8mm; }
                        body { margin: 0; padding: 0; background: #ffffff; font-family: system-ui, -apple-system, sans-serif; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
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
