<?php
/**
 * ==========================================================
 * TRANG MUA KEY & THUÊ CLOUD - HỆ THỐNG THANHQUYTECH
 * File: buy-key.php
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
// TỰ ĐỘNG KHỞI TẠO BẢNG KEY_ORDERS (SELF-HEALING SCHEMA)
// ==========================================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `key_orders` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_uuid` CHAR(36) NOT NULL COMMENT 'Liên kết bảng users.uuid',
            `order_code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Mã đơn hàng (#ORD-XXXXXX)',
            `package_type` ENUM('key_only', 'combo') NOT NULL DEFAULT 'key_only' COMMENT 'Loại gói (key_only hoặc combo)',
            `package_name` VARCHAR(100) NOT NULL COMMENT 'Tên gói dịch vụ',
            `duration_days` INT UNSIGNED NOT NULL COMMENT 'Số ngày sử dụng (1, 3, 7, 30, 90)',
            `license_key` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Chuỗi mã Key bản quyền kích hoạt',
            `cloud_server` VARCHAR(100) DEFAULT NULL COMMENT 'Máy chủ Cloud treo ngầm (cho gói Combo)',
            `amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'Số tiền thanh toán (VND)',
            `status` ENUM('Active', 'Expired') NOT NULL DEFAULT 'Active' COMMENT 'Trạng thái bản quyền',
            `expires_at` DATETIME NOT NULL COMMENT 'Thời hạn hết hạn của Key',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm mua đơn hàng',
            INDEX `idx_ko_user_uuid` (`user_uuid`),
            INDEX `idx_ko_order_code` (`order_code`),
            INDEX `idx_ko_license_key` (`license_key`),
            INDEX `idx_ko_status` (`status`),
            INDEX `idx_ko_expires_at` (`expires_at`),
            CONSTRAINT `fk_key_orders_user_uuid`
                FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Tự động cập nhật các key đã quá hạn sang Expired
    $pdo->exec("UPDATE `key_orders` SET `status` = 'Expired' WHERE `status` = 'Active' AND `expires_at` < NOW()");
} catch (Exception $e) {
    // Ghi log nhẹ nếu có lỗi
    error_log("Migration key_orders error: " . $e->getMessage());
}

// ==========================================================
// ĐỊNH NGHĨA DANH MỤC CÁC GÓI BẢN QUYỀN & COMBO
// ==========================================================
$PACKAGES = [
    // 1. Nhóm chỉ Mua Key (Giá gốc 1.000đ / ngày, chiết khấu vừa phải 5% - 20% đảm bảo chi phí & lợi nhuận)
    'key_1d' => [
        'id'             => 'key_1d',
        'type'           => 'key_only',
        'name'           => 'Gói Key 1 Ngày',
        'days'           => 1,
        'original_price' => 1000,
        'price'          => 1000,
        'discount_pct'   => 0,
        'badge'          => 'Tiện lợi',
        'badge_class'    => 'badge-tienloi',
        'popular'        => false,
        'card_class'     => '',
        'features'       => [
            'Bản quyền kích hoạt Tool Golike 24 giờ',
            'Đầy đủ tính năng nuôi acc & làm nhiệm vụ',
            'Hỗ trợ kỹ thuật 24/7 qua Telegram/Zalo',
            'Không bao gồm máy chủ Cloud (chạy trên PC/Phone)'
        ]
    ],
    'key_3d' => [
        'id'             => 'key_3d',
        'type'           => 'key_only',
        'name'           => 'Gói Key 3 Ngày',
        'days'           => 3,
        'original_price' => 3000,
        'price'          => 2850,
        'discount_pct'   => 5,
        'badge'          => 'Ưu đãi',
        'badge_class'    => 'badge-uudai',
        'popular'        => false,
        'card_class'     => 'card-uudai',
        'features'       => [
            'Bản quyền kích hoạt Tool Golike liên tục 72 giờ (3 ngày)',
            'Tự động giải captcha, chống checkpoint tối ưu',
            'Đổi cấu hình máy linh hoạt không giới hạn',
            'Không bao gồm máy chủ Cloud (chạy trên PC/Phone)'
        ]
    ],
    'key_7d' => [
        'id'             => 'key_7d',
        'type'           => 'key_only',
        'name'           => 'Gói Key 1 Tuần',
        'days'           => 7,
        'original_price' => 7000,
        'price'          => 6300,
        'discount_pct'   => 10,
        'badge'          => 'Phổ biến',
        'badge_class'    => 'badge-popular',
        'popular'        => true,
        'card_class'     => '',
        'features'       => [
            'Bản quyền kích hoạt Tool Golike trọn vẹn 7 ngày',
            'Tối ưu tốc độ làm job Instagram, Threads, TikTok',
            'Cập nhật thuật toán chống quét mới nhất',
            'Được tham gia nhóm chia sẻ kinh nghiệm VIP'
        ]
    ],
    'key_30d' => [
        'id'             => 'key_30d',
        'type'           => 'key_only',
        'name'           => 'Gói Key 1 Tháng',
        'days'           => 30,
        'original_price' => 30000,
        'price'          => 25500,
        'discount_pct'   => 15,
        'badge'          => 'Tiết kiệm',
        'badge_class'    => 'badge-tietkiem',
        'popular'        => false,
        'card_class'     => '',
        'features'       => [
            'Bản quyền kích hoạt trọn vẹn 30 ngày (1 tháng)',
            'Cày sản lượng đua top mượt mà không lo gián đoạn',
            'Ưu tiên kết nối máy chủ API tốc độ cao',
            'Hỗ trợ cấu hình proxy & quản lý đa luồng'
        ]
    ],
    'key_90d' => [
        'id'             => 'key_90d',
        'type'           => 'key_only',
        'name'           => 'Gói Key 3 Tháng',
        'days'           => 90,
        'original_price' => 90000,
        'price'          => 72000,
        'discount_pct'   => 20,
        'badge'          => 'Siêu Tiết Kiệm',
        'badge_class'    => 'badge-sieutietkiem',
        'popular'        => false,
        'card_class'     => '',
        'features'       => [
            'Bản quyền kích hoạt dài hạn 90 ngày (3 tháng)',
            'Cam kết uptime 99.9%, hỗ trợ 1-1 qua Ultraview',
            'Tặng kèm bộ tài liệu tối ưu acc nuôi an toàn',
            'Tiết kiệm tối đa chi phí duy trì hệ thống'
        ]
    ],

    // 2. Nhóm Combo Key + Treo Cloud (Chiết khấu 5% - 20% giữ vững biên lợi nhuận & chi phí VPS)
    'combo_1d' => [
        'id'             => 'combo_1d',
        'type'           => 'combo',
        'name'           => 'Combo 1 Ngày',
        'days'           => 1,
        'original_price' => 4000,
        'price'          => 4000,
        'discount_pct'   => 0,
        'badge'          => 'Tiện lợi',
        'badge_class'    => 'badge-tienloi',
        'popular'        => false,
        'card_class'     => '',
        'features'       => [
            'Bản quyền Key Tool Golike 24 giờ',
            'Máy chủ Cloud VPS tốc độ cao cày ngầm 24/24',
            'Không cần bật máy tính, không tốn pin điện thoại',
            'Xem log sản lượng realtime từ web'
        ]
    ],
    'combo_3d' => [
        'id'             => 'combo_3d',
        'type'           => 'combo',
        'name'           => 'Combo 3 Ngày',
        'days'           => 3,
        'original_price' => 12000,
        'price'          => 11400,
        'discount_pct'   => 5,
        'badge'          => 'Ưu đãi',
        'badge_class'    => 'badge-uudai',
        'popular'        => false,
        'card_class'     => 'card-uudai',
        'features'       => [
            'Bản quyền Key + Treo máy chủ Cloud 72 giờ',
            'Tự động chạy liên tục cả ngày lẫn đêm',
            'IP máy chủ sạch, hạn chế tối đa checkpoint',
            'Backup tiến trình tự động mỗi 30 phút'
        ]
    ],
    'combo_7d' => [
        'id'             => 'combo_7d',
        'type'           => 'combo',
        'name'           => 'Combo 1 Tuần',
        'days'           => 7,
        'original_price' => 28000,
        'price'          => 25200,
        'discount_pct'   => 10,
        'badge'          => 'Hot Combo',
        'badge_class'    => 'badge-combo-hot',
        'popular'        => true,
        'card_class'     => '',
        'features'       => [
            'Trọn gói Key + Cloud 7 ngày tự động hoá 100%',
            'Phù hợp cho anh em bận rộn muốn đua Top tuần',
            'Hạ tầng máy chủ NVMe cực mạnh, ping thấp',
            'Hỗ trợ khôi phục tức thì khi mạng xã hội lỗi'
        ]
    ],
    'combo_30d' => [
        'id'             => 'combo_30d',
        'type'           => 'combo',
        'name'           => 'Combo 1 Tháng',
        'days'           => 30,
        'original_price' => 120000,
        'price'          => 102000,
        'discount_pct'   => 15,
        'badge'          => 'Tiết kiệm',
        'badge_class'    => 'badge-tietkiem',
        'popular'        => false,
        'card_class'     => '',
        'features'       => [
            'Treo ngầm trọn gói 30 ngày trên máy chủ riêng biệt',
            'Khả năng đua Top tháng nhận giải thưởng lớn',
            'Được cấp port IP tĩnh riêng chống trùng lặp',
            'Ưu tiên slot máy chủ tài nguyên RAM 4GB'
        ]
    ],
    'combo_90d' => [
        'id'             => 'combo_90d',
        'type'           => 'combo',
        'name'           => 'Combo 3 Tháng',
        'days'           => 90,
        'original_price' => 360000,
        'price'          => 288000,
        'discount_pct'   => 20,
        'badge'          => 'Siêu Tiết Kiệm',
        'badge_class'    => 'badge-sieutietkiem',
        'popular'        => false,
        'card_class'     => '',
        'features'       => [
            '90 ngày cày sản lượng ngầm 24/7 hoàn toàn tự động',
            'Tiết kiệm điện năng, hao mòn phần cứng thiết bị cá nhân',
            'Kỹ thuật viên giám sát tiến trình 24/7',
            'Bảo hành 1 đổi 1 trong suốt thời gian sử dụng'
        ]
    ]
];

// ==========================================================
// XỬ LÝ THANH TOÁN MUA KEY (POST)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'purchase_package') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        set_flash('error', 'Yêu cầu không hợp lệ hoặc phiên bảo mật đã hết hạn. Vui lòng thử lại.', 'Lỗi xác thực');
        header("Location: buy-key.php");
        exit;
    }

    $packageId = trim($_POST['package_id'] ?? '');

    if (!isset($PACKAGES[$packageId])) {
        set_flash('error', 'Gói bản quyền bạn chọn không tồn tại trên hệ thống.', 'Gói không hợp lệ');
        header("Location: buy-key.php");
        exit;
    }

    $selectedPkg = $PACKAGES[$packageId];
    $pkgPrice    = (float)$selectedPkg['price'];
    $pkgDays     = (int)$selectedPkg['days'];
    $pkgType     = $selectedPkg['type'];
    $pkgName     = $selectedPkg['name'];

    try {
        $pdo->beginTransaction();

        // Khóa dòng user để kiểm tra và trừ số dư an toàn (ACID transaction)
        $stmtLock = $pdo->prepare("SELECT balance, uuid FROM users WHERE id = ? FOR UPDATE");
        $stmtLock->execute([$currentUser['id']]);
        $userData = $stmtLock->fetch();

        if (!$userData) {
            throw new Exception("Không tìm thấy thông tin tài khoản người dùng.");
        }

        $currentBal = (float)$userData['balance'];

        // Kiểm tra số dư tài khoản
        if ($currentBal < $pkgPrice) {
            $pdo->rollBack();
            $needAmount = $pkgPrice - $currentBal;
            set_flash(
                'warning',
                'Số dư tài khoản của bạn hiện có <strong>' . format_currency($currentBal) . '</strong>, còn thiếu <strong>' . format_currency($needAmount) . '</strong> để mua <strong>' . htmlspecialchars($pkgName) . '</strong>.<br><div class="mt-3"><a href="/payments/deposit" class="btn btn-sm btn-success px-3 py-2 rounded-pill"><i class="fa-solid fa-wallet me-1"></i> Nạp tiền ngay</a></div>',
                'Số dư không đủ'
            );
            header("Location: buy-key.php");
            exit;
        }

        // Trừ tiền tài khoản
        $newBal = $currentBal - $pkgPrice;
        $stmtUpBal = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
        $stmtUpBal->execute([$newBal, $currentUser['id']]);

        // Tạo mã đơn hàng duy nhất (#ORD-...)
        $orderCode = 'ORD-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        // Tạo chuỗi mã bản quyền kích hoạt (TQTECH-KEY-XXXX-XXXX-XXXX)
        $licenseKey = 'TQ-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));

        // Nếu là gói Combo thì chỉ định Cloud Server
        $cloudServer = null;
        if ($pkgType === 'combo') {
            $cloudServer = 'Node-Cloud#0' . rand(1, 8) . ' (Hạ tầng 24/24)';
        }

        // Tính thời hạn hết hạn
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$pkgDays} days"));

        // Lưu đơn hàng vào key_orders
        $stmtInsertOrder = $pdo->prepare("
            INSERT INTO key_orders (
                user_uuid, order_code, package_type, package_name, duration_days, license_key, cloud_server, amount, status, expires_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?
            )
        ");
        $stmtInsertOrder->execute([
            $userData['uuid'],
            $orderCode,
            $pkgType,
            $pkgName,
            $pkgDays,
            $licenseKey,
            $cloudServer,
            $pkgPrice,
            $expiresAt
        ]);

        // Lưu biến động số dư vào bảng transactions
        $transCode = 'PAY-' . strtoupper(substr(uniqid(), -8));
        $stmtInsertTrans = $pdo->prepare("
            INSERT INTO transactions (
                user_uuid, code, type, amount, balance_before, balance_after, status, note
            ) VALUES (
                ?, ?, 'Payment', ?, ?, ?, 'Success', ?
            )
        ");
        $stmtInsertTrans->execute([
            $userData['uuid'],
            $transCode,
            $pkgPrice,
            $currentBal,
            $newBal,
            "Thanh toán đơn hàng {$orderCode} ({$pkgName})"
        ]);

        $pdo->commit();

        set_flash(
            'success',
            'Chúc mừng bạn đã thanh toán thành công <strong>' . htmlspecialchars($pkgName) . '</strong>!<br>' .
            '<div class="p-3 my-2 bg-light border rounded-3 text-start font-monospace small">' .
            '<strong>Mã đơn:</strong> <span class="text-primary">#' . $orderCode . '</span><br>' .
            '<strong>Mã Key:</strong> <span class="text-success fw-bold">' . $licenseKey . '</span><br>' .
            ($cloudServer ? '<strong>Máy chủ:</strong> <span class="text-info">' . $cloudServer . '</span><br>' : '') .
            '<strong>Thời hạn đến:</strong> ' . date('d/m/Y H:i:s', strtotime($expiresAt)) .
            '</div>' .
            'Hãy sao chép mã Key để dán vào Tool Golike hoặc quản lý ở bảng lịch sử bên dưới.',
            'Thanh toán thành công'
        );

        header("Location: buy-key.php");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Có lỗi xảy ra trong quá trình thanh toán: ' . $e->getMessage(), 'Lỗi giao dịch');
        header("Location: buy-key.php");
        exit;
    }
}

// Lấy danh sách lịch sử đơn hàng của người dùng hiện tại
$stmtHistory = $pdo->prepare("
    SELECT * FROM key_orders 
    WHERE user_uuid = ? 
    ORDER BY id DESC
");
$stmtHistory->execute([$currentUser['uuid']]);
$keyHistory = $stmtHistory->fetchAll();

$flash = get_flash();
$csrfToken = get_csrf_token();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mua Key Bản Quyền & Treo Cloud - <?= htmlspecialchars(APP_NAME) ?></title>

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
            --gradient-combo: linear-gradient(135deg, #0ea5e9 0%, #3b82f6 50%, #8b5cf6 100%);
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

        .brand-name span {
            color: var(--primary);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }

        /* Khối Số Dư Nạp Vào (Bấm vào chuyển nạp tiền) */
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
            min-width: 0;
            overflow-x: hidden !important;
        }

        /* Card Container */
        .dash-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-card);
            margin-bottom: 24px;
        }

        /* Banner Giới Thiệu */
        .pricing-hero-banner {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 40%, #4338ca 100%);
            border-radius: 20px;
            padding: 30px;
            color: #ffffff;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 30px -10px rgba(49, 46, 129, 0.4);
        }

        .pricing-hero-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 320px;
            height: 320px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.35) 0%, rgba(99, 102, 241, 0) 70%);
            border-radius: 50%;
        }

        .banner-feature-pill {
            background: rgba(255, 255, 255, 0.12) !important;
            border: 1px solid rgba(255, 255, 255, 0.28) !important;
            color: #ffffff !important;
            font-size: 0.82rem;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 50px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
        }

        /* Tabs chuyển đổi gói (Key vs Combo) */
        .pricing-tabs {
            display: inline-flex;
            background: #e2e8f0;
            padding: 5px;
            border-radius: 50px;
            margin-bottom: 24px;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .pricing-tab-btn {
            padding: 9px 24px;
            border-radius: 50px;
            font-size: 0.92rem;
            font-weight: 700;
            border: none;
            background: transparent;
            color: #475569;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .pricing-tab-btn.active {
            background: #ffffff;
            color: var(--primary);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        /* Thẻ Gói Giá (Pricing Card) */
        .pkg-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .pkg-card {
            background: #ffffff;
            border: 2px solid #e2e8f0;
            border-radius: 20px;
            padding: 48px 18px 20px;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .pkg-card:hover {
            transform: translateY(-5px);
            border-color: #818cf8;
            box-shadow: 0 16px 32px -10px rgba(79, 70, 229, 0.18);
        }

        /* Gói Phổ Biến: Viền cam đậm và hiệu ứng nổi bật như ảnh chụp */
        .pkg-card.popular {
            border: 2px solid #f59e0b !important;
            background: linear-gradient(180deg, #fffdf8 0%, #ffffff 100%);
            box-shadow: 0 10px 25px -8px rgba(245, 158, 11, 0.22);
        }

        .pkg-card.popular:hover {
            border-color: #d97706 !important;
            box-shadow: 0 16px 32px -10px rgba(245, 158, 11, 0.32);
        }

        .pkg-card-combo {
            border-color: #e0e7ff;
            background: linear-gradient(180deg, #faf5ff 0%, #ffffff 100%);
        }

        .pkg-card-combo:hover {
            border-color: #a855f7;
            box-shadow: 0 16px 32px -10px rgba(168, 85, 247, 0.22);
        }

        /* Huy hiệu nhãn gói - CỐ ĐỊNH NẰM Ở GÓC TRÁI TRÊN CỦA THẺ */
        .pkg-badge {
            position: absolute;
            top: 14px;
            left: 16px;
            right: auto;
            font-size: 0.76rem;
            font-weight: 800;
            padding: 4px 16px;
            border-radius: 50px;
            letter-spacing: 0.2px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            z-index: 2;
            display: inline-flex;
            align-items: center;
            line-height: 1.35;
        }

        /* 1. Badge Phổ biến: Màu cam vàng, chữ đen đậm (Chuẩn ảnh mẫu gói 1 Tuần) */
        .badge-popular {
            background: #f59e0b !important;
            color: #0f172a !important;
            font-weight: 800 !important;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3) !important;
        }

        /* 2. Badge Ưu đãi & Thẻ viền xanh tím (Chuẩn ảnh mẫu 1) */
        .badge-uudai {
            background: #4338ca !important;
            color: #ffffff !important;
            font-weight: 800 !important;
            box-shadow: 0 2px 8px rgba(67, 56, 202, 0.3) !important;
        }

        .card-uudai {
            border: 2px solid #4338ca !important;
        }

        /* 3. Badge Tiện lợi (Chuẩn ảnh mẫu 4: Dải màu Gradient Tím sang Hồng) */
        .badge-tienloi {
            background: linear-gradient(90deg, #6366f1 0%, #a855f7 50%, #d946ef 100%) !important;
            color: #ffffff !important;
            font-weight: 800 !important;
            box-shadow: 0 3px 12px rgba(168, 85, 247, 0.35) !important;
        }

        /* 4. Badge Hot Combo (Chuẩn ảnh mẫu 3) */
        .badge-combo-hot {
            background: linear-gradient(90deg, #6366f1 0%, #a855f7 50%, #d946ef 100%) !important;
            color: #ffffff !important;
            font-weight: 800 !important;
            box-shadow: 0 3px 12px rgba(168, 85, 247, 0.35) !important;
        }

        /* 5. Badge Siêu Tiết Kiệm (Chuẩn ảnh mẫu 2) */
        .badge-sieutietkiem {
            background: linear-gradient(90deg, #6366f1 0%, #a855f7 50%, #d946ef 100%) !important;
            color: #ffffff !important;
            font-weight: 800 !important;
            box-shadow: 0 3px 12px rgba(168, 85, 247, 0.35) !important;
        }

        /* 6. Badge Tiết kiệm (Xanh ngọc / Cyan) */
        .badge-tietkiem {
            background: #0284c7 !important;
            color: #ffffff !important;
            font-weight: 800 !important;
            box-shadow: 0 2px 8px rgba(2, 132, 199, 0.3) !important;
        }

        .badge-combo-vip {
            background: linear-gradient(135deg, #4f46e5 0%, #06b6d4 100%) !important;
            color: #ffffff !important;
            font-weight: 800 !important;
            box-shadow: 0 3px 10px rgba(79, 70, 229, 0.35) !important;
        }

        .pkg-title {
            font-size: 1.12rem;
            font-weight: 800;
            color: var(--text-heading);
            margin-bottom: 4px;
        }

        .pkg-duration {
            font-size: 0.8rem;
            color: #64748b;
            font-weight: 600;
            margin-bottom: 14px;
        }

        .pkg-price-box {
            background: #f8fafc;
            border-radius: 14px;
            padding: 14px 10px;
            text-align: center;
            margin-bottom: 16px;
            border: 1px solid #f1f5f9;
            transition: var(--transition);
        }

        .pkg-old-price {
            text-decoration: line-through;
            color: #94a3b8;
            font-size: 0.86rem;
            font-weight: 600;
        }

        .pkg-discount-pill {
            background: #ef4444;
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 6px;
            letter-spacing: 0.3px;
            display: inline-flex;
            align-items: center;
            box-shadow: 0 2px 6px rgba(239, 68, 68, 0.25);
        }

        .pkg-price {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--primary);
            line-height: 1.1;
            margin: 4px 0 2px;
        }

        .pkg-price-unit {
            font-size: 0.76rem;
            color: #64748b;
        }

        .pkg-save-label {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            color: #15803d;
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            padding: 2px 9px;
            border-radius: 50px;
        }

        .pkg-features {
            list-style: none;
            padding: 0;
            margin: 0 0 18px;
            flex-grow: 1;
        }

        .pkg-features li {
            font-size: 0.82rem;
            color: #475569;
            margin-bottom: 8px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            line-height: 1.35;
        }

        .pkg-features li i {
            color: #10b981;
            margin-top: 2px;
            font-size: 0.85rem;
            flex-shrink: 0;
        }

        .btn-buy-pkg {
            width: 100%;
            padding: 10px;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 700;
            border: none;
            color: #ffffff;
            background: var(--gradient-primary);
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }

        .btn-buy-pkg:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(79, 70, 229, 0.35);
            color: #ffffff;
        }

        .btn-buy-combo {
            background: var(--gradient-combo) !important;
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.25) !important;
        }

        .btn-buy-combo:hover {
            box-shadow: 0 6px 18px rgba(14, 165, 233, 0.35) !important;
        }

        /* Bảng Lịch Sử Mua Key */
        .history-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .history-table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 12px 14px;
            border-bottom: 1px solid #e2e8f0;
            letter-spacing: 0.4px;
        }

        .history-table td {
            padding: 14px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.88rem;
            vertical-align: middle;
        }

        .history-table tr:hover td {
            background: #f8faff;
        }

        .key-code-box {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f1f5f9;
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

        /* ==========================================================
         * 4. SVG STROKE DRAW ANIMATION DIALOG
         * ========================================================== */
        .svg-dialog-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(5px);
            z-index: 2050;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            visibility: hidden;
            transition: all 0.25s ease-in-out;
        }

        .svg-dialog-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .svg-dialog-box {
            background: #ffffff;
            border-radius: 24px;
            padding: 32px 28px;
            max-width: 440px;
            width: 100%;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            transform: scale(0.9) translateY(20px);
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .svg-dialog-overlay.active .svg-dialog-box {
            transform: scale(1) translateY(0);
        }

        .svg-icon-container {
            width: 80px;
            height: 80px;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
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

        @keyframes drawStroke {
            to { stroke-dashoffset: 0; }
        }

        @keyframes fadeInDot {
            to { opacity: 1; }
        }

        .svg-success .svg-circle { stroke: #10b981; }
        .svg-success .svg-check { stroke: #10b981; }
        .svg-confirm .svg-circle { stroke: #6366f1; }
        .svg-confirm .svg-question { stroke: #6366f1; }
        .svg-confirm .svg-question-dot { fill: #6366f1; }
        .svg-warning .svg-circle { stroke: #f59e0b; }
        .svg-warning .svg-question { stroke: #f59e0b; }
        .svg-warning .svg-question-dot { fill: #f59e0b; }

        /* Responsive Mobile & Tablet chuẩn index.php */
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
        }

        @media (max-width: 767.98px) {
            .app-sidebar {
                top: 56px;
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
            .pkg-grid {
                grid-template-columns: 1fr;
            }
            .pricing-hero-banner {
                padding: 20px 16px;
            }
            .pricing-tabs {
                display: flex !important;
                width: 100% !important;
                padding: 4px !important;
                margin-bottom: 18px !important;
            }
            .pricing-tab-btn {
                flex: 1 1 50% !important;
                padding: 9px 8px !important;
                font-size: 0.84rem !important;
                font-weight: 700 !important;
                justify-content: center !important;
                white-space: nowrap !important;
                text-align: center !important;
                gap: 5px !important;
            }
        }
    </style>
</head>
<body>

    <!-- ==========================================================
     * HEADER CỐ ĐỊNH (FIXED TOPBAR)
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
                    <span>ThanhQuy</span><span class="brand-tech-suffix">Tech</span>
                </div>
            </a>
        </div>

        <div class="header-right">
            <!-- Số dư tài khoản: Bấm vào khung chuyển qua nạp tiền -->
            <a href="/payments/deposit" class="header-balance-card" title="Nạp tiền vào tài khoản">
                <div class="balance-wallet-icon">
                    <i class="fa-solid fa-wallet"></i>
                </div>
                <div class="balance-text-group">
                    <span class="balance-title">Số dư</span>
                    <span class="balance-val"><?= format_currency($currentUser['balance']) ?></span>
                </div>
            </a>

            <!-- Khối Avatar & Bảng Popup Hồ Sơ -->
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
                <a href="index.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fa-solid fa-house"></i></span>
                    <span class="sidebar-title">Trang chủ</span>
                </a>
            </li>
            <!-- Mua key (Active) -->
            <li>
                <a href="buy-key.php" class="sidebar-link active">
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
                <a href="/referral" class="sidebar-link">
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

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- ==========================================================
     * KHU VỰC NỘI DUNG CHÍNH (APP MAIN)
     * ========================================================== -->
    <main class="app-main">
        <div class="dashboard-container-inner">

            <!-- Banner Giới Thiệu Dịch Vụ -->
            <div class="pricing-hero-banner">
                <div>
                    <span class="badge bg-warning text-dark fw-bold px-3 py-1 mb-2 rounded-pill">
                        <i class="fa-solid fa-fire-flame-curved me-1"></i> Bảng Giá Bản Quyền & Dịch Vụ Cloud
                    </span>
                    <h2 class="fw-extrabold mb-2" style="font-weight: 800;">
                        Mua Bản Quyền Key & Treo Cloud Tự Động
                    </h2>
                    <p class="text-light opacity-90 mb-3" style="font-size: 0.95rem; max-width: 820px;">
                        Kích hoạt tự động ngay sau khi thanh toán. Hệ thống máy chủ Cloud NVMe cày ngầm 24/24 siêu tốc độ và hỗ trợ kỹ thuật tận tình 24/7.
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="banner-feature-pill">
                            <i class="fa-solid fa-shield-check text-success"></i> Kích hoạt tức thì
                        </span>
                        <span class="banner-feature-pill">
                            <i class="fa-solid fa-bolt text-warning"></i> Tự động cấp Key
                        </span>
                        <span class="banner-feature-pill">
                            <i class="fa-solid fa-server text-info"></i> Cloud NVMe Uptime 99.9%
                        </span>
                    </div>
                </div>
            </div>

            <!-- Khối Tabs Chuyển Đổi: Bản Quyền Key vs Combo Cloud -->
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div class="pricing-tabs">
                    <button type="button" class="pricing-tab-btn active" id="tabKeyOnlyBtn" onclick="switchPricingTab('key_only')">
                        <i class="fa-solid fa-key"></i> Bản Quyền Key
                    </button>
                    <button type="button" class="pricing-tab-btn" id="tabComboBtn" onclick="switchPricingTab('combo')">
                        <i class="fa-solid fa-cloud-bolt text-warning"></i> Combo Key + Cloud
                    </button>
                </div>
                <div class="text-muted small">
                    <i class="fa-solid fa-circle-info text-primary me-1"></i> Gói thời hạn càng dài giá trên ngày càng rẻ, tiết kiệm tối đa!
                </div>
            </div>

            <!-- ================= DANH SÁCH GÓI CHỈ KEY (1.000đ/ngày) ================= -->
            <div id="sectionKeyOnly">
                <div class="pkg-grid">
                    <?php 
                    $keyPkgs = array_filter($PACKAGES, fn($p) => $p['type'] === 'key_only');
                    foreach ($keyPkgs as $pkg): 
                    ?>
                        <div class="pkg-card <?= $pkg['popular'] ? 'popular' : '' ?> <?= !empty($pkg['card_class']) ? $pkg['card_class'] : '' ?>">
                            <span class="pkg-badge <?= $pkg['badge_class'] ?>"><?= htmlspecialchars($pkg['badge']) ?></span>
                            
                            <div>
                                <h4 class="pkg-title mt-1 mb-1"><?= htmlspecialchars($pkg['name']) ?></h4>
                                <div class="pkg-duration">
                                    <i class="fa-regular fa-clock me-1 text-muted"></i> Thời hạn: <strong><?= $pkg['days'] ?> ngày</strong> (<?= $pkg['days'] * 24 ?> giờ)
                                </div>

                                <div class="pkg-price-box">
                                    <?php if (!empty($pkg['discount_pct'])): ?>
                                        <div class="d-flex align-items-center justify-content-center gap-2 mb-1">
                                            <span class="pkg-old-price"><?= number_format($pkg['original_price'], 0, ',', '.') ?> ₫</span>
                                            <span class="pkg-discount-pill">-<?= $pkg['discount_pct'] ?>%</span>
                                        </div>
                                    <?php else: ?>
                                        <div class="d-flex align-items-center justify-content-center mb-1">
                                            <span class="badge bg-light text-muted border px-2 py-0" style="font-size: 0.68rem;">Gói cơ bản</span>
                                        </div>
                                    <?php endif; ?>

                                    <div class="pkg-price"><?= number_format($pkg['price'], 0, ',', '.') ?> <span style="font-size: 1rem;">₫</span></div>
                                    <div class="pkg-price-unit">Chỉ <strong><?= number_format(round($pkg['price'] / $pkg['days']), 0, ',', '.') ?>đ</strong> / 24h</div>

                                    <?php if (!empty($pkg['discount_pct'])): ?>
                                        <div class="pkg-save-label">
                                            <i class="fa-solid fa-piggy-bank me-1"></i> Tiết kiệm <?= number_format($pkg['original_price'] - $pkg['price'], 0, ',', '.') ?>đ
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <ul class="pkg-features">
                                    <?php foreach ($pkg['features'] as $ft): ?>
                                        <li><i class="fa-solid fa-check"></i> <span><?= htmlspecialchars($ft) ?></span></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <button type="button" 
                                    class="btn-buy-pkg" 
                                    onclick="openPurchaseModal('<?= $pkg['id'] ?>', '<?= htmlspecialchars($pkg['name']) ?>', '<?= $pkg['days'] ?> ngày', <?= $pkg['price'] ?>)">
                                <i class="fa-solid fa-cart-shopping me-1"></i> Mua ngay
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ================= DANH SÁCH GÓI COMBO KEY + CLOUD (4.000đ/ngày) ================= -->
            <div id="sectionCombo" style="display: none;">
                <div class="pkg-grid">
                    <?php 
                    $comboPkgs = array_filter($PACKAGES, fn($p) => $p['type'] === 'combo');
                    foreach ($comboPkgs as $pkg): 
                    ?>
                        <div class="pkg-card pkg-card-combo <?= $pkg['popular'] ? 'popular' : '' ?> <?= !empty($pkg['card_class']) ? $pkg['card_class'] : '' ?>">
                            <span class="pkg-badge <?= $pkg['badge_class'] ?>"><?= htmlspecialchars($pkg['badge']) ?></span>
                            
                            <div>
                                <h4 class="pkg-title mt-1 mb-1"><?= htmlspecialchars($pkg['name']) ?></h4>
                                <div class="pkg-duration">
                                    <i class="fa-regular fa-clock me-1 text-muted"></i> Thời hạn: <strong><?= $pkg['days'] ?> ngày</strong> (Key + Treo máy 24/7)
                                </div>

                                <div class="pkg-price-box" style="background: #f0f9ff; border-color: #e0f2fe;">
                                    <?php if (!empty($pkg['discount_pct'])): ?>
                                        <div class="d-flex align-items-center justify-content-center gap-2 mb-1">
                                            <span class="pkg-old-price"><?= number_format($pkg['original_price'], 0, ',', '.') ?> ₫</span>
                                            <span class="pkg-discount-pill" style="background: #e11d48;">-<?= $pkg['discount_pct'] ?>%</span>
                                        </div>
                                    <?php else: ?>
                                        <div class="d-flex align-items-center justify-content-center mb-1">
                                            <span class="badge bg-light text-muted border px-2 py-0" style="font-size: 0.68rem;">Gói cơ bản</span>
                                        </div>
                                    <?php endif; ?>

                                    <div class="pkg-price text-info"><?= number_format($pkg['price'], 0, ',', '.') ?> <span style="font-size: 1rem;">₫</span></div>
                                    <div class="pkg-price-unit">Chỉ <strong><?= number_format(round($pkg['price'] / $pkg['days']), 0, ',', '.') ?>đ</strong> / 24h trọn gói</div>

                                    <?php if (!empty($pkg['discount_pct'])): ?>
                                        <div class="pkg-save-label" style="background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8;">
                                            <i class="fa-solid fa-bolt text-warning me-1"></i> Tiết kiệm <?= number_format($pkg['original_price'] - $pkg['price'], 0, ',', '.') ?>đ
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <ul class="pkg-features">
                                    <?php foreach ($pkg['features'] as $ft): ?>
                                        <li><i class="fa-solid fa-circle-check text-info"></i> <span><?= htmlspecialchars($ft) ?></span></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <button type="button" 
                                    class="btn-buy-pkg btn-buy-combo" 
                                    onclick="openPurchaseModal('<?= $pkg['id'] ?>', '<?= htmlspecialchars($pkg['name']) ?>', '<?= $pkg['days'] ?> ngày', <?= $pkg['price'] ?>)">
                                <i class="fa-solid fa-bolt-lightning me-1"></i> Thuê Combo Ngay
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ================= BẢNG LỊCH SỬ MUA KEY ================= -->
            <div class="dash-card">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <h4 class="fw-bold text-dark mb-1">
                            <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Lịch Sử Đơn Hàng Mua Key & Cloud
                        </h4>
                        <p class="text-muted small mb-0">Quản lý mã Key kích hoạt, thời hạn sử dụng và trạng thái máy chủ của bạn</p>
                    </div>
                    <span class="badge bg-light text-dark border px-3 py-2">
                        Tổng đơn đã mua: <strong><?= count($keyHistory) ?></strong>
                    </span>
                </div>

                <?php if (empty($keyHistory)): ?>
                    <div class="text-center py-5">
                        <div class="p-3 bg-light rounded-circle d-inline-flex align-items-center justify-content-center text-muted mb-3" style="width: 70px; height: 70px;">
                            <i class="fa-solid fa-key fs-2"></i>
                        </div>
                        <h5 class="fw-bold text-dark">Bạn chưa có đơn hàng nào</h5>
                        <p class="text-muted small mb-3">Hãy chọn một trong các gói phía trên để trải nghiệm công cụ tự động hóa đỉnh cao.</p>
                        <button type="button" class="btn btn-primary rounded-pill px-4" onclick="window.scrollTo({top: 0, behavior: 'smooth'})">
                            <i class="fa-solid fa-cart-plus me-1"></i> Chọn gói mua ngay
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>Mã đơn</th>
                                    <th>Gói dịch vụ</th>
                                    <th>Mã Key bản quyền</th>
                                    <th>Máy chủ Cloud</th>
                                    <th class="text-end">Thanh toán</th>
                                    <th>Thời hạn đến</th>
                                    <th class="text-center">Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($keyHistory as $order): ?>
                                    <?php 
                                    $isExpired = ($order['status'] === 'Expired' || strtotime($order['expires_at']) < time());
                                    ?>
                                    <tr>
                                        <td>
                                            <strong class="font-monospace text-primary">#<?= htmlspecialchars($order['order_code']) ?></strong>
                                            <div class="text-muted" style="font-size: 0.72rem;"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($order['package_name']) ?></div>
                                            <small class="text-muted">Thời hạn: <?= $order['duration_days'] ?> ngày</small>
                                        </td>
                                        <td>
                                            <div class="key-code-box">
                                                <span><?= htmlspecialchars($order['license_key']) ?></span>
                                                <button type="button" 
                                                        class="btn-copy-key" 
                                                        title="Sao chép Key" 
                                                        onclick="copyKeyText('<?= htmlspecialchars($order['license_key']) ?>')">
                                                    <i class="fa-regular fa-copy"></i>
                                                </button>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($order['cloud_server'])): ?>
                                                <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1 font-monospace">
                                                    <i class="fa-solid fa-server me-1"></i><?= htmlspecialchars($order['cloud_server']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small"><i class="fa-solid fa-laptop me-1"></i>Chạy thiết bị riêng</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <strong class="text-dark"><?= format_currency($order['amount']) ?></strong>
                                        </td>
                                        <td>
                                            <div class="small fw-semibold text-dark"><?= date('d/m/Y H:i', strtotime($order['expires_at'])) ?></div>
                                            <?php if (!$isExpired): ?>
                                                <?php 
                                                $leftSeconds = strtotime($order['expires_at']) - time();
                                                $leftDays = floor($leftSeconds / 86400);
                                                $leftHours = floor(($leftSeconds % 86400) / 3600);
                                                ?>
                                                <small class="text-success fw-bold">
                                                    <i class="fa-regular fa-clock me-1"></i>Còn <?= $leftDays ?> ngày <?= $leftHours ?>h
                                                </small>
                                            <?php else: ?>
                                                <small class="text-muted">Đã kết thúc</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if (!$isExpired): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-1 rounded-pill">
                                                    <i class="fa-solid fa-circle-check me-1"></i> Đang hoạt động
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-3 py-1 rounded-pill">
                                                    <i class="fa-solid fa-circle-xmark me-1"></i> Đã hết hạn
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
    </main>

    <!-- ==========================================================
     * MODAL XÁC NHẬN MUA GÓI (TÍCH HỢP TRỰC QUAN)
     * ========================================================== -->
    <form id="purchaseForm" method="POST" action="buy-key.php" style="display: none;">
        <input type="hidden" name="action" value="purchase_package">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="package_id" id="formPackageId" value="">
    </form>

    <!-- ==========================================================
     * DIALOG SVG ANIMATION (THÔNG BÁO & XÁC NHẬN ĐỒNG BỘ INDEX)
     * ========================================================== -->
    <div class="svg-dialog-overlay" id="svgDialogOverlay">
        <div class="svg-dialog-box">
            <div class="svg-icon-container" id="svgDialogIcon"></div>
            <h4 class="fw-bold mb-2 text-dark" id="svgDialogTitle">Thông báo</h4>
            <div class="text-muted mb-4" id="svgDialogMessage"></div>
            <div class="d-flex justify-content-center gap-2" id="svgDialogActions"></div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // ==========================================================
        // DIALOG XÁC NHẬN HIỆU ỨNG SVG STROKE DRAW ANIMATION
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
            `,
            warning: `
                <svg class="svg-draw-icon svg-warning" viewBox="0 0 80 80">
                    <circle class="svg-circle" cx="40" cy="40" r="34" />
                    <line x1="40" y1="24" x2="40" y2="46" stroke="#f59e0b" stroke-width="4.5" stroke-linecap="round" />
                    <circle cx="40" cy="56" r="3" fill="#f59e0b" />
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
                <button type="button" class="btn btn-primary px-4 rounded-pill" id="svgCloseBtn">
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
                <button type="button" class="btn btn-light border px-4 rounded-pill" id="cancelBtn">Hủy bỏ</button>
                <button type="button" class="btn btn-danger px-4 rounded-pill" id="confirmBtn">Đăng xuất</button>
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
        // XÁC NHẬN MUA GÓI BẢN QUYỀN
        // ==========================================================
        const userBalance = <?= (float)$currentUser['balance'] ?>;

        function openPurchaseModal(pkgId, pkgName, duration, price) {
            const overlay = document.getElementById('svgDialogOverlay');
            const iconEl = document.getElementById('svgDialogIcon');
            const titleEl = document.getElementById('svgDialogTitle');
            const messageEl = document.getElementById('svgDialogMessage');
            const actionsEl = document.getElementById('svgDialogActions');

            const formattedPrice = new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(price);
            const formattedBalance = new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(userBalance);
            const remainBalance = userBalance - price;
            const formattedRemain = new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(remainBalance);

            if (userBalance < price) {
                iconEl.innerHTML = SVG_TEMPLATES.warning;
                titleEl.textContent = 'Số dư không đủ';
                messageEl.innerHTML = `
                    Bạn đang chọn: <strong>${pkgName}</strong> (${duration})<br>
                    Giá gói: <strong class="text-danger">${formattedPrice}</strong><br>
                    Số dư hiện có: <strong>${formattedBalance}</strong><br>
                    <span class="text-danger small mt-2 d-block">Vui lòng nạp thêm tiền để tiến hành thanh toán!</span>
                `;
                actionsEl.innerHTML = `
                    <button type="button" class="btn btn-light border px-3 rounded-pill" id="cancelBtn">Đóng</button>
                    <a href="/payments/deposit" class="btn btn-success px-4 rounded-pill">
                        <i class="fa-solid fa-wallet me-1"></i> Nạp tiền ngay
                    </a>
                `;
                overlay.classList.add('active');
                document.getElementById('cancelBtn').onclick = () => overlay.classList.remove('active');
                return;
            }

            iconEl.innerHTML = SVG_TEMPLATES.confirm;
            titleEl.textContent = 'Xác nhận thanh toán';
            messageEl.innerHTML = `
                Bạn có chắc chắn muốn mua <strong>${pkgName}</strong> không?<br>
                <div class="bg-light p-3 rounded-3 text-start small font-monospace mt-3 border">
                    <div>• <strong>Thời hạn:</strong> ${duration}</div>
                    <div>• <strong>Số tiền trừ:</strong> <span class="text-primary fw-bold">${formattedPrice}</span></div>
                    <div>• <strong>Số dư hiện tại:</strong> ${formattedBalance}</div>
                    <div>• <strong>Số dư sau mua:</strong> <span class="text-success fw-bold">${formattedRemain}</span></div>
                </div>
            `;
            actionsEl.innerHTML = `
                <button type="button" class="btn btn-light border px-4 rounded-pill" id="cancelBtn">Hủy bỏ</button>
                <button type="button" class="btn btn-primary px-4 rounded-pill" id="confirmBuyBtn">
                    <i class="fa-solid fa-cart-check me-1"></i> Thanh toán ngay
                </button>
            `;

            overlay.classList.add('active');
            document.getElementById('cancelBtn').onclick = () => overlay.classList.remove('active');
            document.getElementById('confirmBuyBtn').onclick = () => {
                document.getElementById('formPackageId').value = pkgId;
                document.getElementById('purchaseForm').submit();
            };
        }

        // ==========================================================
        // SAO CHÉP MÃ KEY VÀO CLIPBOARD
        // ==========================================================
        function copyKeyText(keyText) {
            navigator.clipboard.writeText(keyText).then(() => {
                showSvgAlert(
                    'Đã sao chép mã Key bản quyền vào bộ nhớ đệm:<br><div class="mt-2 p-2 bg-light border rounded text-success font-monospace fw-bold">' + keyText + '</div><div class="mt-2 small text-muted">Dán mã này vào Tool để kích hoạt sử dụng ngay.</div>',
                    'Sao chép thành công',
                    'success'
                );
            }).catch(() => {
                alert('Mã key của bạn: ' + keyText);
            });
        }

        // ==========================================================
        // CHUYỂN ĐỔI TAB GÓI (CHỈ KEY vs COMBO CLOUD)
        // ==========================================================
        function switchPricingTab(type) {
            const tabKeyOnlyBtn = document.getElementById('tabKeyOnlyBtn');
            const tabComboBtn = document.getElementById('tabComboBtn');
            const sectionKeyOnly = document.getElementById('sectionKeyOnly');
            const sectionCombo = document.getElementById('sectionCombo');

            if (type === 'combo') {
                tabKeyOnlyBtn.classList.remove('active');
                tabComboBtn.classList.add('active');
                sectionKeyOnly.style.display = 'none';
                sectionCombo.style.display = 'block';
            } else {
                tabComboBtn.classList.remove('active');
                tabKeyOnlyBtn.classList.add('active');
                sectionCombo.style.display = 'none';
                sectionKeyOnly.style.display = 'block';
            }
        }

        // Kiểm tra URL param để tự động mở tab Combo nếu có ?tab=combo
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('tab') === 'combo') {
            switchPricingTab('combo');
        }

        // ==========================================================
        // ĐIỀU KHIỂN BẬT/TẮT BẢNG POPUP HỒ SƠ
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
