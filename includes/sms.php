<?php
/**
 * DuaRTE — SMS Dispatch Service (PhilSMS API v3)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/sms.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Normalizes a Philippine mobile number into PhilSMS's required international format:
 * e.g. "09554737364" -> "639554737364"
 *      "+639554737364" -> "639554737364"
 */
function format_ph_phone(?string $phone): ?string
{
    if (!$phone) return null;
    $digits = preg_replace('/[^\d]/', '', $phone);
    
    if (str_starts_with($digits, '09') && strlen($digits) === 11) {
        return '63' . substr($digits, 1);
    }
    if (str_starts_with($digits, '639') && strlen($digits) === 12) {
        return $digits;
    }
    if (str_starts_with($digits, '9') && strlen($digits) === 10) {
        return '63' . $digits;
    }
    
    return $digits ?: null;
}

/**
 * Send an SMS message via PhilSMS API v3.
 * Returns array with success boolean and status/error message.
 */
function send_sms(string $recipient, string $message, ?PDO $pdo = null): array
{
    if (!defined('SMS_ENABLED') || !SMS_ENABLED) {
        return ['success' => false, 'message' => 'SMS service is disabled in settings.'];
    }

    $formattedNumber = format_ph_phone($recipient) ?: format_ph_phone(SMS_DEFAULT_RECIPIENT);
    if (!$formattedNumber) {
        return ['success' => false, 'message' => 'Invalid phone number format.'];
    }

    $payload = [
        'recipient' => $formattedNumber,
        'sender_id' => defined('PHILSMS_SENDER_ID') ? PHILSMS_SENDER_ID : 'PhilSMS',
        'message'   => $message,
    ];

    $ch = curl_init(PHILSMS_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . PHILSMS_API_TOKEN,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => defined('PHILSMS_VERIFY_SSL') ? PHILSMS_VERIFY_SSL : true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $success = false;
    $statusMsg = '';

    if ($curlErr) {
        $statusMsg = 'cURL Error: ' . $curlErr;
    } else {
        $data = json_decode($response, true);
        if ($httpCode === 200 && isset($data['status']) && $data['status'] === 'success') {
            $success = true;
            $statusMsg = 'Delivered (' . ($data['data']['uid'] ?? '') . ')';
        } else {
            $statusMsg = $data['message'] ?? ('HTTP ' . $httpCode . ' error');
        }
    }

    // Record in sms_logs table for audit & tracking
    try {
        $db = $pdo ?? get_db();
        $stmt = $db->prepare(
            'INSERT INTO sms_logs (recipient, message, status, response_payload) VALUES (:to, :msg, :st, :res)'
        );
        $stmt->execute([
            'to'  => $formattedNumber,
            'msg' => $message,
            'st'  => $success ? 'delivered' : 'failed',
            'res' => $response ?: $curlErr,
        ]);
    } catch (Throwable $e) {
        // Log failure silently so main transaction never fails
        error_log('SMS Log DB error: ' . $e->getMessage());
    }

    return [
        'success'  => $success,
        'message'  => $statusMsg,
        'response' => $response,
    ];
}

/**
 * Send an SMS alert to a specific user by user_id.
 */
function notify_user_sms(int $user_id, string $message, ?PDO $pdo = null): bool
{
    try {
        $pdo = $pdo ?? get_db();
        $stmt = $pdo->prepare('SELECT contact_number FROM users WHERE id = :id AND status = "active" LIMIT 1');
        $stmt->execute(['id' => $user_id]);
        $row = $stmt->fetch();

        $phone = (!empty($row['contact_number'])) ? $row['contact_number'] : SMS_DEFAULT_RECIPIENT;
        if ($phone) {
            $res = send_sms($phone, $message, $pdo);
            return $res['success'];
        }
    } catch (Throwable $e) {
        error_log('notify_user_sms error: ' . $e->getMessage());
    }
    return false;
}

/**
 * Send an SMS alert to all active users with a specified role.
 */
function notify_role_sms(string $role, string $message, ?PDO $pdo = null): void
{
    try {
        $pdo = $pdo ?? get_db();
        $stmt = $pdo->prepare('SELECT id, contact_number FROM users WHERE role = :role AND status = "active"');
        $stmt->execute(['role' => $role]);
        $users = $stmt->fetchAll();

        // Keep track of recipients to avoid duplicate SMS to same phone
        $sentNumbers = [];

        foreach ($users as $u) {
            $phone = (!empty($u['contact_number'])) ? $u['contact_number'] : SMS_DEFAULT_RECIPIENT;
            $formatted = format_ph_phone($phone);
            if ($formatted && !isset($sentNumbers[$formatted])) {
                $sentNumbers[$formatted] = true;
                send_sms($phone, $message, $pdo);
            }
        }
    } catch (Throwable $e) {
        error_log('notify_role_sms error: ' . $e->getMessage());
    }
}
