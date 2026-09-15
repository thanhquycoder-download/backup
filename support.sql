-- ==========================================================
-- HỆ THỐNG CƠ SỞ DỮ LIỆU THANHQUYTECH - MODULE HỖ TRỢ (SUPPORT)
-- File: support.sql
-- Bảng: support_tickets & support_messages
-- Chuẩn hóa: UTF-8 Unicode, InnoDB, Liên kết UUIDv7
-- ==========================================================

USE `thanhquytech_db`;

-- Vô hiệu hóa kiểm tra khóa ngoại tạm thời để tránh xung đột
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------
-- 1. Bảng: support_tickets (Quản lý các phiếu yêu cầu hỗ trợ)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `support_tickets` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'Khóa chính tự tăng',
    `user_uuid` CHAR(36) NOT NULL COMMENT 'Người gửi yêu cầu (Liên kết users.uuid)',
    `ticket_code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Mã phiếu hỗ trợ duy nhất (VD: #TK-2609-1234)',
    `subject` VARCHAR(255) NOT NULL COMMENT 'Tiêu đề vấn đề cần trợ giúp',
    `category` ENUM('Billing', 'LicenseKey', 'CloudServer', 'GolikeTool', 'Account', 'Other') NOT NULL DEFAULT 'Other' COMMENT 'Danh mục hỗ trợ',
    `priority` ENUM('Low', 'Medium', 'High', 'Urgent') NOT NULL DEFAULT 'Medium' COMMENT 'Mức độ ưu tiên',
    `status` ENUM('Pending', 'In Progress', 'Answered', 'Closed') NOT NULL DEFAULT 'Pending' COMMENT 'Trạng thái xử lý ticket',
    `order_code` VARCHAR(50) DEFAULT NULL COMMENT 'Mã đơn hàng hoặc mã giao dịch liên quan nếu có',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo yêu cầu',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật gần nhất',
    
    INDEX `idx_st_user_uuid` (`user_uuid`),
    INDEX `idx_st_ticket_code` (`ticket_code`),
    INDEX `idx_st_status` (`status`),
    INDEX `idx_st_category` (`category`),
    INDEX `idx_st_priority` (`priority`),
    INDEX `idx_st_created_at` (`created_at`),
    CONSTRAINT `fk_support_tickets_user_uuid`
        FOREIGN KEY (`user_uuid`) REFERENCES `users` (`uuid`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 2. Bảng: support_messages (Nội dung trao đổi & phản hồi ticket)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `support_messages` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'Khóa chính tự tăng',
    `ticket_id` BIGINT UNSIGNED NOT NULL COMMENT 'Liên kết tới support_tickets.id',
    `sender_uuid` CHAR(36) NOT NULL COMMENT 'Người gửi tin nhắn (users.uuid)',
    `sender_role` ENUM('Member', 'Admin', 'Support') NOT NULL DEFAULT 'Member' COMMENT 'Vai trò người gửi phản hồi',
    `message` TEXT NOT NULL COMMENT 'Nội dung trao đổi, tư vấn',
    `attachment` VARCHAR(255) DEFAULT NULL COMMENT 'Đường dẫn ảnh chụp màn hình lỗi / chứng từ',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm gửi tin nhắn',
    
    INDEX `idx_sm_ticket_id` (`ticket_id`),
    INDEX `idx_sm_sender_uuid` (`sender_uuid`),
    INDEX `idx_sm_created_at` (`created_at`),
    CONSTRAINT `fk_support_messages_ticket_id`
        FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_support_messages_sender_uuid`
        FOREIGN KEY (`sender_uuid`) REFERENCES `users` (`uuid`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------
-- 3. Dữ liệu mẫu (Seed Data)
-- ----------------------------------------------------------
INSERT INTO `support_tickets` (`id`, `user_uuid`, `ticket_code`, `subject`, `category`, `priority`, `status`, `order_code`, `created_at`) VALUES
(1, '0191eb50-0002-7000-8000-000000000002', 'TK-2609-7812', 'Hỗ trợ nạp tiền chưa cộng số dư tự động', 'Billing', 'High', 'Answered', 'NAP6839204-01', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(2, '0191eb50-0003-7000-8000-000000000003', 'TK-2609-4159', 'Key Golike báo lỗi kích hoạt trên máy chủ phụ', 'LicenseKey', 'Urgent', 'In Progress', 'TQ-REF-VIP-8921-HN03', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(3, '0191eb50-0004-7000-8000-000000000004', 'TK-2609-9023', 'Tư vấn cấu hình máy chủ Cloud chạy đa luồng', 'CloudServer', 'Medium', 'Closed', NULL, DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE `subject` = VALUES(`subject`), `status` = VALUES(`status`);

INSERT INTO `support_messages` (`id`, `ticket_id`, `sender_uuid`, `sender_role`, `message`, `created_at`) VALUES
(1, 1, '0191eb50-0002-7000-8000-000000000002', 'Member', 'Chào ban quản trị, mình vừa quét mã QR nạp 2.000.000đ từ app ngân hàng nhưng sau 5 phút hệ thống chưa cộng số dư. Nhờ ad kiểm tra giúp với mã giao dịch NAP6839204-01.', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(2, 1, '0191eb50-0001-7000-8000-000000000001', 'Admin', 'Chào bạn Thanh Quý, hệ thống đã kiểm tra và đối soát giao dịch ngân hàng thành công. Số dư 2.000.000đ đã được cộng vào tài khoản của bạn. Chúc bạn làm việc hiệu quả!', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(3, 2, '0191eb50-0003-7000-8000-000000000003', 'Member', 'Admin kiểm tra giúp mình mã key vừa nhận từ quà giới thiệu bạn bè, khi nhập vào tool Golike thì báo mã không tìm thấy trên server.', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(4, 2, '0191eb50-0001-7000-8000-000000000001', 'Admin', 'Kỹ thuật viên đang đồng bộ lại cache máy chủ bản quyền, bạn vui lòng đợi trong 5 phút rồi thử lại nhé.', DATE_SUB(NOW(), INTERVAL 20 HOUR)),
(5, 3, '0191eb50-0004-7000-8000-000000000004', 'Member', 'Mình muốn thuê gói cloud treo 100 nick Golike cùng lúc thì nên chọn cấu hình nào tối ưu nhất ạ?', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(6, 3, '0191eb50-0001-7000-8000-000000000001', 'Admin', 'Chào bạn, với 100 luồng Golike bạn nên chọn gói Cloud Pro (4 vCPU, 8GB RAM) tại mục Thuê Cloud để chạy ổn định 24/7 mượt mà không bị nghẽn CPU nhé.', DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE `message` = VALUES(`message`);
