<?php
/**
 * ==========================================================
 * TRANG NẠP TIỀN TỰ ĐỘNG QUA NGÂN HÀNG & VIETQR (VIETNAMESE QR)
 * File: deposit.php & payments/deposit.php
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
// 1. TỰ ĐỘNG KHỞI TẠO BẢNG CSDL NẾU CHƯA CÓ
// ----------------------------------------------------------
try {
    // Bảng tài khoản ngân hàng của Admin
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `bank_accounts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `bank_code` VARCHAR(20) NOT NULL COMMENT 'Mã chuẩn VietQR (MB, VCB, TCB, ACB...)',
            `bank_name` VARCHAR(100) NOT NULL,
            `account_number` VARCHAR(50) NOT NULL,
            `account_name` VARCHAR(100) NOT NULL,
            `branch` VARCHAR(100) DEFAULT NULL,
            `qr_template` VARCHAR(20) NOT NULL DEFAULT 'compact2',
            `min_deposit` DECIMAL(15, 2) NOT NULL DEFAULT 10000.00,
            `max_deposit` DECIMAL(15, 2) NOT NULL DEFAULT 50000000.00,
            `is_default` TINYINT(1) NOT NULL DEFAULT 0,
            `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_ba_bank_code` (`bank_code`),
            INDEX `idx_ba_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Bảng lệnh nạp tiền của người dùng
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `deposits` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_uuid` CHAR(36) NOT NULL,
            `deposit_code` VARCHAR(50) NOT NULL UNIQUE,
            `bank_id` INT DEFAULT NULL,
            `bank_name` VARCHAR(100) NOT NULL,
            `account_number` VARCHAR(50) NOT NULL,
            `account_name` VARCHAR(100) NOT NULL,
            `amount` DECIMAL(15, 2) NOT NULL,
            `transfer_content` VARCHAR(100) NOT NULL,
            `status` ENUM('Pending', 'Success', 'Failed', 'Cancelled') NOT NULL DEFAULT 'Pending',
            `proof_image` VARCHAR(255) DEFAULT NULL,
            `admin_note` VARCHAR(255) DEFAULT NULL,
            `approved_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_dep_user_uuid` (`user_uuid`),
            INDEX `idx_dep_code` (`deposit_code`),
            INDEX `idx_dep_status` (`status`),
            INDEX `idx_dep_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Tự động đảm bảo và đồng bộ ngân hàng Admin TPBank với STK và Chủ TK mới nhất
    $pdo->exec("
        UPDATE `bank_accounts` 
        SET `account_number` = '10004397102', `account_name` = 'PHAN THANH QUY'
        WHERE `bank_code` = 'TPB' OR `id` = 1;

        UPDATE `deposits` 
        SET `account_number` = '10004397102', `account_name` = 'PHAN THANH QUY'
        WHERE `account_number` = '0987654321' OR `account_name` = 'TRAN THANH QUY';
    ");

    $stmtCheckTpb = $pdo->query("SELECT COUNT(*) FROM bank_accounts WHERE bank_code = 'TPB'");
    if ($stmtCheckTpb->fetchColumn() == 0) {
        $pdo->exec("
            DELETE FROM `bank_accounts`;
            INSERT INTO `bank_accounts` (`id`, `bank_code`, `bank_name`, `account_number`, `account_name`, `branch`, `qr_template`, `min_deposit`, `max_deposit`, `is_default`, `status`) VALUES
            (1, 'TPB', 'TPBank (Ngân Hàng Tiên Phong)', '10004397102', 'PHAN THANH QUY', 'Hội Sở Chính Hà Nội', 'compact2', 10000.00, 50000000.00, 1, 'Active');
        ");
    }
} catch (Exception $e) {
    // Ghi log nếu lỗi
    error_log("Deposit Table Setup Error: " . $e->getMessage());
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

// Lấy duy nhất ngân hàng TPBank của Admin đang hoạt động
$stmtBanks = $pdo->query("SELECT * FROM bank_accounts WHERE bank_code = 'TPB' AND status = 'Active' ORDER BY is_default DESC, id ASC LIMIT 1");
$bankAccounts = $stmtBanks->fetchAll();
if (empty($bankAccounts)) {
    $stmtBanks = $pdo->query("SELECT * FROM bank_accounts WHERE status = 'Active' ORDER BY is_default DESC, id ASC LIMIT 1");
    $bankAccounts = $stmtBanks->fetchAll();
}

// ----------------------------------------------------------
// 3. XỬ LÝ POST: TẠO LỆNH NẠP TIỀN HOẶC HỦY LỆNH
// ----------------------------------------------------------
$activeDeposit = null; // Lệnh nạp đang mở để quét QR
$redirectRoute = (strpos($_SERVER['REQUEST_URI'] ?? '', '/payments/deposit') !== false) ? '/payments/deposit' : 'deposit.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($submittedToken)) {
        set_flash('error', 'Yêu cầu không hợp lệ hoặc phiên bảo mật đã hết hạn. Vui lòng tải lại trang.', 'Bảo Mật CSRF');
        header("Location: " . $redirectRoute);
        exit;
    }

    // A. TẠO LỆNH NẠP TIỀN MỚI
    if ($action === 'create_deposit') {
        $bankId = (int)($_POST['bank_id'] ?? 0);
        $amount = (float)str_replace(['.', ',', ' '], '', $_POST['amount'] ?? '0');

        // Tìm ngân hàng được chọn
        $selectedBank = null;
        foreach ($bankAccounts as $b) {
            if ($b['id'] == $bankId) {
                $selectedBank = $b;
                break;
            }
        }

        if (!$selectedBank && !empty($bankAccounts)) {
            $selectedBank = $bankAccounts[0];
        }

        if (!$selectedBank) {
            set_flash('warning', 'Vui lòng chọn ngân hàng bạn muốn chuyển tiền vào.', 'Chưa Chọn Ngân Hàng');
            header("Location: " . $redirectRoute);
            exit;
        }

        $minDep = (float)$selectedBank['min_deposit'];
        $maxDep = (float)$selectedBank['max_deposit'];

        if ($amount < $minDep) {
            set_flash('warning', 'Số tiền nạp tối thiểu là ' . format_currency($minDep) . '.', 'Số Tiền Không Hợp Lệ');
            header("Location: " . $redirectRoute);
            exit;
        }

        if ($amount > $maxDep) {
            set_flash('warning', 'Số tiền nạp tối đa là ' . format_currency($maxDep) . '.', 'Số Tiền Quá Lớn');
            header("Location: " . $redirectRoute);
            exit;
        }

        // Tạo mã ngẫu nhiên 7 số: Cú pháp ThanhQuyTech(mã 7 số random)
        $random7Digits = (string)mt_rand(1000000, 9999999);
        $depositCode = $random7Digits;
        $transferContent = 'ThanhQuyTech' . $random7Digits;

        try {
            $stmtInsert = $pdo->prepare("
                INSERT INTO deposits (user_uuid, deposit_code, bank_id, bank_name, account_number, account_name, amount, transfer_content, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
            ");
            $stmtInsert->execute([
                $currentUser['uuid'],
                $depositCode,
                $selectedBank['id'],
                $selectedBank['bank_name'],
                $selectedBank['account_number'],
                $selectedBank['account_name'],
                $amount,
                $transferContent
            ]);

            set_flash('success', 'Đã tạo lệnh nạp #' . $depositCode . ' thành công! Vui lòng quét mã VietQR TPBank bên dưới để hoàn tất chuyển tiền.', 'Lệnh Nạp Sẵn Sàng');
            $redirectUrl = (strpos($redirectRoute, '?') !== false) ? ($redirectRoute . '&code=' . urlencode($depositCode)) : ($redirectRoute . '?code=' . urlencode($depositCode));
            header("Location: " . $redirectUrl);
            exit;

        } catch (Exception $e) {
            set_flash('error', 'Lỗi hệ thống khi tạo lệnh nạp: ' . $e->getMessage(), 'Lỗi Xử Lý');
            header("Location: " . $redirectRoute);
            exit;
        }
    }

    // B. HỦY LỆNH NẠP TIỀN
    if ($action === 'cancel_deposit') {
        $depId = (int)($_POST['deposit_id'] ?? 0);
        $stmtCancel = $pdo->prepare("
            UPDATE deposits 
            SET status = 'Cancelled' 
            WHERE id = ? AND user_uuid = ? AND status = 'Pending'
        ");
        $stmtCancel->execute([$depId, $currentUser['uuid']]);

        if ($stmtCancel->rowCount() > 0) {
            set_flash('info', 'Đã hủy lệnh nạp tiền thành công.', 'Đã Hủy Lệnh');
        } else {
            set_flash('warning', 'Không tìm thấy lệnh nạp cần hủy hoặc lệnh đã được xử lý trước đó.', 'Thông Báo');
        }
        header("Location: " . $redirectRoute);
        exit;
    }
}

// ----------------------------------------------------------
// 4. LẤY LỆNH NẠP TIỀN HIỆN TẠI ĐỂ HIỂN THỊ VIETQR
// ----------------------------------------------------------
// CHỈ HIỂN THỊ MÃ VIETQR KHI NGƯỜI DÙNG BẤM TẠO LỆNH HOẶC TRUYỀN CODE CỤ THỂ
$activeDeposit = null;
$requestedCode = trim($_GET['code'] ?? '');
if (!empty($requestedCode)) {
    $stmtFindDep = $pdo->prepare("
        SELECT d.*, b.bank_code, b.qr_template 
        FROM deposits d
        LEFT JOIN bank_accounts b ON d.bank_id = b.id
        WHERE d.deposit_code = ? AND d.user_uuid = ?
        LIMIT 1
    ");
    $stmtFindDep->execute([$requestedCode, $currentUser['uuid']]);
    $activeDeposit = $stmtFindDep->fetch();
}

$defaultBank = !empty($bankAccounts) ? $bankAccounts[0] : null;

// ----------------------------------------------------------
// 5. THỐNG KÊ VÀ LỊCH SỬ NẠP TIỀN CỦA NGƯỜI DÙNG
// ----------------------------------------------------------
// Lịch sử 50 giao dịch gần nhất
$stmtHistory = $pdo->prepare("
    SELECT * FROM deposits 
    WHERE user_uuid = ? 
    ORDER BY id DESC 
    LIMIT 50
");
$stmtHistory->execute([$currentUser['uuid']]);
$depositHistory = $stmtHistory->fetchAll();

// Tổng nạp thành công
$stmtTotalSuccess = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_amount, COUNT(*) AS total_count 
    FROM deposits 
    WHERE user_uuid = ? AND status = 'Success'
");
$stmtTotalSuccess->execute([$currentUser['uuid']]);
$statsSuccess = $stmtTotalSuccess->fetch();
$totalDeposited = (float)($statsSuccess['total_amount'] ?? 0);
$successCount = (int)($statsSuccess['total_count'] ?? 0);

// Đếm số lệnh đang chờ
$stmtPendingCount = $pdo->prepare("
    SELECT COUNT(*) FROM deposits 
    WHERE user_uuid = ? AND status = 'Pending'
");
$stmtPendingCount->execute([$currentUser['uuid']]);
$pendingCount = (int)$stmtPendingCount->fetchColumn();

// Đọc cài đặt ẩn/hiện số dư trên thanh tiêu đề
$stmtHide = $pdo->prepare("SELECT setting_value FROM settings WHERE user_uuid = ? AND setting_key = 'hide_balance_header' LIMIT 1");
$stmtHide->execute([$currentUser['uuid']]);
$hideBalance = ($stmtHide->fetchColumn() === '1');

$flash = get_flash();
$csrfToken = get_csrf_token();

// Cấu hình SĐT và Website động theo tên miền hiện tại
$dynamicHost = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'thanhquytech.vn');
$dynamicHost = preg_replace('/:\d+$/', '', $dynamicHost);
$companyPhone = '0355879036';

// Đọc và chuẩn bị chữ ký người đại diện Phan Thành Quý (Xử lý lọc nét ký trong suốt, khử sạch nền trắng)
$signatureSource = 'C:/Users/admin/.gemini/antigravity-ide/brain/9b9e3d7d-8ebc-4447-93f8-00568d8227e1/.user_uploaded/media_1789531409754.png';
$signatureLocalRaw = __DIR__ . '/assets/images/signature.png';
$signatureLocalTrans = __DIR__ . '/assets/images/signature_transparent.png';

if (file_exists($signatureSource) && (!file_exists($signatureLocalRaw) || filesize($signatureLocalRaw) === 0)) {
    @copy($signatureSource, $signatureLocalRaw);
}

// Xử lý tạo ảnh chữ ký trong suốt (chỉ giữ nét mực đen, loại bỏ 100% nền trắng)
if (!file_exists($signatureLocalTrans) || filesize($signatureLocalTrans) === 0) {
    $srcToProcess = file_exists($signatureLocalRaw) ? $signatureLocalRaw : (file_exists($signatureSource) ? $signatureSource : null);
    if ($srcToProcess && function_exists('imagecreatefrompng')) {
        $srcImg = @imagecreatefrompng($srcToProcess);
        if ($srcImg) {
            $w = imagesx($srcImg);
            $h = imagesy($srcImg);
            $transImg = imagecreatetruecolor($w, $h);
            imagealphablending($transImg, false);
            imagesavealpha($transImg, true);
            $transparentColor = imagecolorallocatealpha($transImg, 0, 0, 0, 127);
            imagefilledrectangle($transImg, 0, 0, $w, $h, $transparentColor);

            for ($x = 0; $x < $w; $x++) {
                for ($y = 0; $y < $h; $y++) {
                    $rgb = imagecolorat($srcImg, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    // Độ sáng (0: đen, 255: trắng)
                    $brightness = ($r * 299 + $g * 587 + $b * 114) / 1000;
                    if ($brightness > 210) {
                        // Nền trắng -> Trong suốt hoàn toàn
                        imagesetpixel($transImg, $x, $y, $transparentColor);
                    } else {
                        // Nét mực ký -> Giữ màu đen đậm và làm mềm viền (anti-aliasing)
                        $alpha = (int)(($brightness / 210) * 55);
                        $ink = imagecolorallocatealpha($transImg, 15, 23, 42, $alpha);
                        imagesetpixel($transImg, $x, $y, $ink);
                    }
                }
            }
            @imagepng($transImg, $signatureLocalTrans);
            imagedestroy($srcImg);
            imagedestroy($transImg);
        }
    }
}

$signatureBase64 = '';
if (file_exists($signatureLocalTrans) && filesize($signatureLocalTrans) > 0) {
    $signatureBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($signatureLocalTrans));
} elseif (file_exists($signatureLocalRaw) && filesize($signatureLocalRaw) > 0) {
    $signatureBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($signatureLocalRaw));
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
    <title>Nạp Tiền Tài Khoản Tự Động - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico">

    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Fira+Code:wght@400;500;600&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- html2pdf.js: Xuất hóa đơn sang file PDF chất lượng cao không thể chỉnh sửa -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

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

        /* ==========================================================
         * 1. THANH ĐIỀU HƯỚNG CỐ ĐỊNH TRÊN ĐẦU (HEADER)
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

        /* ==========================================================
         * 3. NỘI DUNG CHÍNH (APP MAIN)
         * ========================================================== */
        .app-main {
            margin-left: 260px;
            padding-top: 70px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
            background-color: var(--bg-body);
        }

        .content-container {
            padding: 28px;
            flex-grow: 1;
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
        }

        /* BREADCRUMB */
        .breadcrumb-custom {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 20px;
        }

        .breadcrumb-custom a {
            color: var(--text-muted);
            text-decoration: none;
            transition: var(--transition);
        }

        .breadcrumb-custom a:hover {
            color: var(--primary);
        }

        .breadcrumb-custom i {
            font-size: 0.72rem;
            opacity: 0.6;
        }

        /* HERO BANNER */
        .deposit-hero {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
            border-radius: var(--radius-lg);
            padding: 32px 36px;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 35px -10px rgba(30, 27, 75, 0.35);
            margin-bottom: 28px;
        }

        .deposit-hero::before {
            content: "";
            position: absolute;
            top: -60px;
            right: -60px;
            width: 280px;
            height: 280px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.4) 0%, transparent 70%);
            pointer-events: none;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            color: #a5b4fc;
            margin-bottom: 12px;
        }

        .hero-title {
            font-size: 1.85rem;
            font-weight: 800;
            line-height: 1.25;
            margin-bottom: 10px;
            letter-spacing: -0.5px;
        }

        .hero-desc {
            color: #cbd5e1;
            font-size: 0.95rem;
            max-width: 780px;
            line-height: 1.6;
            margin-bottom: 0;
        }

        /* THẺ THỐNG KÊ NHANH */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            padding: 18px 20px;
            box-shadow: var(--shadow-card);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px -10px rgba(0, 0, 0, 0.08);
            border-color: #cbd5e1;
        }

        .stat-card.card-balance-highlight {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border-color: #86efac;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
        }

        .stat-info {
            flex-grow: 1;
            min-width: 0;
        }

        .stat-label {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }

        .stat-val {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--text-heading);
            white-space: nowrap;
        }

        /* BỐ CỤC CÁC CARD NẠP TIỀN (MỖI HÀNG 1 CARD ĐỘC LẬP) */
        .deposit-layout {
            display: flex;
            flex-direction: column;
            gap: 24px;
            margin-bottom: 30px;
        }

        .deposit-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 28px 32px;
            box-shadow: var(--shadow-card);
            width: 100%;
        }

        .card-header-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
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

        /* THẺ CHỌN NGÂN HÀNG TPBANK TINH TẾ & GỌN GÀNG */
        .bank-card-premium {
            background: linear-gradient(135deg, #ffffff 0%, #f8faff 50%, #eef2ff 100%);
            border: 2px solid #6366f1;
            border-radius: 14px;
            padding: 12px 18px;
            box-shadow: 0 4px 14px -2px rgba(79, 70, 229, 0.12), 0 1px 3px rgba(0, 0, 0, 0.02);
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .bank-brand-info {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .bank-badge-code {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 1rem;
            color: #ffffff;
            box-shadow: 0 3px 8px rgba(79, 70, 229, 0.3);
            flex-shrink: 0;
            letter-spacing: 0.5px;
        }

        .bank-title {
            font-size: 1.08rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
            letter-spacing: -0.2px;
        }

        .bank-status-tag {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
            box-shadow: 0 2px 6px rgba(16, 185, 129, 0.08);
            flex-shrink: 0;
        }

        /* NÚT CHỌN NHANH SỐ TIỀN CHUẨN PILL BO TRÒN */
        .amount-quick-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }

        .amount-pill-btn {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            padding: 8px 18px;
            border-radius: 50px;
            font-size: 0.88rem;
            font-weight: 700;
            color: #334155;
            cursor: pointer;
            transition: all 0.2s ease;
            outline: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .amount-pill-btn:hover {
            background: #eef2ff;
            color: #4f46e5;
            border-color: #c7d2fe;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.15);
        }

        .amount-pill-btn.active {
            background: #4f46e5 !important;
            color: #ffffff !important;
            border-color: #4f46e5 !important;
        }

        /* KHUNG NHẬP TIỀN CAO CẤP HIỆN ĐẠI (FINTECH STYLE - GỌN GÀNG TINH TẾ) */
        .premium-amount-box {
            background: #ffffff;
            border: 2px solid #e2e8f0;
            border-radius: 13px;
            padding: 6px 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .premium-amount-box:focus-within {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12), 0 4px 14px rgba(99, 102, 241, 0.06);
            background: #ffffff;
        }

        .currency-symbol-badge {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
            color: #4f46e5;
            border: 1.5px solid #c7d2fe;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.18rem;
            font-weight: 800;
            flex-shrink: 0;
            box-shadow: 0 2px 6px rgba(79, 70, 229, 0.10);
        }

        .amount-field-inner {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            justify-content: center;
        }

        .amount-field-label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #94a3b8;
            margin-bottom: 0px;
            line-height: 1.1;
        }

        .amount-input-control {
            border: none !important;
            outline: none !important;
            box-shadow: none !important;
            background: transparent !important;
            padding: 0 !important;
            font-size: 1.3rem;
            font-weight: 800;
            color: #0f172a;
            font-family: 'Plus Jakarta Sans', sans-serif;
            letter-spacing: -0.3px;
            width: 100%;
            height: auto !important;
            line-height: 1.2;
        }

        .amount-input-control::placeholder {
            color: #cbd5e1;
            font-size: 0.95rem;
            font-weight: 500;
            letter-spacing: 0;
        }

        .currency-tag-pill {
            background: #f8fafc;
            color: #475569;
            font-weight: 800;
            font-size: 0.76rem;
            padding: 4px 10px;
            border-radius: 8px;
            letter-spacing: 0.5px;
            border: 1px solid #e2e8f0;
            flex-shrink: 0;
        }

        .btn-gradient-primary {
            background: var(--gradient-primary);
            color: #ffffff;
            font-weight: 700;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            box-shadow: 0 6px 18px rgba(79, 70, 229, 0.35);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            width: 100%;
            font-size: 1rem;
        }

        .btn-gradient-primary:hover {
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(79, 70, 229, 0.45);
        }

        /* KHUNG VIETQR HIỆN BÊN DƯỚI (MỖI HÀNG 1 CARD ĐỘC LẬP) */
        .qr-display-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-top: 4px solid var(--primary);
            border-radius: var(--radius-lg);
            padding: 28px 32px;
            box-shadow: var(--shadow-card);
            position: relative;
            width: 100%;
        }

        .qr-card-body-grid {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 32px;
            align-items: center;
        }

        @media (max-width: 991.98px) {
            .qr-card-body-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .deposit-card,
            .qr-display-card {
                padding: 20px 18px;
            }
        }

        .qr-image-wrapper {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 12px;
            border-radius: 16px;
            display: inline-block;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08);
            margin-bottom: 14px;
            max-width: 100%;
        }

        .qr-image-wrapper img {
            width: 250px;
            height: auto;
            max-width: 100%;
            display: block;
            border-radius: 8px;
        }

        /* BẢNG THÔNG TIN CHUYỂN KHOẢN CHI TIẾT */
        .transfer-details-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px;
            text-align: left;
            margin-bottom: 16px;
        }

        .transfer-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #e2e8f0;
            font-size: 0.88rem;
        }

        .transfer-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .transfer-label {
            color: var(--text-muted);
            font-weight: 600;
        }

        .transfer-val {
            font-weight: 700;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .transfer-val.highlight-code {
            color: #dc2626;
            font-size: 1.05rem;
            font-family: 'Fira Code', monospace;
            background: #fee2e2;
            padding: 2px 8px;
            border-radius: 6px;
            letter-spacing: 0.5px;
        }

        .btn-copy-mini {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: var(--text-body);
            border-radius: 6px;
            padding: 2px 8px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-copy-mini:hover {
            background: #eef2ff;
            color: var(--primary);
            border-color: #c7d2fe;
        }

        /* BẢNG LỊCH SỬ CHUẨN BUY-KEY */
        .dash-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-card);
            margin-bottom: 24px;
            width: 100%;
        }

        .table-responsive {
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch !important;
            scrollbar-width: thin;
        }

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

        .history-table {
            width: 100%;
            min-width: 820px;
            border-collapse: separate;
            border-spacing: 0;
        }

        .history-table th,
        .history-table td {
            text-align: center !important;
            vertical-align: middle !important;
        }

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

        .history-table th i {
            font-size: 0.82rem;
            vertical-align: middle;
        }

        .history-table td {
            padding: 14px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.88rem;
            white-space: nowrap;
        }

        .history-table td div {
            text-align: center !important;
        }

        .history-table tr:hover td {
            background: #f8faff;
        }

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
            font-size: 0.85rem;
            color: #0f172a;
        }

        .btn-copy-key {
            border: none;
            background: #e2e8f0;
            color: #475569;
            width: 26px;
            height: 26px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-copy-key:hover {
            background: var(--primary);
            color: #ffffff;
        }

        .btn-copy-minimal {
            border: none;
            background: transparent;
            color: #94a3b8;
            padding: 3px 6px;
            font-size: 0.88rem;
            cursor: pointer;
            transition: var(--transition);
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            vertical-align: middle;
        }

        .btn-copy-minimal:hover {
            color: var(--primary);
            background: #eff6ff;
        }

        /* Các nút bấm thao tác trong bảng lịch sử giao dịch nạp tiền */
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
            line-height: 1.2;
        }

        .btn-action-view:hover {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 3px 8px rgba(37, 99, 235, 0.25);
            transform: translateY(-1px);
        }

        .btn-action-qr {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
            color: #ffffff;
            border: 1px solid #4f46e5;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.76rem;
            font-weight: 700;
            transition: var(--transition);
            box-shadow: 0 2px 6px rgba(79, 70, 229, 0.2);
            text-decoration: none;
            cursor: pointer;
            line-height: 1.2;
        }

        .btn-action-qr:hover {
            background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
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
            cursor: pointer;
            line-height: 1.2;
        }

        .btn-action-cancel:hover {
            background: #e11d48;
            color: #ffffff;
            border-color: #e11d48;
            box-shadow: 0 3px 8px rgba(225, 29, 72, 0.25);
            transform: translateY(-1px);
        }

        /* ==========================================================
         * HÓA ĐƠN CHI TIẾT NẠP TIỀN & XUẤT WORD
        /* ==========================================================
         * CSS HÓA ĐƠN DOANH NGHIỆP B2B - CHUẨN IN & PDF 1:1 TRÊN 1 TRANG DUY NHẤT
         * ========================================================== */
        .invoice-card {
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 12px !important;
            padding: 18px 22px !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04) !important;
            color: #0f172a !important;
            width: 100% !important;
            max-width: 740px !important;
            margin: 0 auto !important;
            font-size: 0.82rem !important;
            line-height: 1.35 !important;
            position: relative !important;
            page-break-inside: avoid !important;
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

        .invoice-barcode-svg,
        .invoice-barcode-img {
            width: 180px;
            height: 24px;
            display: inline-block;
            image-rendering: pixelated;
        }

        /* Bảng thông tin khách hàng và thụ hưởng (Kẻ khung rõ nét) */
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

        .meta-table {
            width: 100%;
            font-size: 0.77rem;
            border-collapse: collapse;
        }

        .meta-table td {
            padding: 2px 2px;
            vertical-align: top;
            border: none !important;
        }

        .meta-label {
            width: 38%;
            color: #64748b;
            white-space: nowrap;
        }

        .meta-val {
            color: #0f172a;
        }

        /* Bảng danh mục thanh toán có đầy đủ đường kẻ khung sắc nét chuẩn kế toán */
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
            vertical-align: middle !important;
        }

        .invoice-table td {
            padding: 4px 6px !important;
            border: 1px solid #94a3b8 !important;
            vertical-align: middle !important;
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
            pointer-events: none;
            z-index: 1;
        }

        .signature-stroke-img {
            max-height: 65px;
            max-width: 165px;
            mix-blend-mode: multiply;
            display: inline-block;
            background: transparent !important;
            filter: contrast(1.2);
            pointer-events: none;
            user-select: none;
        }

        .signature-handwriting {
            font-family: 'Brush Script MT', 'Dancing Script', cursive, sans-serif;
            font-size: 2rem;
            color: #1d4ed8;
            font-weight: 700;
            letter-spacing: 1px;
            display: inline-block;
            margin: 6px 0 2px;
            transform: rotate(-4deg);
            position: relative;
            z-index: 3;
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 5mm 8mm;
            }
            html, body {
                height: auto !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                overflow: visible !important;
            }
            /* Ẩn hoàn toàn tất cả các phần tử trang khác, không để lại chiều cao trống */
            body > *:not(#depositInvoiceModal):not(#invoicePrintIframe) {
                display: none !important;
            }
            #depositInvoiceModal {
                position: static !important;
                display: block !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                background: transparent !important;
                box-shadow: none !important;
                overflow: visible !important;
            }
            #depositInvoiceModal .modal-dialog {
                max-width: 100% !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                min-height: 0 !important;
                display: block !important;
                transform: none !important;
            }
            #depositInvoiceModal .modal-content {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                background: transparent !important;
                border-radius: 0 !important;
            }
            #depositInvoiceModal .modal-header, 
            #depositInvoiceModal .modal-footer, 
            #depositInvoiceModal .btn-close {
                display: none !important;
            }
            #depositInvoiceModal .modal-body {
                padding: 0 !important;
                margin: 0 !important;
                background: transparent !important;
                overflow: visible !important;
            }
            #invoiceCardPrintArea {
                border: 1px solid #cbd5e1 !important;
                border-radius: 8px !important;
                box-shadow: none !important;
                padding: 10px 14px !important;
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
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

        /* ==========================================================
         * 4. RESPONSIVE MEDIA QUERIES (CHUẨN 1:1 THEO INDEX & BUY-KEY)
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
                padding: 16px 12px 50px !important;
                overflow-x: hidden !important;
                box-sizing: border-box !important;
            }
            .content-container {
                padding: 16px 4px;
            }
            .deposit-layout {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            .stat-card {
                padding: 14px 12px;
            }
            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 1.1rem;
            }
            .stat-val {
                font-size: 1.1rem;
            }
            .deposit-card, .qr-display-card, .history-card {
                padding: 18px 14px;
            }
            .bank-options-grid {
                grid-template-columns: 1fr;
            }
            .hero-title {
                font-size: 1.35rem;
            }
            .hero-desc {
                font-size: 0.86rem;
            }
            .amount-input-control {
                font-size: 1.18rem !important;
            }
            .currency-symbol-badge {
                width: 34px !important;
                height: 34px !important;
                font-size: 1.05rem !important;
            }

            /* Tiêu đề Card trên mobile: Không bị ép chữ thành hàng dọc */
            .card-header-title {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 8px !important;
            }
            .card-title-text {
                font-size: 1.05rem !important;
                line-height: 1.35 !important;
                width: 100% !important;
            }

            /* Thẻ ngân hàng TPBank trên mobile */
            .bank-card-premium {
                padding: 10px 14px !important;
            }
            .bank-badge-code {
                width: 36px !important;
                height: 36px !important;
                font-size: 0.9rem !important;
            }
            .bank-title {
                font-size: 1rem !important;
            }
            .bank-status-tag {
                font-size: 0.7rem !important;
                padding: 4px 8px !important;
            }

            /* Nút chọn nhanh số tiền: Grid 4 cột cân đối 2 hàng */
            .amount-quick-pills {
                display: grid !important;
                grid-template-columns: repeat(4, 1fr) !important;
                gap: 8px !important;
            }
            .amount-pill-btn {
                padding: 7px 2px !important;
                font-size: 0.75rem !important;
                border-radius: 10px !important;
                text-align: center !important;
                justify-content: center !important;
                white-space: nowrap !important;
                width: 100% !important;
            }

            /* Bảng chi tiết chuyển khoản trong Card QR: Label trên, giá trị dưới không bị ép dòng */
            .transfer-details-box {
                padding: 12px 14px !important;
            }
            .transfer-row {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 5px !important;
                padding: 9px 0 !important;
            }
            .transfer-label {
                font-size: 0.78rem !important;
            }
            .transfer-val {
                width: 100% !important;
                justify-content: space-between !important;
                font-size: 0.95rem !important;
            }

            /* Đảm bảo bảng lịch sử luôn cuộn ngang 1 dòng mượt mà */
            .table-responsive {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                border-radius: 12px !important;
            }
            .history-table {
                min-width: 820px !important;
            }
            .history-table th,
            .history-table td {
                white-space: nowrap !important;
            }
        }

        @media (max-width: 420px) {
            .amount-quick-pills {
                grid-template-columns: repeat(4, 1fr) !important;
                gap: 5px !important;
            }
            .amount-pill-btn {
                font-size: 0.69rem !important;
                padding: 6px 1px !important;
            }
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
                    <span class="balance-val"><?= !empty($hideBalance) ? '****** đ' : format_currency($currentUser['balance']) ?></span>
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
                            <div class="fw-bold" style="color: #15803d; font-size: 0.95rem;"><?= !empty($hideBalance) ? '****** đ' : format_currency($currentUser['balance']) ?></div>
                        </div>
                        <a href="/payments/deposit" class="btn btn-sm btn-success rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;">
                            <i class="fa-solid fa-circle-arrow-down me-1"></i> Nạp tiền
                        </a>
                    </div>

                    <!-- Danh sách liên kết nhanh -->
                    <div class="d-flex flex-column gap-1">
                        <a href="profile.php" class="popup-menu-item">
                            <i class="fa-solid fa-user-gear me-2 text-primary"></i> Thông tin cá nhân
                        </a>
                        <a href="/payments/deposit" class="popup-menu-item" style="color: #4f46e5; background: #eef2ff;">
                            <i class="fa-solid fa-wallet me-2 text-success"></i> Nạp tiền tài khoản
                        </a>
                        <a href="buy-key.php" class="popup-menu-item">
                            <i class="fa-solid fa-key me-2 text-warning"></i> Mua key bản quyền
                        </a>
                        <a href="token.php" class="popup-menu-item">
                            <i class="fa-solid fa-fingerprint me-2 text-info"></i> Quản lý Access Token
                        </a>
                        <a href="settings.php" class="popup-menu-item">
                            <i class="fa-solid fa-gear me-2 text-secondary"></i> Cài đặt tài khoản
                        </a>
                        <?php if ($isAdmin): ?>
                        <a href="/admin/dashboard" class="popup-menu-item text-danger fw-bold">
                            <i class="fa-solid fa-shield-halved text-danger me-2"></i> Quản trị Admin
                        </a>
                        <?php endif; ?>
                        <hr class="my-2 border-secondary-subtle">
                        <a href="logout.php" class="popup-menu-item text-danger">
                            <i class="fa-solid fa-right-from-bracket me-2"></i> Đăng xuất
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- ==========================================================
     * 2. MENU SIDEBAR CỐ ĐỊNH TRÁI
     * ========================================================== -->
    <aside class="app-sidebar" id="appSidebar">
        <!-- Header cho Sidebar trên Mobile -->
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

            <!-- Payment (Đang mở) -->
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
                            <a href="/payments/deposit" class="submenu-link active">
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

    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeAppSidebar()"></div>

    <!-- ==========================================================
     * 3. NỘI DUNG CHÍNH (APP MAIN)
     * ========================================================== -->
    <main class="app-main">
        <div class="content-container">

            <!-- BREADCRUMB -->
            <div class="breadcrumb-custom">
                <a href="index.php"><i class="fa-solid fa-house me-1"></i> Trang chủ</a>
                <i class="fa-solid fa-chevron-right"></i>
                <span>Thanh toán</span>
                <i class="fa-solid fa-chevron-right"></i>
                <span class="text-primary">Nạp tiền tài khoản</span>
            </div>

            <!-- HERO BANNER -->
            <div class="deposit-hero">
                <div class="hero-badge">
                    <i class="fa-solid fa-bolt-lightning text-warning"></i> Cổng Nạp Tự Động VietQR 24/7
                </div>
                <h1 class="hero-title">Nạp Tiền Vào Ví Tài Khoản</h1>
                <p class="hero-desc">
                    Hệ thống tự động kiểm tra giao dịch và cộng số dư tức thì trong 30 giây đến 2 phút. Quý khách chỉ cần mở ứng dụng ngân hàng, quét mã VietQR và xác nhận chuyển khoản.
                </p>
            </div>

            <!-- 4 THẺ THỐNG KÊ NHANH (ĐỒNG BỘ MÀU SẮC & ICON RÕ NÉT) -->
            <div class="stats-grid">
                <!-- Thẻ 1: Số Dư Hiện Tại -->
                <div class="stat-card card-balance-highlight">
                    <div class="stat-icon" style="background: #ecfdf5; color: #10b981; border: 1.5px solid #a7f3d0; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.15);">
                        <i class="fa-solid fa-wallet"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label" style="color: #15803d;">Số Dư Hiện Tại</div>
                        <div class="stat-val" style="color: #166534;"><?= format_currency($currentUser['balance']) ?></div>
                    </div>
                </div>

                <!-- Thẻ 2: Tổng Tiền Đã Nạp -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: #eef2ff; color: #4f46e5; border: 1.5px solid #c7d2fe; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.15);">
                        <i class="fa-solid fa-circle-arrow-down"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Tổng Tiền Đã Nạp</div>
                        <div class="stat-val text-primary"><?= format_currency($totalDeposited) ?></div>
                    </div>
                </div>

                <!-- Thẻ 3: Đơn Nạp Thành Công -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: #ecfeff; color: #0891b2; border: 1.5px solid #a5f3fc; box-shadow: 0 4px 10px rgba(6, 182, 212, 0.15);">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Giao Dịch Thành Công</div>
                        <div class="stat-val" style="color: #0e7490;"><?= number_format($successCount) ?> đơn</div>
                    </div>
                </div>

                <!-- Thẻ 4: Lệnh Đang Chờ -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fffbeb; color: #d97706; border: 1.5px solid #fde68a; box-shadow: 0 4px 10px rgba(217, 119, 6, 0.15);">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Đang Chờ Xử Lý</div>
                        <div class="stat-val" style="color: #b45309;"><?= number_format($pendingCount) ?> lệnh</div>
                    </div>
                </div>
            </div>

            <!-- KHỐI CHÍNH: FORM NẠP TIỀN & KHUNG VIETQR -->
            <div class="deposit-layout">

                <!-- CARD 1: FORM TẠO LỆNH NẠP TIỀN (CHIẾM TRỌN 100% CHIỀU RỘNG) -->
                <div class="deposit-card">
                    <div class="card-header-title">
                        <div class="card-title-text">
                            <i class="fa-solid fa-money-bill-transfer text-primary"></i>
                            <span>Tạo Yêu Cầu Nạp Tiền</span>
                        </div>
                        <span class="badge bg-primary-subtle text-primary fw-bold px-3 py-1">Tự động VietQR 24/7</span>
                    </div>

                    <form action="<?= htmlspecialchars($redirectRoute) ?>" method="POST" id="depositForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="create_deposit">

                        <!-- BƯỚC 1: CHỌN NGÂN HÀNG THỤ HƯỞNG CỦA ADMIN (CHỈ CÓ TPBANK) -->
                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark mb-2">
                                1. Chọn ngân hàng / phương thức thanh toán: <span class="text-danger">*</span>
                            </label>
                            
                            <?php if (!empty($bankAccounts)): ?>
                                <?php $bank = $bankAccounts[0]; ?>
                                <input type="hidden" name="bank_id" value="<?= $bank['id'] ?>">
                                <div class="bank-card-premium">
                                    <div class="bank-brand-info">
                                        <div class="bank-badge-code">
                                            TPB
                                        </div>
                                        <div class="bank-title">TPBank</div>
                                    </div>
                                    <div class="bank-status-tag">
                                        <i class="fa-solid fa-bolt-lightning text-warning"></i>
                                        <span>Tự động 24/7 (Napas)</span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="col-12 text-muted small">Đang nạp thông tin ngân hàng TPBank...</div>
                            <?php endif; ?>
                        </div>

                        <!-- BƯỚC 2: NHẬP SỐ TIỀN CẦN NẠP -->
                        <div class="mb-4">
                            <label for="amountInput" class="form-label fw-bold text-dark mb-2">
                                2. Số tiền muốn nạp (VND): <span class="text-danger">*</span>
                            </label>

                            <!-- Các nút chọn nhanh số tiền bo tròn pill đẹp mắt -->
                            <div class="amount-quick-pills">
                                <button type="button" class="amount-pill-btn" onclick="selectQuickAmount(20000, this)">20.000 ₫</button>
                                <button type="button" class="amount-pill-btn" onclick="selectQuickAmount(50000, this)">50.000 ₫</button>
                                <button type="button" class="amount-pill-btn active" onclick="selectQuickAmount(100000, this)">100.000 ₫</button>
                                <button type="button" class="amount-pill-btn" onclick="selectQuickAmount(200000, this)">200.000 ₫</button>
                                <button type="button" class="amount-pill-btn" onclick="selectQuickAmount(500000, this)">500.000 ₫</button>
                                <button type="button" class="amount-pill-btn" onclick="selectQuickAmount(1000000, this)">1.000.000 ₫</button>
                                <button type="button" class="amount-pill-btn" onclick="selectQuickAmount(2000000, this)">2.000.000 ₫</button>
                                <button type="button" class="amount-pill-btn" onclick="selectQuickAmount(5000000, this)">5.000.000 ₫</button>
                            </div>

                            <!-- Khung nhập tiền cao cấp Fintech -->
                            <div class="premium-amount-box mb-2">
                                <div class="currency-symbol-badge">
                                    <i class="fa-solid fa-dong-sign"></i>
                                </div>
                                <div class="amount-field-inner">
                                    <label for="amountInput" class="amount-field-label">Số tiền muốn nạp</label>
                                    <input type="text" 
                                           class="amount-input-control" 
                                           id="amountInput" 
                                           name="amount" 
                                           value="100.000" 
                                           placeholder="100.000" 
                                           required
                                           autocomplete="off"
                                           oninput="formatCurrencyInput(this)">
                                </div>
                                <span class="currency-tag-pill">VND</span>
                            </div>
                            <div class="d-flex justify-content-between text-muted small mt-2 px-1">
                                <span><i class="fa-solid fa-circle-info text-primary me-1"></i> Tối thiểu: <strong class="text-dark">10.000 ₫</strong></span>
                                <span>Tối đa: <strong class="text-dark">50.000.000 ₫</strong></span>
                            </div>
                        </div>

                        <!-- NÚT TẠO LỆNH -->
                        <div class="mt-4">
                            <button type="submit" class="btn-gradient-primary">
                                <i class="fa-solid fa-bolt fs-5"></i> Tạo Lệnh Nạp Tiền & Lấy Mã VietQR
                            </button>
                        </div>
                    </form>
                </div>

                <!-- CARD 2: CHỈ HIỆN KHI NGƯỜI DÙNG BẤM TẠO LỆNH (NẰM Ở HÀNG DƯỚI CARD TẠO LỆNH) -->
                <?php if ($activeDeposit): ?>
                    <?php 
                        $qrBankCode = !empty($activeDeposit['bank_code']) ? $activeDeposit['bank_code'] : 'TPB';
                        $qrAccNum = $activeDeposit['account_number'];
                        $qrAccName = $activeDeposit['account_name'];
                        $qrAmount = (float)$activeDeposit['amount'];
                        $qrContent = $activeDeposit['transfer_content'];
                        $qrBankName = $activeDeposit['bank_name'];

                        $vietQrUrl = sprintf(
                            "https://img.vietqr.io/image/%s-%s-compact2.png?amount=%d&addInfo=%s&accountName=%s",
                            urlencode($qrBankCode),
                            urlencode($qrAccNum),
                            (int)$qrAmount,
                            urlencode($qrContent),
                            urlencode($qrAccName)
                        );
                    ?>
                    <div class="qr-display-card" id="qrDisplayCard">
                        <div class="card-header-title">
                            <div class="card-title-text">
                                <i class="fa-solid fa-qrcode text-primary"></i>
                                <span>Thông Tin Thanh Toán & Quét Mã VietQR TPBank</span>
                            </div>
                            <?php if ($activeDeposit['status'] === 'Pending'): ?>
                                <span class="badge bg-warning text-dark px-3 py-2 fw-bold" style="font-size: 0.82rem;">
                                    <i class="fa-solid fa-clock me-1"></i> Mã đơn: #<?= htmlspecialchars($activeDeposit['deposit_code']) ?> - Chờ chuyển tiền
                                </span>
                            <?php else: ?>
                                <span class="badge bg-success text-white px-3 py-2 fw-bold" style="font-size: 0.82rem;">
                                    <i class="fa-solid fa-circle-check me-1"></i> Mã đơn: #<?= htmlspecialchars($activeDeposit['deposit_code']) ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="qr-card-body-grid">
                            <!-- Cột trái bên trong card: Ảnh mã VietQR & nút tải -->
                            <div class="text-center d-flex flex-column align-items-center justify-content-center">
                                <div class="qr-image-wrapper">
                                    <img src="<?= htmlspecialchars($vietQrUrl) ?>" 
                                         alt="VietQR TPBank Chuyển Khoản" 
                                         id="vietQrImage"
                                         loading="lazy">
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-center mt-1">
                                    <a href="<?= htmlspecialchars($vietQrUrl) ?>" download="VietQR-TPBank-<?= htmlspecialchars($activeDeposit['deposit_code']) ?>.png" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-bold">
                                        <i class="fa-solid fa-download me-1"></i> Tải ảnh QR
                                    </a>
                                    <a href="<?= htmlspecialchars($redirectRoute) ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-bold">
                                        <i class="fa-solid fa-plus me-1"></i> Tạo lệnh nạp mới
                                    </a>
                                </div>
                            </div>

                            <!-- Cột phải bên trong card: Bảng thông tin chuyển khoản & thông báo tự động -->
                            <div>
                                <div class="transfer-details-box mb-3">
                                    <!-- Ngân hàng -->
                                    <div class="transfer-row">
                                        <span class="transfer-label">Ngân hàng thụ hưởng:</span>
                                        <span class="transfer-val" id="detailBankName"><?= htmlspecialchars($qrBankName) ?></span>
                                    </div>

                                    <!-- Số tài khoản -->
                                    <div class="transfer-row">
                                        <span class="transfer-label">Số tài khoản nhận:</span>
                                        <div class="transfer-val">
                                            <span class="font-monospace text-primary fw-bold fs-6" id="detailAccNum"><?= htmlspecialchars($qrAccNum) ?></span>
                                            <button type="button" class="btn-copy-mini" onclick="copyText(document.getElementById('detailAccNum').innerText, this)">
                                                <i class="fa-regular fa-copy"></i> Chép
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Chủ tài khoản -->
                                    <div class="transfer-row">
                                        <span class="transfer-label">Chủ tài khoản:</span>
                                        <span class="transfer-val" id="detailAccName"><?= htmlspecialchars($qrAccName) ?></span>
                                    </div>

                                    <!-- Số tiền -->
                                    <div class="transfer-row">
                                        <span class="transfer-label">Số tiền cần chuyển:</span>
                                        <div class="transfer-val">
                                            <span class="text-success fw-bold fs-6" id="detailAmount"><?= format_currency($qrAmount) ?></span>
                                            <button type="button" class="btn-copy-mini" onclick="copyText('<?= (int)$qrAmount ?>', this)">
                                                <i class="fa-regular fa-copy"></i> Chép
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Nội dung chuyển khoản -->
                                    <div class="transfer-row" style="border-bottom: none;">
                                        <span class="transfer-label">Nội dung CK (bắt buộc chính xác):</span>
                                        <div class="transfer-val">
                                            <span class="transfer-val highlight-code" id="detailContent"><?= htmlspecialchars($qrContent) ?></span>
                                            <button type="button" class="btn-copy-mini" onclick="copyText(document.getElementById('detailContent').innerText, this)">
                                                <i class="fa-regular fa-copy"></i> Chép
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Thông báo hệ thống kiểm tra tự động 24/7 -->
                                <div class="p-3 rounded-3" style="background: #f0fdf4; border: 1px solid #bbf7d0;">
                                    <div class="d-flex align-items-center gap-2 text-success fw-bold mb-1" style="font-size: 0.88rem;">
                                        <span class="spinner-grow spinner-grow-sm text-success" role="status"></span>
                                        <span>Hệ thống TPBank đang kiểm tra tự động 24/7</span>
                                    </div>
                                    <div class="text-muted" style="font-size: 0.8rem; line-height: 1.45;">
                                        Quý khách mở App ngân hàng quét mã QR bên cạnh và xác nhận chuyển. Khi nhận được tiền, hệ thống sẽ tự động cộng số dư vào tài khoản trong <strong>30s - 1 phút</strong>.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ================= BẢNG LỊCH SỬ NẠP TIỀN (CHUẨN 1:1 THEO BUY-KEY) ================= -->
            <div class="dash-card">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <h4 class="fw-bold text-dark mb-1">
                            <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Lịch Sử Giao Dịch Nạp Tiền
                        </h4>
                        <p class="text-muted small mb-0">Theo dõi chi tiết mã đơn nạp, ngân hàng thụ hưởng và trạng thái cộng tiền tự động của bạn</p>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="history-count-badge">
                            <span class="count-badge-icon">
                                <i class="fa-solid fa-receipt"></i>
                            </span>
                            <span class="count-badge-label">Tổng đơn đã tạo:</span>
                            <span class="count-badge-number"><?= count($depositHistory) ?></span>
                        </div>
                        <a href="<?= htmlspecialchars($redirectRoute) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold">
                            <i class="fa-solid fa-arrows-rotate me-1"></i> Làm mới
                        </a>
                    </div>
                </div>

                <?php if (empty($depositHistory)): ?>
                    <div class="text-center py-5">
                        <div class="p-3 bg-light rounded-circle d-inline-flex align-items-center justify-content-center text-muted mb-3" style="width: 70px; height: 70px;">
                            <i class="fa-solid fa-receipt fs-2 text-primary opacity-50"></i>
                        </div>
                        <h5 class="fw-bold text-dark">Bạn chưa có đơn nạp tiền nào</h5>
                        <p class="text-muted small mb-3">Hãy chọn số tiền và bấm Tạo Yêu Cầu Nạp Tiền ở phía trên để nạp số dư vào ví tức thì.</p>
                        <button type="button" class="btn btn-primary rounded-pill px-4" onclick="window.scrollTo({top: 300, behavior: 'smooth'})">
                            <i class="fa-solid fa-plus me-1"></i> Tạo lệnh nạp ngay
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th class="text-center" style="text-align: center !important;"><i class="fa-solid fa-hashtag text-primary me-1"></i> Mã đơn</th>
                                    <th class="text-center" style="text-align: center !important;"><i class="fa-solid fa-building-columns text-primary me-1"></i> Ngân hàng nhận</th>
                                    <th class="text-center" style="text-align: center !important;"><i class="fa-solid fa-coins text-primary me-1"></i> Số tiền nạp</th>
                                    <th class="text-center" style="text-align: center !important;"><i class="fa-solid fa-message text-primary me-1"></i> Nội dung chuyển khoản</th>
                                    <th class="text-center" style="text-align: center !important;"><i class="fa-regular fa-clock text-primary me-1"></i> Thời gian tạo</th>
                                    <th class="text-center" style="text-align: center !important;"><i class="fa-solid fa-circle-check text-primary me-1"></i> Trạng thái</th>
                                    <th class="text-center" style="text-align: center !important;"><i class="fa-solid fa-gear text-primary me-1"></i> Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($depositHistory as $item): ?>
                                    <tr>
                                        <td class="text-center" style="text-align: center !important;">
                                            <strong class="font-monospace text-primary" style="font-size: 0.95rem;">#<?= htmlspecialchars($item['deposit_code']) ?></strong>
                                        </td>
                                        <td class="text-center" style="text-align: center !important;">
                                            <div class="fw-bold text-dark text-center" style="text-align: center !important;"><?= htmlspecialchars($item['bank_name']) ?></div>
                                            <small class="text-muted font-monospace text-center d-block" style="text-align: center !important;">STK: <?= htmlspecialchars($item['account_number']) ?> (<?= htmlspecialchars($item['account_name']) ?>)</small>
                                        </td>
                                        <td class="text-center" style="text-align: center !important;">
                                            <strong class="text-success" style="font-size: 0.88rem; font-weight: 700;">+<?= format_currency($item['amount']) ?></strong>
                                        </td>
                                        <td class="text-center" style="text-align: center !important;">
                                            <span class="text-danger fw-bold font-monospace me-1"><?= htmlspecialchars($item['transfer_content']) ?></span>
                                            <button type="button" 
                                                    class="btn-copy-minimal" 
                                                    title="Sao chép nội dung chuyển khoản" 
                                                    onclick="copyText('<?= htmlspecialchars($item['transfer_content']) ?>', this)">
                                                <i class="fa-regular fa-copy"></i>
                                            </button>
                                        </td>
                                        <td class="text-center" style="text-align: center !important;">
                                            <div class="small fw-semibold text-dark"><?= date('d/m/Y H:i', strtotime($item['created_at'])) ?></div>
                                            <small class="text-muted"><?= time_ago($item['created_at']) ?></small>
                                        </td>
                                        <td class="text-center" style="text-align: center !important;">
                                            <?php 
                                            $st = ucfirst(strtolower($item['status'] ?? 'Pending'));
                                            ?>
                                            <?php if ($st === 'Success'): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-1 rounded-pill fw-semibold">
                                                    <i class="fa-solid fa-circle-check me-1"></i> Hoàn thành
                                                </span>
                                            <?php elseif ($st === 'Pending'): ?>
                                                <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 px-3 py-1 rounded-pill text-dark fw-semibold">
                                                    <i class="fa-solid fa-clock me-1"></i> Đang chờ
                                                </span>
                                            <?php elseif ($st === 'Cancelled'): ?>
                                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-3 py-1 rounded-pill fw-semibold">
                                                    <i class="fa-solid fa-ban me-1"></i> Đã hủy
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-3 py-1 rounded-pill fw-semibold">
                                                    <i class="fa-solid fa-circle-xmark me-1"></i> Thất bại
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center" style="text-align: center !important;">
                                            <div class="d-inline-flex gap-1 justify-content-center align-items-center flex-wrap">
                                                <button type="button" 
                                                        class="btn btn-sm btn-outline-primary rounded-pill px-2 py-1" 
                                                        style="font-size: 0.76rem; font-weight: 700;" 
                                                        title="Xem hóa đơn chi tiết & xuất file PDF" 
                                                        onclick='showDepositInvoice(<?= htmlspecialchars(json_encode([
                                                            'code' => $item['deposit_code'],
                                                            'amount' => (float)$item['amount'],
                                                            'amount_formatted' => format_currency($item['amount']),
                                                            'bank_name' => $item['bank_name'],
                                                            'account_number' => $item['account_number'],
                                                            'account_name' => $item['account_name'],
                                                            'transfer_content' => $item['transfer_content'],
                                                            'created_at' => date('d/m/Y H:i:s', strtotime($item['created_at'])),
                                                            'status' => $item['status'],
                                                            'customer_name' => !empty($currentUser['name']) ? $currentUser['name'] : (!empty($currentUser['username']) ? ltrim($currentUser['username'], '@') : 'Khách hàng'),
                                                            'customer_username' => ltrim($currentUser['username'] ?? '', '@'),
                                                            'customer_email' => $currentUser['email'] ?? '',
                                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>)'>
                                                    <i class="fa-solid fa-receipt me-1"></i> Chi tiết
                                                </button>

                                                <?php if ($st === 'Pending'): ?>
                                                    <a href="<?= htmlspecialchars($redirectRoute) ?>?code=<?= urlencode($item['deposit_code']) ?>" class="btn btn-sm btn-primary rounded-pill px-2 py-1 fw-bold" style="font-size: 0.76rem;" title="Xem lại mã QR">
                                                        <i class="fa-solid fa-qrcode me-1"></i> Lấy QR
                                                    </a>
                                                    <form action="<?= htmlspecialchars($redirectRoute) ?>" method="POST" class="d-inline" onsubmit="return confirm('Bạn có chắc chắn muốn hủy lệnh nạp tiền này?');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="cancel_deposit">
                                                        <input type="hidden" name="deposit_id" value="<?= $item['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1" style="font-size: 0.76rem;" title="Hủy lệnh nạp">
                                                            <i class="fa-solid fa-xmark"></i> Hủy
                                                        </button>
                                                    </form>
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
     * MODAL HÓA ĐƠN GIAO DỊCH NẠP TIỀN & XUẤT FILE WORD
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
                            <h5 class="modal-title fw-bold text-dark mb-0" id="depositInvoiceModalLabel">Hóa Đơn Nạp Tiền Điện Tử</h5>
                            <small class="text-muted">Chứng từ xác thực giao dịch chính thức</small>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-danger rounded-pill px-3 fw-bold shadow-sm" onclick="exportCurrentInvoiceToPdf()">
                            <i class="fa-solid fa-file-pdf me-1"></i> Xuất file PDF
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-bold" onclick="printCurrentInvoice()">
                            <i class="fa-solid fa-print me-1"></i> In hóa đơn
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
                                    CÔNG TY TNHH CÔNG NGHỆ SỐ THANH QUY TECH
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

                        <!-- 2. Tiêu đề Hóa Đơn & Barcode Siêu Thị Chuẩn Siêu Nét -->
                        <div class="text-center my-2">
                            <div class="fw-bold text-dark text-uppercase" style="font-size: 1.02rem; letter-spacing: 0.8px; margin-bottom: 1px;">
                                HÓA ĐƠN BÁN HÀNG DỊCH VỤ CÔNG NGHỆ
                            </div>
                            <div class="text-muted" style="font-size: 0.72rem; margin-bottom: 3px;">(Bản thể hiện hóa đơn điện tử phục vụ chứng từ đối soát doanh nghiệp)</div>
                            <div class="d-flex justify-content-center align-items-center gap-2 text-muted" style="font-size: 0.76rem; margin-bottom: 3px;">
                                <span>Ký hiệu (Serial): <strong class="text-dark">TQ/26E</strong></span>
                                <span>|</span>
                                <span>Số hóa đơn (No.): <strong class="text-primary font-monospace" style="font-size: 0.84rem;" id="invModalCode">#---</strong></span>
                                <span>|</span>
                                <span id="invModalStatusBadge"><span class="badge bg-success text-white px-2 py-0 rounded-pill">ĐÃ HOÀN THÀNH</span></span>
                            </div>
                            
                            <!-- Mã vạch Barcode siêu thị: Nét mảnh chuẩn, cao 22px, không có số bên dưới -->
                            <div class="invoice-barcode-wrap">
                                <div class="text-muted fw-bold" style="font-size: 0.65rem; letter-spacing: 0.8px; margin-bottom: 1px;">MÃ VẠCH TRA CỨU ĐỐI SOÁT (RETAIL BARCODE)</div>
                                <div id="invModalBarcodeSvg" style="line-height: 0;"></div>
                            </div>
                        </div>

                        <!-- 3. Thông tin Khách hàng & Thụ hưởng (Kẻ bảng chia 2 cột rõ ràng, sắc nét) -->
                        <div class="invoice-meta-grid">
                            <div class="meta-col">
                                <div class="meta-title"><i class="fa-solid fa-building me-1"></i> ĐƠN VỊ MUA HÀNG / KHÁCH HÀNG</div>
                                <table class="meta-table">
                                    <tr>
                                        <td class="meta-label">Khách hàng:</td>
                                        <td class="meta-val fw-bold text-dark" id="invModalCustomerName">---</td>
                                    </tr>
                                    <tr>
                                        <td class="meta-label">Tài khoản:</td>
                                        <td class="meta-val font-monospace text-primary fw-semibold" id="invModalCustomerUsername">---</td>
                                    </tr>
                                    <tr>
                                        <td class="meta-label">Email nhận HĐ:</td>
                                        <td class="meta-val text-dark" id="invModalCustomerEmail">---</td>
                                    </tr>
                                </table>
                            </div>
                            <div class="meta-col">
                                <div class="meta-title"><i class="fa-solid fa-building-columns me-1"></i> PHƯƠNG THỨC & THỤ HƯỞNG</div>
                                <table class="meta-table">
                                    <tr>
                                        <td class="meta-label">Hình thức:</td>
                                        <td class="meta-val fw-bold text-dark">Chuyển khoản (VietQR Napas 24/7)</td>
                                    </tr>
                                    <tr>
                                        <td class="meta-label">Ngân hàng:</td>
                                        <td class="meta-val text-dark" id="invModalBankName">---</td>
                                    </tr>
                                    <tr>
                                        <td class="meta-label">Tài khoản nhận:</td>
                                        <td class="meta-val font-monospace text-dark fw-bold"><span id="invModalAccountNumber">---</span> (<span class="text-uppercase" id="invModalAccountName">---</span>)</td>
                                    </tr>
                                    <tr>
                                        <td class="meta-label">Nội dung CK:</td>
                                        <td class="meta-val font-monospace text-danger fw-bold" id="invModalTransferContent">---</td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- 4. Bảng chi tiết dịch vụ thanh toán B2B kẻ khung đầy đủ -->
                        <table class="invoice-table">
                            <thead>
                                <tr>
                                    <th style="width: 40px; text-align: center !important;">STT</th>
                                    <th>Tên hàng hóa, dịch vụ</th>
                                    <th style="width: 65px; text-align: center !important;">ĐVT</th>
                                    <th style="width: 50px; text-align: center !important;">SL</th>
                                    <th class="text-end" style="width: 105px; text-align: right !important;">Đơn giá</th>
                                    <th class="text-end" style="width: 115px; text-align: right !important;">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td style="text-align: center !important;">01</td>
                                    <td style="text-align: left !important;">
                                        <div class="fw-bold text-dark">Nạp số dư dịch vụ công nghệ & key bản quyền tự động</div>
                                        <div class="text-muted" style="font-size: 0.72rem;">Cộng tiền tự động vào số dư ví tài khoản hệ thống ThanhQuyTech</div>
                                    </td>
                                    <td style="text-align: center !important;">Giao dịch</td>
                                    <td style="text-align: center !important;">01</td>
                                    <td class="text-end fw-semibold text-dark" style="text-align: right !important;" id="invModalUnitPrice">0 đ</td>
                                    <td class="text-end fw-bold text-dark" style="text-align: right !important;" id="invModalAmountItem">0 đ</td>
                                </tr>
                                <tr>
                                    <td colspan="5" class="text-end text-muted" style="text-align: right !important;">Cộng tiền hàng (Subtotal):</td>
                                    <td class="text-end fw-bold text-dark" style="text-align: right !important;" id="invModalSubTotal">0 đ</td>
                                </tr>
                                <tr>
                                    <td colspan="5" class="text-end text-muted" style="text-align: right !important;">Thuế suất GTGT (VAT Rate):</td>
                                    <td class="text-end text-muted" style="text-align: right !important;">0% (Không tính thuế)</td>
                                </tr>
                                <tr>
                                    <td colspan="5" class="text-end text-muted" style="text-align: right !important;">Phí xử lý cổng thanh toán:</td>
                                    <td class="text-end text-success fw-bold" style="text-align: right !important;">0 đ (Miễn phí)</td>
                                </tr>
                                <tr style="background: #f8fafc;">
                                    <td colspan="5" class="text-end fw-bold text-dark" style="text-align: right !important; font-size: 0.86rem;">TỔNG CỘNG TIỀN THANH TOÁN:</td>
                                    <td class="text-end fw-bold text-success" style="text-align: right !important; font-size: 0.95rem;" id="invModalTotalAmount">0 đ</td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="p-2 px-3 bg-light rounded-2 border mb-2 text-start" style="font-size: 0.76rem;">
                            <strong>Số tiền viết bằng chữ:</strong> <span class="fst-italic text-dark fw-semibold" id="invModalAmountWords">---</span>
                        </div>

                        <!-- 5. Phần Chữ Ký & Con Dấu Xác Nhận Đơn Vị Phát Hành (Chỉ bên bán/thủ trưởng) -->
                        <div class="d-flex justify-content-end my-2">
                            <div class="text-center" style="min-width: 250px; max-width: 280px;">
                                <div class="fw-bold text-dark text-uppercase" style="font-size: 0.82rem; letter-spacing: 0.3px;">
                                    NGƯỜI BÁN HÀNG / THỦ TRƯỞNG ĐƠN VỊ
                                </div>
                                <div class="text-muted fst-italic" style="font-size: 0.72rem; margin-bottom: 2px;">(Ký số, đóng dấu chứng thực)</div>
                                
                                <!-- Khối chứa con dấu và chữ ký thật trong suốt của Phan Thành Quý -->
                                <div style="position: relative; height: 95px; margin: 4px auto; display: flex; align-items: center; justify-content: center;">
                                    <!-- Con dấu tròn đỏ công ty -->
                                    <div class="red-stamp-seal" style="position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%) rotate(-6deg); z-index: 1;">
                                        <svg viewBox="0 0 160 160" width="105" height="105">
                                            <circle cx="80" cy="80" r="74" fill="none" stroke="#dc2626" stroke-width="3" />
                                            <circle cx="80" cy="80" r="67" fill="none" stroke="#dc2626" stroke-width="1.5" stroke-dasharray="3,3" />
                                            <circle cx="80" cy="80" r="48" fill="none" stroke="#dc2626" stroke-width="1.5" />
                                            
                                            <path id="curveTop" d="M 22,80 A 58,58 0 1,1 138,80" fill="none" />
                                            <text font-size="9.5" font-weight="900" fill="#dc2626" letter-spacing="0.5">
                                                <textPath href="#curveTop" startOffset="50%" text-anchor="middle">
                                                    CÔNG TY TNHH CÔNG NGHỆ SỐ THANH QUY TECH
                                                </textPath>
                                            </text>
                                            
                                            <path id="curveBottom" d="M 138,80 A 58,58 0 0,1 22,80" fill="none" />
                                            <text font-size="8.5" font-weight="700" fill="#dc2626" letter-spacing="1">
                                                <textPath href="#curveBottom" startOffset="50%" text-anchor="middle">
                                                    ★ MST: 0318954321 ★ TP. HỒ CHÍ MINH
                                                </textPath>
                                            </text>
                                            
                                            <polygon points="80,57 82.5,63.5 89.5,63.5 84,68 86,74.5 80,70.5 74,74.5 76,68 70.5,63.5 77.5,63.5" fill="#dc2626" />
                                            <text x="80" y="91" font-size="10" font-weight="900" fill="#dc2626" text-anchor="middle" letter-spacing="0.5">ĐÃ XÁC NHẬN</text>
                                            <text x="80" y="103" font-size="8" font-weight="700" fill="#dc2626" text-anchor="middle">THANH TOÁN</text>
                                        </svg>
                                    </div>

                                    <!-- Chữ ký thật trong suốt của Phan Thành Quý -->
                                    <div style="position: relative; z-index: 2;" id="invModalSignatureImgContainer">
                                        <!-- Injected via JS using USER_SIGNATURE_BASE64 -->
                                    </div>
                                </div>

                                <div class="fw-bold text-dark mt-1" style="font-size: 0.86rem;">Phan Thành Quý</div>
                                <div class="text-muted" style="font-size: 0.72rem;">Giám đốc điều hành / Người đại diện pháp luật</div>
                            </div>
                        </div>

                        <!-- 6. Footer Lời Cảm Ơn -->
                        <div class="mt-2 pt-2 border-top text-center text-muted" style="font-size: 0.72rem;">
                            <div><i class="fa-solid fa-shield-halved text-success me-1"></i> Hóa đơn điện tử khởi tạo hợp pháp theo quy định của pháp luật Việt Nam.</div>
                            <div>Mọi thắc mắc xin liên hệ SĐT: 0355879036 | Website: <span class="fw-semibold text-dark"><?= htmlspecialchars($dynamicHost) ?></span>. Cảm ơn quý khách!</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-2 px-4 justify-content-between">
                    <span class="text-muted small"><i class="fa-solid fa-shield-halved text-success me-1"></i> Chứng từ hóa đơn bảo mật & xác thực</span>
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // 1. Điều khiển Sidebar Mobile & Desktop Collapse (Chuẩn 1:1)
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

        // 2. User Popup Menu (Chuẩn 1:1)
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

        // 3. Sao chép nhanh vào Clipboard
        function copyText(text, btnElement) {
            if (!text) return;
            navigator.clipboard.writeText(text).then(function() {
                if (btnElement) {
                    var origHtml = btnElement.innerHTML;
                    btnElement.innerHTML = '<i class="fa-solid fa-check text-success"></i> Đã chép';
                    setTimeout(function() {
                        btnElement.innerHTML = origHtml;
                    }, 2000);
                }
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Đã sao chép: ' + text,
                    showConfirmButton: false,
                    timer: 1800
                });
            }).catch(function(err) {
                prompt('Sao chép thủ công:', text);
            });
        }

        // 4. Định dạng tiền tệ trong ô nhập
        function formatCurrencyInput(input) {
            let val = input.value.replace(/\D/g, '');
            if (val === '') {
                input.value = '';
                return;
            }
            let formatted = new Intl.NumberFormat('vi-VN').format(val);
            input.value = formatted;
        }

        // 5. Nút chọn nhanh số tiền
        function selectQuickAmount(amount, btn) {
            document.querySelectorAll('.amount-pill-btn').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');
            var input = document.getElementById('amountInput');
            if (input) {
                input.value = new Intl.NumberFormat('vi-VN').format(amount);
            }
        }

        // 6. Xử lý hiển thị Hóa đơn nạp tiền & Xuất file Word
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
            // Chuẩn hóa chuỗi mã vạch bán lẻ siêu thị (Code 128 / Retail Barcode)
            const seed = (code || '5411442').toString().replace(/\D/g, '') + '928475';
            const patterns = [
                '11011001100', '11001101100', '11001100110', '10010011000', '10010001100',
                '10001001100', '10011001000', '10011000100', '10001100100', '11001001000',
                '11001000100', '11000100100', '10110011100', '10011011100', '10011001110',
                '10111001100', '10011101100', '10011100110', '11001110010', '11001011100'
            ];
            let bitString = '11010010000'; // Start B
            for (let i = 0; i < 7; i++) {
                const charCode = seed.charCodeAt(i % seed.length) % patterns.length;
                bitString += patterns[charCode];
            }
            bitString += '1100011101011'; // Stop B + terminal
            
            // Vẽ bằng Canvas xuất ra ảnh PNG độ phân giải cao 
            // Khắc phục triệt để lỗi html2canvas làm chập/mất vạch khi xuất PDF
            try {
                const canvas = document.createElement('canvas');
                const scale = 2;
                const barW = 2; // Độ rộng từng vạch chuẩn integer pixel
                const h = 26;
                const quiet = 10;
                const width = (quiet * 2) + (bitString.length * barW);
                
                canvas.width = width * scale;
                canvas.height = h * scale;
                const ctx = canvas.getContext('2d');
                ctx.scale(scale, scale);
                
                // Nền trắng tinh khiết
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, width, h);
                
                // Vẽ các vạch đen theo chuẩn mã vạch bán lẻ siêu thị
                ctx.fillStyle = '#0f172a';
                for (let i = 0; i < bitString.length; i++) {
                    if (bitString[i] === '1') {
                        ctx.fillRect(quiet + i * barW, 0, barW, h);
                    }
                }
                
                const pngUrl = canvas.toDataURL('image/png');
                return `<img src="${pngUrl}" alt="Retail Barcode ${code}" class="invoice-barcode-img" style="width: 180px; height: 24px; display: inline-block; image-rendering: pixelated; vertical-align: middle;" />`;
            } catch (e) {
                // Fallback nếu canvas bị lỗi
                let x = 6;
                const barW = 1.35;
                const h = 22;
                let rects = '';
                for (let i = 0; i < bitString.length; i++) {
                    if (bitString[i] === '1') {
                        rects += `<rect x="${(x + i * barW).toFixed(2)}" y="0" width="${barW.toFixed(2)}" height="${h}" fill="#0f172a"/>`;
                    }
                }
                const totalW = Math.ceil(x * 2 + bitString.length * barW);
                return `<svg class="invoice-barcode-svg" viewBox="0 0 ${totalW} ${h}" style="width: 175px; height: 22px; display: inline-block;">${rects}</svg>`;
            }
        }

        // Chuyển đổi trạng thái giao dịch sang Tiếng Việt chuẩn
        function getVietnameseStatus(status) {
            const s = (status || '').toLowerCase();
            if (s === 'success') {
                return { text: 'ĐÃ HOÀN THÀNH', badgeClass: 'bg-success text-white', color: '#16a34a', icon: 'fa-circle-check' };
            }
            if (s === 'pending') {
                return { text: 'ĐANG CHỜ THANH TOÁN', badgeClass: 'bg-warning text-dark', color: '#d97706', icon: 'fa-clock' };
            }
            if (s === 'cancelled') {
                return { text: 'ĐÃ HỦY', badgeClass: 'bg-secondary text-white', color: '#64748b', icon: 'fa-ban' };
            }
            return { text: 'THẤT BẠI', badgeClass: 'bg-danger text-white', color: '#dc2626', icon: 'fa-circle-xmark' };
        }

        function showDepositInvoice(data) {
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
                        } else if (datePart.includes('-')) {
                            const [y, m, d] = datePart.split('-');
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

            // 2. Trạng thái badge (Tiếng Việt)
            const statusEl = document.getElementById('invModalStatusBadge');
            if (statusEl) {
                const vStatus = getVietnameseStatus(data.status);
                statusEl.innerHTML = `<span class="badge ${vStatus.badgeClass} px-2 py-0 rounded-pill fw-bold" style="font-size: 0.72rem;"><i class="fa-solid ${vStatus.icon} me-1"></i> ${vStatus.text}</span>`;
            }

            // 3. Thông tin người nạp (Lấy name trong SQL, chuẩn hóa @username không bị dư @)
            const cleanUsername = (data.customer_username || '').replace(/^@+/, '');
            const customerName = data.customer_name || cleanUsername;

            const cName = document.getElementById('invModalCustomerName');
            if (cName) cName.innerText = customerName;
            const cUser = document.getElementById('invModalCustomerUsername');
            if (cUser) cUser.innerText = '@' + cleanUsername;
            const cEmail = document.getElementById('invModalCustomerEmail');
            if (cEmail) cEmail.innerText = data.customer_email || 'Chưa cập nhật';

            // Cập nhật website động theo domain hiện tại
            const webEl = document.getElementById('invCompanyWebsite');
            if (webEl) {
                webEl.innerText = window.location.host || '<?= htmlspecialchars($dynamicHost) ?>';
            }

            // 4. Thông tin ngân hàng
            const bName = document.getElementById('invModalBankName');
            if (bName) bName.innerText = data.bank_name;
            const bAcc = document.getElementById('invModalAccountNumber');
            if (bAcc) bAcc.innerText = data.account_number;
            const bAccName = document.getElementById('invModalAccountName');
            if (bAccName) bAccName.innerText = data.account_name;
            const bContent = document.getElementById('invModalTransferContent');
            if (bContent) bContent.innerText = data.transfer_content;

            // 5. Số tiền chi tiết B2B
            const unitPrice = document.getElementById('invModalUnitPrice');
            if (unitPrice) unitPrice.innerText = data.amount_formatted;
            const amtItem = document.getElementById('invModalAmountItem');
            if (amtItem) amtItem.innerText = data.amount_formatted;
            const subTotal = document.getElementById('invModalSubTotal');
            if (subTotal) subTotal.innerText = data.amount_formatted;
            const totAmt = document.getElementById('invModalTotalAmount');
            if (totAmt) totAmt.innerText = data.amount_formatted;
            const wordsEl = document.getElementById('invModalAmountWords');
            if (wordsEl) wordsEl.innerText = docSoTien(data.amount);

            // 6. Chữ ký thật trong suốt của Phan Thành Quý (chỉ có nét mực ký, không có nền trắng)
            const sigBox = document.getElementById('invModalSignatureImgContainer');
            if (sigBox) {
                if (USER_SIGNATURE_BASE64) {
                    sigBox.innerHTML = `<img src="${USER_SIGNATURE_BASE64}" alt="Chữ ký Phan Thành Quý" class="signature-stroke-img" style="max-height: 60px; max-width: 155px; mix-blend-mode: multiply; filter: contrast(1.2); display: inline-block;" />`;
                } else {
                    sigBox.innerHTML = `<span style="font-family: 'Brush Script MT', cursive; font-size: 22px; color: #1d4ed8; font-weight: bold;">Phan Thành Quý</span>`;
                }
            }

            // 7. Barcode siêu thị (không có số phía dưới)
            const barcodeBox = document.getElementById('invModalBarcodeSvg');
            if (barcodeBox) barcodeBox.innerHTML = generateBarcodeSvg(data.code);

            // 8. Mở Modal
            const modalEl = document.getElementById('depositInvoiceModal');
            if (modalEl) {
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    let modalInstance = bootstrap.Modal.getInstance(modalEl);
                    if (!modalInstance) {
                        modalInstance = new bootstrap.Modal(modalEl);
                    }
                    modalInstance.show();
                } else {
                    modalEl.classList.add('show');
                    modalEl.style.display = 'block';
                    document.body.classList.add('modal-open');
                }
            }
        }

        // Xuất hóa đơn ra file PDF chuẩn Full-Width 1:1, khớp tuyệt đối 1 trang A4 duy nhất
        function exportCurrentInvoiceToPdf() {
            if (!currentInvoiceData) return;
            const data = currentInvoiceData;
            const element = document.getElementById('invoiceCardPrintArea');
            if (!element) return;

            // Cuộn modal lên trên cùng để tránh lệch toạ độ
            const modalBody = document.querySelector('#depositInvoiceModal .modal-body');
            if (modalBody) modalBody.scrollTop = 0;

            Swal.fire({
                title: 'Đang khởi tạo file PDF...',
                text: 'Hệ thống đang kết xuất hóa đơn chuẩn A4 Full-Width...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            // Lấy trực tiếp constructor jsPDF từ bundle hoặc window
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
                    letterRendering: true,
                    backgroundColor: '#ffffff',
                    scrollX: 0,
                    scrollY: 0
                }).then(canvas => {
                    try {
                        const pdf = new jsPdfClass('p', 'mm', 'a4');
                        const pageWidth = pdf.internal.pageSize.getWidth(); // 210mm
                        const pageHeight = pdf.internal.pageSize.getHeight(); // 297mm
                        
                        // Lề 8mm hai bên -> Hóa đơn chiếm trọn vẹn 194mm (Full-Width cực đẹp như trên web)
                        const margin = 8;
                        const imgWidth = pageWidth - (margin * 2); // 194 mm
                        const imgHeight = (canvas.height * imgWidth) / canvas.width;
                        
                        const imgData = canvas.toDataURL('image/jpeg', 0.98);
                        pdf.addImage(imgData, 'JPEG', margin, margin, imgWidth, imgHeight);
                        pdf.save(`Hoa_Don_Doanh_Nghiep_${data.code}.pdf`);

                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'success',
                            title: 'Đã xuất hóa đơn PDF Full-Width thành công!',
                            showConfirmButton: false,
                            timer: 2500
                        });
                    } catch (e) {
                        console.error('jsPDF generation error:', e);
                        fallbackToHtml2Pdf();
                    }
                }).catch(err => {
                    console.error('html2canvas error:', err);
                    fallbackToHtml2Pdf();
                });
            } else {
                fallbackToHtml2Pdf();
            }

            function fallbackToHtml2Pdf() {
                if (typeof html2pdf !== 'undefined') {
                    const opt = {
                        margin: [8, 8, 8, 8],
                        filename: `Hoa_Don_Doanh_Nghiep_${data.code}.pdf`,
                        image: { type: 'jpeg', quality: 0.98 },
                        html2canvas: { 
                            scale: 2.5, 
                            useCORS: true, 
                            scrollY: 0, 
                            scrollX: 0 
                        },
                        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                        pagebreak: { mode: 'avoid-all' }
                    };
                    html2pdf().set(opt).from(element).save().then(() => {
                        Swal.close();
                    }).catch(e => {
                        console.error('Fallback export error:', e);
                        window.print();
                    });
                } else {
                    window.print();
                }
            }
        }

        function printCurrentInvoice() {
            const card = document.getElementById('invoiceCardPrintArea');
            if (!card) {
                window.print();
                return;
            }

            // In qua iframe ẩn chuyên dụng để triệt tiêu hoàn toàn trang trắng thừa
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
                    <title>Hoa_Don_${currentInvoiceData ? currentInvoiceData.code : ''}</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
                    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
                        .invoice-barcode-img, .invoice-barcode-svg {
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

        // Thông báo Flash nếu có
        <?php if ($flash): ?>
            Swal.fire({
                icon: <?= json_encode($flash['type']) ?>,
                title: <?= json_encode($flash['title'] ?: 'Thông báo') ?>,
                text: <?= json_encode($flash['message']) ?>,
                confirmButtonColor: '#4f46e5'
            });
        <?php endif; ?>
    </script>
</body>
</html>
