<?php

use App\Support\PaymentWindow;

return [
    'enabled' => (bool) env('XENDIT_ENABLED', false),
    'base_url' => rtrim((string) env('XENDIT_BASE_URL', 'https://api.xendit.co'), '/'),
    'secret_key' => env('XENDIT_SECRET_KEY'),
    'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),
    'country' => env('XENDIT_COUNTRY', 'ID'),
    'currency' => env('XENDIT_CURRENCY', 'IDR'),
    'success_url' => env('XENDIT_SUCCESS_URL', rtrim((string) env('FRONTEND_URL'), '/').'/payment?gateway=success'),
    'cancel_url' => env('XENDIT_CANCEL_URL', rtrim((string) env('FRONTEND_URL'), '/').'/payment?gateway=cancelled'),
    'session_ttl_minutes' => PaymentWindow::MINUTES,
    'payout_api_version' => env('XENDIT_PAYOUT_API_VERSION', '2025-09-01'),
    // Payments API v3 refunds only accept this single api-version value.
    'refund_api_version' => env('XENDIT_REFUND_API_VERSION', '2024-11-11'),
];
