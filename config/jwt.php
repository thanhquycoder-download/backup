<?php
/**
 * ==========================================================
 * CẤU HÌNH & XỬ LÝ JSON WEB TOKEN (JWT)
 * File: config/jwt.php
 * Website: ThanhQuyTech
 * ==========================================================
 */

// Khóa bí mật dùng để ký và xác thực chữ ký JWT (HMAC-SHA256)
define('JWT_SECRET', 'ThanhQuyTech@2026#SuperSecretJWTKeyForAuthentication!*#99');
define('JWT_ALGO', 'HS256');
define('JWT_ISSUER', 'thanhquytech.local');

// Thời hạn hiệu lực của Token (mặc định: 15 ngày để duy trì đăng nhập)
define('JWT_EXPIRY_SECONDS', 86400 * 15);

/**
 * Mã hóa dữ liệu theo chuẩn Base64Url (an toàn cho URL và HTTP Header)
 *
 * @param string $data
 * @return string
 */
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Giải mã chuỗi Base64Url
 *
 * @param string $data
 * @return string|false
 */
function base64url_decode(string $data) {
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $padlen = 4 - $remainder;
        $data .= str_repeat('=', $padlen);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Tạo chuỗi JSON Web Token (JWT)
 *
 * @param array $payload Các thông tin định danh cần lưu trong Token (user_id, uid, uuid, role, ...)
 * @param int|null $expiresIn Số giây hết hạn (null = dùng mặc định JWT_EXPIRY_SECONDS)
 * @return string Chuỗi JWT dạng header.payload.signature
 */
function jwt_encode(array $payload, ?int $expiresIn = null): string {
    $issuedAt = time();
    $expire = $issuedAt + ($expiresIn ?? JWT_EXPIRY_SECONDS);

    // Header JWT
    $header = [
        'typ' => 'JWT',
        'alg' => JWT_ALGO
    ];

    // Gộp các claims chuẩn (Standard claims)
    $payload['iss'] = JWT_ISSUER;
    $payload['iat'] = $issuedAt;
    $payload['nbf'] = $issuedAt;
    $payload['exp'] = $expire;

    $headerEncoded  = base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $payloadEncoded = base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // Ký chữ ký số HMAC-SHA256
    $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true);
    $signatureEncoded = base64url_encode($signature);

    return "$headerEncoded.$payloadEncoded.$signatureEncoded";
}

/**
 * Giải mã và xác minh tính hợp lệ của JSON Web Token (JWT)
 *
 * @param string $jwt Chuỗi Token
 * @return array|null Trả về dữ liệu payload nếu Token hợp lệ và còn hạn, ngược lại trả về null
 */
function jwt_decode(string $jwt): ?array {
    if (empty($jwt)) {
        return null;
    }

    $parts = explode('.', trim($jwt));
    if (count($parts) !== 3) {
        return null;
    }

    [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

    // 1. Kiểm tra chữ ký (Signature Verification)
    $expectedSignature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true);
    $providedSignature = base64url_decode($signatureEncoded);

    if (!$providedSignature || !hash_equals($expectedSignature, $providedSignature)) {
        return null; // Chữ ký không hợp lệ (bị can thiệp hoặc sai secret key)
    }

    // 2. Giải mã Header & Payload
    $headerJson = base64url_decode($headerEncoded);
    $payloadJson = base64url_decode($payloadEncoded);

    if (!$headerJson || !$payloadJson) {
        return null;
    }

    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);

    if (!is_array($header) || !is_array($payload)) {
        return null;
    }

    // 3. Kiểm tra thuật toán
    if (($header['alg'] ?? '') !== JWT_ALGO) {
        return null;
    }

    // 4. Kiểm tra thời hạn hết hạn (Expiration - exp)
    $currentTime = time();
    if (isset($payload['exp']) && $payload['exp'] < $currentTime) {
        return null; // Token đã hết hạn
    }

    // 5. Kiểm tra thời điểm có hiệu lực (Not Before - nbf)
    if (isset($payload['nbf']) && $payload['nbf'] > ($currentTime + 60)) {
        return null;
    }

    return $payload;
}
