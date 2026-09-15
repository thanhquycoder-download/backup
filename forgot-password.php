<?php
/**
 * ==========================================================
 * TRANG QUÊN MẬT KHẨU (FORGOT PASSWORD) - NỀN TRẮNG ĐỘC LẬP
 * File: forgot-password.php
 * Website: ThanhQuyTech
 * ==========================================================
 */

require_once __DIR__ . '/config/config.php';

require_guest();

$errors = [];
$successMessage = '';
$demoResetLink = '';
$emailInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Phiên làm việc hết hạn. Vui lòng thử lại.';
    }

    $emailInput = trim($_POST['email'] ?? '');

    if (empty($emailInput) || !filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Vui lòng nhập một địa chỉ email hợp lệ.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id, uuid, name, email FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$emailInput]);
        $user = $stmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

            // Xóa các mã đặt lại mật khẩu cũ liên kết với người dùng này
            $stmtDel = $pdo->prepare("DELETE FROM password_resets WHERE user_uuid = ? OR email = ?");
            $stmtDel->execute([$user['uuid'], $emailInput]);

            // Thêm mã đặt lại mới liên kết chặt chẽ qua users.uuid và users.email
            $stmtInsert = $pdo->prepare("INSERT INTO password_resets (user_uuid, email, token, expires_at) VALUES (?, ?, ?, ?)");
            $stmtInsert->execute([$user['uuid'], $emailInput, $token, $expiresAt]);

            $demoResetLink = "reset-password.php?token=" . urlencode($token);
            $successMessage = 'Yêu cầu khôi phục mật khẩu đã được tạo thành công! (Thời hạn 30 phút)';
        } else {
            $successMessage = 'Nếu email của bạn tồn tại trong hệ thống, liên kết khôi phục sẽ được gửi đến hòm thư.';
        }
    }
}

$csrfToken = get_csrf_token();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quên mật khẩu - <?= htmlspecialchars(APP_NAME) ?></title>

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
            --gradient-btn: linear-gradient(135deg, #4f46e5 0%, #6366f1 50%, #06b6d4 100%);
            --radius-md: 12px;
            --radius-lg: 20px;
            --shadow-card: 0 20px 45px -15px rgba(0, 0, 0, 0.08), 0 2px 10px rgba(0, 0, 0, 0.03);
            --transition: all 0.25s ease-in-out;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif !important;
            background-color: var(--bg-body) !important;
            color: var(--text-body) !important;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 15px;
            position: relative;
        }

        .auth-card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 460px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            padding: 42px 36px;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            padding: 5px 14px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 700;
            color: #4f46e5;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
        }

        .auth-title {
            font-size: 1.85rem;
            font-weight: 800;
            color: var(--text-heading);
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .auth-subtitle {
            color: var(--text-muted);
            font-size: 0.92rem;
            margin-bottom: 28px;
        }

        .form-label {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--text-heading);
            margin-bottom: 8px;
            display: block;
        }

        .input-group-custom {
            position: relative;
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            color: #94a3b8;
            font-size: 1rem;
            z-index: 2;
        }

        .form-control-custom {
            width: 100%;
            height: 48px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: var(--radius-md);
            padding: 0 16px 0 44px;
            font-size: 0.94rem;
            color: var(--text-heading);
            outline: none;
            transition: var(--transition);
        }

        .form-control-custom:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.12);
        }

        .btn-gradient {
            width: 100%;
            height: 48px;
            background: var(--gradient-btn);
            color: #ffffff;
            border: none;
            border-radius: var(--radius-md);
            font-size: 1rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 10px 25px -6px rgba(79, 70, 229, 0.4);
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 28px -6px rgba(79, 70, 229, 0.55);
        }

        .auth-footer {
            margin-top: 26px;
            text-align: center;
            font-size: 0.88rem;
            color: var(--text-muted);
            border-top: 1px solid #f1f5f9;
            padding-top: 20px;
        }

        .auth-footer a {
            color: var(--primary);
            font-weight: 700;
            text-decoration: none;
        }

        /* SVG Line Drawing Dialog */
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

        .svg-error .svg-circle {
            stroke: #ef4444;
            stroke-dasharray: 220;
            stroke-dashoffset: 220;
            animation: strokeCircle 0.6s ease-out forwards;
        }
        .svg-error .line-1 {
            stroke: #ef4444;
            stroke-dasharray: 50;
            stroke-dashoffset: 50;
            animation: strokeCross 0.3s 0.45s ease-out forwards;
        }
        .svg-error .line-2 {
            stroke: #ef4444;
            stroke-dasharray: 50;
            stroke-dashoffset: 50;
            animation: strokeCross 0.3s 0.6s ease-out forwards;
        }

        @keyframes strokeCircle { from { stroke-dashoffset: 220; } to { stroke-dashoffset: 0; } }
        @keyframes strokeCheck { from { stroke-dashoffset: 60; } to { stroke-dashoffset: 0; } }
        @keyframes strokeCross { from { stroke-dashoffset: 50; } to { stroke-dashoffset: 0; } }
    </style>
</head>
<body>
    <!-- Hộp Thoại Dialog SVG Stroke Draw -->
    <div id="svgDialogOverlay" class="svg-dialog-overlay">
        <div class="svg-dialog-card">
            <div class="mb-3" id="svgDialogIcon" style="display: flex; justify-content: center;"></div>
            <h3 class="fw-bold mb-2 text-dark" id="svgDialogTitle">Thông báo</h3>
            <div class="text-muted mb-4 small" id="svgDialogMessage"></div>
            <button type="button" class="btn-gradient" style="height: 44px;" id="svgAlertCloseBtn">
                <i class="fa-solid fa-check me-2"></i> Xác nhận
            </button>
        </div>
    </div>

    <div class="auth-card">
        <div class="text-center">
            <div class="brand-badge">
                <i class="fa-solid fa-rotate-left me-1"></i> Khôi phục tài khoản
            </div>
            <h1 class="auth-title">Quên mật khẩu?</h1>
            <p class="auth-subtitle">Nhập email đăng ký để nhận liên kết xác thực đổi mật khẩu</p>
        </div>

        <?php if ($successMessage): ?>
            <div class="p-3 mb-4 bg-success bg-opacity-10 border border-success border-opacity-25 rounded-3">
                <div class="text-success fw-bold small mb-1">
                    <i class="fa-solid fa-circle-check me-1"></i> <?= htmlspecialchars($successMessage) ?>
                </div>
                <?php if ($demoResetLink): ?>
                    <div class="mt-3 p-2 bg-white rounded border">
                        <div class="text-primary small fw-bold mb-2">
                            <i class="fa-solid fa-link me-1"></i> LIÊN KẾT ĐỔI MẬT KHẨU (DEMO TRỰC TIẾP):
                        </div>
                        <a href="<?= htmlspecialchars($demoResetLink) ?>" class="btn-gradient text-decoration-none" style="height: 40px; font-size: 0.9rem;">
                            <i class="fa-solid fa-key me-1"></i> Đổi Mật Khẩu Ngay
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form action="forgot-password.php" method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div>
                <label for="email" class="form-label">Email đã đăng ký</label>
                <div class="input-group-custom">
                    <i class="fa-solid fa-envelope input-icon"></i>
                    <input type="email" id="email" name="email" class="form-control-custom" 
                           placeholder="name@example.com" 
                           value="<?= htmlspecialchars($emailInput) ?>" required autofocus>
                </div>
            </div>

            <button type="submit" class="btn-gradient">
                <span>Gửi liên kết xác thực</span>
                <i class="fa-solid fa-paper-plane ms-1"></i>
            </button>
        </form>

        <div class="auth-footer">
            Nhớ lại mật khẩu rồi? <a href="login.php"><i class="fa-solid fa-arrow-left me-1"></i>Quay lại Đăng nhập</a>
        </div>
    </div>

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
            error: `
                <svg class="svg-draw-icon svg-error" viewBox="0 0 80 80">
                    <circle class="svg-circle" cx="40" cy="40" r="34" />
                    <line class="line-1" x1="26" y1="26" x2="54" y2="54" />
                    <line class="line-2" x1="54" y1="26" x2="26" y2="54" />
                </svg>
            `
        };

        function showSvgAlert(message, title = 'Thông Báo', type = 'success') {
            const overlay = document.getElementById('svgDialogOverlay');
            const iconEl = document.getElementById('svgDialogIcon');
            const titleEl = document.getElementById('svgDialogTitle');
            const messageEl = document.getElementById('svgDialogMessage');
            const closeBtn = document.getElementById('svgAlertCloseBtn');

            iconEl.innerHTML = SVG_TEMPLATES[type] || SVG_TEMPLATES.success;
            titleEl.textContent = title;
            messageEl.innerHTML = message;

            overlay.classList.add('active');

            closeBtn.onclick = () => {
                overlay.classList.remove('active');
            };
        }

        <?php if (!empty($successMessage)): ?>
            document.addEventListener('DOMContentLoaded', () => {
                showSvgAlert(<?= json_encode($successMessage) ?>, 'Đã Tạo Liên Kết', 'success');
            });
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            document.addEventListener('DOMContentLoaded', () => {
                const errorMsg = `<?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>`;
                showSvgAlert(errorMsg, 'Lỗi Yêu Cầu', 'error');
            });
        <?php endif; ?>
    </script>
</body>
</html>
