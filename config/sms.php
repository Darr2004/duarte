<?php
/**
 * DuaRTE — PhilSMS Gateway Configuration
 */

// PhilSMS API Endpoint & Credentials
if (!defined('PHILSMS_API_URL')) {
    define('PHILSMS_API_URL', getenv('PHILSMS_API_URL') ?: 'https://dashboard.philsms.com/api/v3/sms/send');
}
if (!defined('PHILSMS_API_TOKEN')) {
    define('PHILSMS_API_TOKEN', getenv('PHILSMS_API_TOKEN') ?: '');
}
if (!defined('PHILSMS_SENDER_ID')) {
    define('PHILSMS_SENDER_ID', getenv('PHILSMS_SENDER_ID') ?: 'PhilSMS');
}

// Default fallback mobile number for testing / demonstration
if (!defined('SMS_DEFAULT_RECIPIENT')) {
    define('SMS_DEFAULT_RECIPIENT', getenv('SMS_DEFAULT_RECIPIENT') ?: '09554737364');
}

// Enable or disable real SMS dispatch (can be toggled for staging/testing)
if (!defined('SMS_ENABLED')) {
    define('SMS_ENABLED', getenv('SMS_ENABLED') !== false ? (bool)getenv('SMS_ENABLED') : true);
}

// Verify SSL certificate by default (protect against MITM)
if (!defined('PHILSMS_VERIFY_SSL')) {
    define('PHILSMS_VERIFY_SSL', true);
}
