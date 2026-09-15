<?php
/**
 * ==========================================================
 * TRANG ĐẶT LẠI MẬT KHẨU (RESET PASSWORD) - NỀN TRẮNG ĐỘC LẬP
 * File: reset-password.php
 * Website: ThanhQuyTech
 * ==========================================================
 */

require_once __DIR__ . '/config/config.php';

require_guest();

$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$errors = [];
$isValidToken = false;
$resetRecord = null;

if (empty($token)) {
    $errors[] = 'Mã xác thực đặt lại mật khẩu không được để trống.';
} else {
    $stmt = $pdo->prepare("
        SELECT * FROM password_resets 
        WHERE token = ? AND expires_at > NOW() 
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $resetRecord = $stmt->fetch();

    if (!$resetRecord) {
        $errors[] = 'Mã xác thực không hợp lệ hoặc đã hết hạn (30 phút). Vui lòng yêu cầu lại.';
    } else {
        $isValidToken = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isValidToken) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Yêu cầu không hợp lệ hoặc phiên làm việc đã hết hạn.';
    }

    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 6) {
        $errors[] = 'Mật khẩu mới phải có ít nhất 6 ký tự.';
    }

    if ($newPassword !== $confirmPassword) {
        $errors[] = 'Mật khẩu xác nhận không khớp.';
    }

    if (empty($errors)) {
        try {
            $hashedPassword = hash_password_stretched($newPassword);

            // Cập nhật mật khẩu bảo mật qua liên kết uuid của bảng users
            $userUuid = $resetRecord['user_uuid'] ?? '';
            $stmtUpdate = $pdo->prepare("UPDATE users SET password = ? WHERE uuid = ? OR email = ?");
            $stmtUpdate->execute([$hashedPassword, $userUuid, $resetRecord['email']]);

            // Xóa mã đặt lại đã sử dụng
            $stmtDel = $pdo->prepare("DELETE FROM password_resets WHERE user_uuid = ? OR email = ?");
            $stmtDel->execute([$userUuid, $resetRecord['email']]);

            set_flash('success', 'Mật khẩu của bạn đã được cập nhật thành công! Hãy đăng nhập ngay.', 'Đổi Mật Khẩu Thành Công');
            header("Location: login.php");
            exit;
        } catch (Exception $e) {
            $errors[] = 'Có lỗi xảy ra khi cập nhật mật khẩu: ' . $e->getMessage();
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
    <title>Đặt lại mật khẩu - <?= htmlspecialchars(APP_NAME) ?></title>

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
            padding: 0 42px 0 44px;
            font-size: 0.94rem;
            color: var(--text-heading);
            outline: none;
            transition: var(--transition);
        }

        .form-control-custom:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.12);
        }

        .toggle-pwd-btn {
            position: absolute;
            right: 14px;
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 1rem;
            padding: 6px;
            z-index: 2;
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
                <i class="fa-solid fa-key me-1"></i> Cập nhật mật khẩu
            </div>
            <h1 class="auth-title">Đặt mật khẩu mới</h1>
            <p class="auth-subtitle">
                <?= $isValidToken ? 'Nhập mật khẩu mới cho: <strong>' . htmlspecialchars($resetRecord['email']) . '</strong>' : 'Xác thực đường dẫn khôi phục mật khẩu' ?>
            </p>
        </div>

        <?php if ($isValidToken): ?>
            <form action="reset-password.php" method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                <div>
                    <label for="new_password" class="form-label">Mật khẩu mới</label>
                    <div class="input-group-custom">
                        <i class="fa-solid fa-lock input-icon"></i>
                        <input type="password" id="new_password" name="new_password" class="form-control-custom" 
                               placeholder="Tối thiểu 6 ký tự" required autofocus>
                        <button type="button" class="toggle-pwd-btn" onclick="togglePasswordVisibility('new_password', this)">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div>
                    <label for="confirm_password" class="form-label">Xác nhận mật khẩu mới</label>
                    <div class="input-group-custom">
                        <i class="fa-solid fa-key input-icon"></i>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control-custom" 
                               placeholder="Nhập lại mật khẩu mới" required>
                        <button type="button" class="toggle-pwd-btn" onclick="togglePasswordVisibility('confirm_password', this)">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-gradient">
                    <span>Lưu Mật Khẩu Mới</span>
                    <i class="fa-solid fa-floppy-disk ms-1"></i>
                </button>
            </form>
        <?php else: ?>
            <div class="text-center mt-3">
                <a href="forgot-password.php" class="btn btn-outline-secondary w-100 py-2">
                    <i class="fa-solid fa-rotate-left me-1"></i> Yêu cầu cấp lại liên kết khác
                </a>
            </div>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="login.php"><i class="fa-solid fa-arrow-left me-1"></i>Quay lại trang Đăng nhập</a>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const SVG_TEMPLATES = {
            error: `
                <svg class="svg-draw-icon svg-error" viewBox="0 0 80 80">
                    <circle class="svg-circle" cx="40" cy="40" r="34" />
                    <line class="line-1" x1="26" y1="26" x2="54" y2="54" />
                    <line class="line-2" x1="54" y1="26" x2="26" y2="54" />
                </svg>
            `
        };

        function showSvgAlert(message, title = 'Thông Báo') {
            const overlay = document.getElementById('svgDialogOverlay');
            const iconEl = document.getElementById('svgDialogIcon');
            const titleEl = document.getElementById('svgDialogTitle');
            const messageEl = document.getElementById('svgDialogMessage');
            const closeBtn = document.getElementById('svgAlertCloseBtn');

            iconEl.innerHTML = SVG_TEMPLATES.error;
            titleEl.textContent = title;
            messageEl.innerHTML = message;

            overlay.classList.add('active');

            closeBtn.onclick = () => {
                overlay.classList.remove('active');
            };
        }

        function togglePasswordVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fa-regular fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fa-regular fa-eye';
            }
        }

        <?php if (!empty($errors)): ?>
            document.addEventListener('DOMContentLoaded', () => {
                const errorMsg = `<?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>`;
                showSvgAlert(errorMsg, 'Xác Thực Thất Bại');
            });
        <?php endif; ?>
    </script>
</body>
</html>
