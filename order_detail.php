<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$guardCandidates = [
    __DIR__ . '/_guard.php',
    dirname(__DIR__) . '/member/_guard.php',
];
foreach ($guardCandidates as $guardFile) {
    if (is_file($guardFile)) {
        require_once $guardFile;
    }
}

$requiredHelper = __DIR__ . '/includes/order_request_actions.php';
$helperLoaded = false;
if (!is_file($requiredHelper)) {
    http_response_code(500);
    echo 'Order helper unavailable.';
    exit;
}
require_once $requiredHelper;
$helperLoaded = true;

$debugLog = static function (array $payload): void {
    $line = '[' . gmdate('Y-m-d H:i:s') . " UTC] seller_order_detail " . json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $logCandidates = [
        '/private_html/seller_order_detail_debug.log',
        '/public_html/seller_order_detail_debug.log',
        dirname(__DIR__) . '/private_html/seller_order_detail_debug.log',
        dirname(__DIR__) . '/public_html/seller_order_detail_debug.log',
        __DIR__ . '/seller_order_detail_debug.log',
    ];

    foreach ($logCandidates as $logFile) {
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            continue;
        }
        if (is_file($logFile) || is_writable($logDir)) {
            @file_put_contents($logFile, $line, FILE_APPEND);
            return;
        }
    }
};

$optionalHelpers = [
    __DIR__ . '/includes/cancel_refund_summary.php',
    dirname(__DIR__) . '/includes/order_cancel.php',
    dirname(__DIR__) . '/includes/order_refund.php',
    dirname(__DIR__) . '/order_cancel.php',
    dirname(__DIR__) . '/order_refund.php',
];
foreach ($optionalHelpers as $optionalFile) {
    if (is_file($optionalFile)) {
        require_once $optionalFile;
    }
}

if (!function_exists('seller_order_request_current_user_id')) {
    http_response_code(403);
    echo 'Seller authentication unavailable.';
    exit;
}

$orderId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
$sellerUserId = (int)seller_order_request_current_user_id();

if ($orderId <= 0 || $sellerUserId <= 0) {
    http_response_code(404);
    echo 'Order not found.';
    exit;
}

$ownershipPassed = null;
if (function_exists('seller_order_request_order_belongs_to_seller')) {
    try {
        $ownershipPassed = (bool)seller_order_request_order_belongs_to_seller($orderId, $sellerUserId);
        if (!$ownershipPassed) {
            $debugLog([
                'order_id' => $orderId,
                'seller_user_id' => $sellerUserId,
                'helper_loaded' => $helperLoaded,
                'context_loaded' => false,
                'ownership_passed' => false,
                'exception' => '',
            ]);
            http_response_code(404);
            echo 'Order not found.';
            exit;
        }
    } catch (Throwable $e) {
        $debugLog([
            'order_id' => $orderId,
            'seller_user_id' => $sellerUserId,
            'helper_loaded' => $helperLoaded,
            'context_loaded' => false,
            'ownership_passed' => false,
            'exception' => $e->getMessage(),
        ]);
        http_response_code(404);
        echo 'Order not found.';
        exit;
    }
}

if (!isset($_SESSION['seller_order_request_csrf_token']) || !is_string($_SESSION['seller_order_request_csrf_token']) || $_SESSION['seller_order_request_csrf_token'] === '') {
    try {
        $_SESSION['seller_order_request_csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['seller_order_request_csrf_token'] = sha1((string)microtime(true) . '-' . (string)mt_rand());
    }
}
$csrfToken = (string)$_SESSION['seller_order_request_csrf_token'];

$orderContext = null;
$bundle = [
    'order' => null,
    'cancel' => null,
    'refund' => null,
    'primary_type' => '',
    'seller_can_approve_cancel' => false,
    'seller_can_reject_cancel' => false,
    'seller_can_approve_refund' => false,
    'seller_can_reject_refund' => false,
];

$loadExceptionMessage = '';
try {
    if (function_exists('seller_order_request_get_order_context')) {
        $orderContext = seller_order_request_get_order_context($orderId, $sellerUserId);
    }
    if (function_exists('seller_order_request_get_request_bundle')) {
        $loaded = seller_order_request_get_request_bundle($orderId, $sellerUserId);
        if (is_array($loaded)) {
            $bundle = array_merge($bundle, $loaded);
        }
    } else {
        if (function_exists('seller_order_request_get_cancel_by_order_id')) {
            $bundle['cancel'] = seller_order_request_get_cancel_by_order_id($orderId, $sellerUserId);
        }
        if (function_exists('seller_order_request_get_refund_by_order_id')) {
            $bundle['refund'] = seller_order_request_get_refund_by_order_id($orderId, $sellerUserId);
        }
    }
} catch (Throwable $e) {
    $loadExceptionMessage = $e->getMessage();
}

if (!$orderContext && is_array($bundle['order'] ?? null)) {
    $orderContext = $bundle['order'];
}

if (!$orderContext || !is_array($orderContext)) {
    $debugLog([
        'order_id' => $orderId,
        'seller_user_id' => $sellerUserId,
        'helper_loaded' => $helperLoaded,
        'context_loaded' => false,
        'ownership_passed' => $ownershipPassed,
        'exception' => $loadExceptionMessage,
    ]);
    http_response_code(404);
    echo 'Order not found.';
    exit;
}

$debugLog([
    'order_id' => $orderId,
    'seller_user_id' => $sellerUserId,
    'helper_loaded' => $helperLoaded,
    'context_loaded' => true,
    'ownership_passed' => $ownershipPassed,
    'exception' => $loadExceptionMessage,
]);

$cancelRow = is_array($bundle['cancel'] ?? null) ? $bundle['cancel'] : null;
$refundRow = is_array($bundle['refund'] ?? null) ? $bundle['refund'] : null;

if (($bundle['primary_type'] ?? '') === '' && function_exists('seller_order_request_detect_primary_type')) {
    $bundle['primary_type'] = seller_order_request_detect_primary_type($cancelRow, $refundRow);
}
$primaryType = (string)($bundle['primary_type'] ?? '');

$canApproveCancel = !empty($bundle['seller_can_approve_cancel']);
$canRejectCancel = !empty($bundle['seller_can_reject_cancel']);
$canApproveRefund = !empty($bundle['seller_can_approve_refund']);
$canRejectRefund = !empty($bundle['seller_can_reject_refund']);

$flashSuccess = isset($_SESSION['seller_order_request_success']) ? trim((string)$_SESSION['seller_order_request_success']) : '';
$flashError = isset($_SESSION['seller_order_request_error']) ? trim((string)$_SESSION['seller_order_request_error']) : '';
unset($_SESSION['seller_order_request_success'], $_SESSION['seller_order_request_error']);

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$money = static function ($amount, ?string $currency = null): string {
    if (function_exists('seller_order_request_money')) {
        return seller_order_request_money($amount, $currency);
    }
    if (!is_numeric($amount)) {
        return '—';
    }
    $currency = strtoupper(trim((string)$currency));
    if ($currency === '') {
        $currency = 'USD';
    }
    return number_format((float)$amount, 2) . ' ' . $currency;
};

$statusBadge = static function (string $type, string $status): array {
    if (function_exists('seller_order_request_status_badge')) {
        return seller_order_request_status_badge($type, $status);
    }
    $label = $status !== '' ? ucfirst(str_replace('_', ' ', $status)) : 'Unknown';
    return ['label' => $label, 'class' => 'badge-default'];
};

$paymentBadge = static function (string $status): array {
    $key = strtolower(trim($status));
    $map = [
        'paid' => ['Paid', 'offer-thread-badge-completed'],
        'completed' => ['Completed', 'offer-thread-badge-completed'],
        'authorized' => ['Authorized', 'offer-thread-badge-ready'],
        'pending' => ['Pending', 'offer-thread-badge-open'],
        'unpaid' => ['Unpaid', 'offer-thread-badge-needs-reply'],
        'failed' => ['Failed', 'offer-thread-badge-needs-reply'],
        'refunded' => ['Refunded', 'offer-thread-badge-ready'],
        'partially_refunded' => ['Partially Refunded', 'offer-thread-badge-open'],
    ];
    if (isset($map[$key])) {
        return ['label' => $map[$key][0], 'class' => $map[$key][1]];
    }
    return ['label' => $key !== '' ? ucfirst(str_replace('_', ' ', $key)) : 'Unknown', 'class' => 'badge-default'];
};

$deriveShippingStatus = static function (array $ctx): string {
    $candidates = [
        'shipping_status',
        'delivery_status',
        'tracking_status',
        'fulfillment_status',
        'order_shipping_status',
    ];
    foreach ($candidates as $key) {
        if (isset($ctx[$key]) && trim((string)$ctx[$key]) !== '') {
            return strtolower(trim((string)$ctx[$key]));
        }
    }

    $orderStatus = strtolower(trim((string)($ctx['order_status'] ?? '')));
    $derivedMap = [
        'paid' => 'to_ship',
        'confirmed' => 'to_ship',
        'ready_to_ship' => 'to_ship',
        'packed' => 'processing',
        'processing' => 'processing',
        'shipped' => 'shipped',
        'in_transit' => 'shipped',
        'out_for_delivery' => 'shipped',
        'delivered' => 'delivered',
        'completed' => 'delivered',
        'cancelled' => 'cancelled',
        'refunded' => 'cancelled',
    ];
    if (isset($derivedMap[$orderStatus])) {
        return $derivedMap[$orderStatus];
    }
    return 'pending';
};

$shippingBadge = static function (string $status): array {
    $key = strtolower(trim($status));
    $map = [
        'to_ship' => ['To Ship', 'offer-thread-badge-ready'],
        'pending' => ['Pending', 'offer-thread-badge-open'],
        'processing' => ['Processing', 'offer-thread-badge-ready'],
        'ready_to_ship' => ['Ready to Ship', 'offer-thread-badge-ready'],
        'shipped' => ['Shipped', 'offer-thread-badge-completed'],
        'in_transit' => ['In Transit', 'offer-thread-badge-completed'],
        'out_for_delivery' => ['Out for Delivery', 'offer-thread-badge-completed'],
        'delivered' => ['Delivered', 'offer-thread-badge-completed'],
        'cancelled' => ['Closed', 'offer-thread-badge-needs-reply'],
        'refunded' => ['Closed', 'offer-thread-badge-needs-reply'],
        'closed' => ['Closed', 'offer-thread-badge-needs-reply'],
        'returned' => ['Returned', 'offer-thread-badge-needs-reply'],
    ]; 
    if (isset($map[$key])) {
        return ['label' => $map[$key][0], 'class' => $map[$key][1]];
    }
    return ['label' => $key !== '' ? ucfirst(str_replace('_', ' ', $key)) : 'Unknown', 'class' => 'badge-default'];
};

$pickDate = static function (?array $row): string {
    if (!$row) {
        return '';
    }
    foreach (['requested_at', 'created_at', 'updated_at'] as $k) {
        if (!empty($row[$k])) {
            return (string)$row[$k];
        }
    }
    return '';
};

$pickValue = static function (?array $row, array $keys): string {
    if (!$row) {
        return '';
    }
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }
    return '';
};

$currency = (string)($orderContext['currency'] ?? 'USD');
$orderCode = trim((string)($orderContext['order_code'] ?? ''));
$paymentStatus = trim((string)($orderContext['payment_status'] ?? ''));
$buyerName = trim((string)($orderContext['buyer_name'] ?? ''));
$listingTitle = trim((string)($orderContext['listing_title'] ?? ''));
$shippingStatus = $deriveShippingStatus($orderContext);
$paymentBadgeUi = $paymentBadge($paymentStatus);
$shippingBadgeUi = $shippingBadge($shippingStatus);

$sellerItemsSubtotal = null;
if (isset($orderContext['items']) && is_array($orderContext['items'])) {
    $subtotal = 0.0;
    $hasRows = false;
    foreach ($orderContext['items'] as $itemRow) {
        if (!is_array($itemRow)) {
            continue;
        }
        $sellerKeyFound = false;
        $belongsToSeller = true;
        foreach (['seller_user_id', 'seller_id', 'owner_user_id', 'user_id'] as $sellerKey) {
            if (!array_key_exists($sellerKey, $itemRow)) {
                continue;
            }
            $sellerValue = $itemRow[$sellerKey];
            if (is_numeric($sellerValue)) {
                $sellerKeyFound = true;
                $belongsToSeller = ((int)$sellerValue === $sellerUserId);
                break;
            }
        }
        if ($sellerKeyFound && !$belongsToSeller) {
            continue;
        }

        $lineTotal = null;
        foreach (['line_total', 'subtotal', 'item_total', 'total'] as $lineKey) {
            if (isset($itemRow[$lineKey]) && is_numeric($itemRow[$lineKey])) {
                $lineTotal = (float)$itemRow[$lineKey];
                break;
            }
        }
        if ($lineTotal === null) {
            $unitPrice = null;
            if (isset($itemRow['unit_price']) && is_numeric($itemRow['unit_price'])) {
                $unitPrice = (float)$itemRow['unit_price'];
            } elseif (isset($itemRow['price']) && is_numeric($itemRow['price'])) {
                $unitPrice = (float)$itemRow['price'];
            }

            $qty = null;
            if (isset($itemRow['quantity']) && is_numeric($itemRow['quantity'])) {
                $qty = (float)$itemRow['quantity'];
            } elseif (isset($itemRow['qty']) && is_numeric($itemRow['qty'])) {
                $qty = (float)$itemRow['qty'];
            }

            if ($unitPrice !== null && $qty !== null) {
                $lineTotal = $unitPrice * $qty;
            }
        }
        if ($lineTotal !== null) {
            $subtotal += $lineTotal;
            $hasRows = true;
        }
    }
    if ($hasRows) {
        $sellerItemsSubtotal = $subtotal;
    }
}

$requestActionEndpoint = '/seller/order_request_action.php';
$requestActionEndpointExists = false;
$requestActionCandidates = [
    __DIR__ . '/order_request_action.php',
    __DIR__ . '/includes/order_request_action.php',
    dirname(__DIR__) . '/seller/order_request_action.php',
    dirname(__DIR__) . '/order_request_action.php',
];
foreach ($requestActionCandidates as $candidatePath) {
    if (is_file($candidatePath)) {
        $requestActionEndpointExists = true;
        break;
    }
}

$fulfillmentActionEndpoint = '/seller/order_action.php';
$fulfillmentActionEndpointExists = false;
$fulfillmentActionCandidates = [
    __DIR__ . '/order_action.php',
    __DIR__ . '/includes/order_action.php',
    dirname(__DIR__) . '/seller/order_action.php',
    dirname(__DIR__) . '/order_action.php',
];
foreach ($fulfillmentActionCandidates as $candidatePath) {
    if (is_file($candidatePath)) {
        $fulfillmentActionEndpointExists = true;
        break;
    }
}

$returnUrl = '/seller/apply.php';
if (function_exists('seller_order_request_best_return_url')) {
    try {
        $candidate = (string)seller_order_request_best_return_url($orderId);
        if ($candidate !== '') {
            $returnUrl = $candidate;
        }
    } catch (Throwable $e) {
        $returnUrl = '/seller/apply.php';
    }
}

if ($returnUrl === '' || preg_match('~^https?://~i', $returnUrl)) {
    $returnUrl = '/seller/apply.php';
}
if ($returnUrl[0] !== '/') {
    $returnUrl = '/' . ltrim($returnUrl, '/');
}

$hasAnyRequest = $cancelRow !== null || $refundRow !== null;
$currentRow = $primaryType === 'cancel' ? $cancelRow : ($primaryType === 'refund' ? $refundRow : ($refundRow ?: $cancelRow));
$currentType = $primaryType !== '' ? $primaryType : ($refundRow ? 'refund' : ($cancelRow ? 'cancel' : 'none'));
$currentStatus = strtolower(trim((string)($currentRow['status'] ?? '')));
$currentDate = $pickDate($currentRow);
$currentReason = $pickValue($currentRow, ['cancel_reason_text', 'reason_text', 'reason', 'note', 'admin_note']);
$currentAmount = $pickValue($currentRow, ['approved_refund_amount', 'requested_refund_amount', 'actual_refunded_amount', 'refundable_amount', 'amount']);
$currentRefundMode = $pickValue($currentRow, ['refund_mode']);
$currentRefundRef = $pickValue($currentRow, ['payment_reference_snapshot', 'payment_reference', 'refund_reference', 'reference']);

$cancelBadge = $statusBadge('cancel', strtolower(trim((string)($cancelRow['status'] ?? ''))));
$refundBadge = $statusBadge('refund', strtolower(trim((string)($refundRow['status'] ?? ''))));

$orderStatus = strtolower(trim((string)($orderContext['order_status'] ?? '')));
$paymentStatusKey = strtolower(trim((string)$paymentStatus));
$shippingStatusKey = strtolower(trim((string)$shippingStatus));
$fulfillmentActions = [];
if (in_array($paymentStatusKey, ['paid', 'authorized'], true) && in_array($orderStatus, ['paid', 'confirmed'], true)) {
    $fulfillmentActions[] = ['label' => 'Mark Processing', 'value' => 'mark_processing', 'class' => 'btn-approve'];
}
if (
    $orderStatus === 'processing'
    || $shippingStatusKey === 'to_ship'
    || $shippingStatusKey === 'processing'
) {
    $fulfillmentActions[] = ['label' => 'Mark Shipped', 'value' => 'mark_shipped', 'class' => 'btn-approve'];
}
if ($orderStatus === 'shipped' || $shippingStatusKey === 'shipped') {
    $fulfillmentActions[] = ['label' => 'Mark Completed', 'value' => 'mark_completed', 'class' => 'btn-approve'];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seller Order Detail</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0b1020;
            --panel: #121a31;
            --panel-soft: #16203b;
            --text: #e7ecff;
            --muted: #95a1c7;
            --line: #2a3558;
            --ok: #2ecc71;
            --warn: #f39c12;
            --danger: #e74c3c;
            --accent: #7f8cff;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            background: radial-gradient(1200px 700px at 20% -10%, #1a2550 0%, var(--bg) 60%);
            color: var(--text);
        }
        .wrap {
            max-width: 1100px;
            margin: 28px auto;
            padding: 0 16px 28px;
        }
        .top-nav {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .btn-link {
            display: inline-block;
            padding: 10px 14px;
            border-radius: 10px;
            text-decoration: none;
            border: 1px solid var(--line);
            color: var(--text);
            background: var(--panel);
            font-weight: 600;
        }
        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 16px;
        }
        h1, h2, h3 {
            margin: 0 0 10px;
            line-height: 1.25;
        }
        h1 { font-size: 26px; }
        h2 { font-size: 19px; }
        h3 { font-size: 16px; color: #d4dcff; }
        .meta-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }
        .meta-item {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
        }
        .meta-item .k {
            display: block;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .meta-item .v {
            font-size: 15px;
            font-weight: 600;
            word-break: break-word;
        }
        .flash {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 12px;
            font-weight: 600;
        }
        .flash.success {
            background: rgba(46, 204, 113, 0.15);
            border: 1px solid rgba(46, 204, 113, 0.5);
            color: #baffd4;
        }
        .flash.error {
            background: rgba(231, 76, 60, 0.15);
            border: 1px solid rgba(231, 76, 60, 0.5);
            color: #ffd0cc;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid transparent;
            vertical-align: middle;
        }
        .offer-thread-badge-open { background: rgba(243, 156, 18, 0.2); border-color: rgba(243, 156, 18, 0.5); color: #ffd89a; }
        .offer-thread-badge-ready { background: rgba(127, 140, 255, 0.18); border-color: rgba(127, 140, 255, 0.5); color: #d8ddff; }
        .offer-thread-badge-completed { background: rgba(46, 204, 113, 0.18); border-color: rgba(46, 204, 113, 0.45); color: #c9ffdf; }
        .offer-thread-badge-needs-reply { background: rgba(231, 76, 60, 0.18); border-color: rgba(231, 76, 60, 0.45); color: #ffd2cd; }
        .badge-default { background: rgba(149, 161, 199, 0.18); border-color: rgba(149, 161, 199, 0.45); color: #dce4ff; }
        .stack { display: grid; gap: 10px; }
        .muted { color: var(--muted); }
        .empty {
            border: 1px dashed var(--line);
            border-radius: 12px;
            padding: 16px;
            color: var(--muted);
            background: rgba(255, 255, 255, 0.02);
        }
        .actions {
            display: grid;
            gap: 12px;
            grid-template-columns: 1fr;
        }
        textarea {
            width: 100%;
            min-height: 110px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #0e152c;
            color: var(--text);
            padding: 12px;
            font-size: 14px;
            resize: vertical;
        }
        .action-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        button {
            appearance: none;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 11px 14px;
            font-weight: 700;
            color: var(--text);
            cursor: pointer;
            background: var(--panel-soft);
        }
        .btn-approve { border-color: rgba(46, 204, 113, 0.5); background: rgba(46, 204, 113, 0.16); }
        .btn-reject { border-color: rgba(231, 76, 60, 0.5); background: rgba(231, 76, 60, 0.16); }
        .split {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top-nav">
        <a class="btn-link" href="<?= $h($returnUrl) ?>">← Back</a>
        <a class="btn-link" href="/seller/apply.php">Seller Dashboard</a>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div class="flash success"><?= $h($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="flash error"><?= $h($flashError) ?></div>
    <?php endif; ?>

    <section class="card">
        <h1>Seller Order Detail</h1>
        <div class="meta-grid">
            <div class="meta-item"><span class="k">Order Code</span><span class="v"><?= $h($orderCode !== '' ? $orderCode : ('#' . $orderId)) ?></span></div>
            <div class="meta-item"><span class="k">Payment Status</span><span class="v"><span class="badge <?= $h((string)($paymentBadgeUi['class'] ?? 'badge-default')) ?>"><?= $h((string)($paymentBadgeUi['label'] ?? 'Unknown')) ?></span></span></div>
            <div class="meta-item"><span class="k">Shipping Status</span><span class="v"><span class="badge <?= $h((string)($shippingBadgeUi['class'] ?? 'badge-default')) ?>"><?= $h((string)($shippingBadgeUi['label'] ?? 'Unknown')) ?></span></span></div>
            <div class="meta-item"><span class="k">Buyer</span><span class="v"><?= $h($buyerName !== '' ? $buyerName : 'Unknown Buyer') ?></span></div>
            <div class="meta-item"><span class="k">Listing</span><span class="v"><?= $h($listingTitle !== '' ? $listingTitle : 'Listing unavailable') ?></span></div>
            <div class="meta-item"><span class="k">Seller Items Subtotal</span><span class="v"><?= $h($sellerItemsSubtotal !== null ? $money($sellerItemsSubtotal, $currency) : '—') ?></span></div>
        </div>
    </section>

    <section class="card stack">
        <h2>Request Status</h2>
        <?php if (!$hasAnyRequest): ?>
            <div class="empty">No cancel or refund request for this order.</div>
        <?php else: ?>
            <?php $activeBadge = $statusBadge($currentType === 'none' ? 'refund' : $currentType, $currentStatus); ?>
            <div class="meta-grid">
                <div class="meta-item"><span class="k">Request Type</span><span class="v"><?= $h($currentType !== 'none' ? ucfirst($currentType) : '—') ?></span></div>
                <div class="meta-item"><span class="k">Current Status</span><span class="v"><span class="badge <?= $h((string)($activeBadge['class'] ?? 'badge-default')) ?>"><?= $h((string)($activeBadge['label'] ?? 'Unknown')) ?></span></span></div>
                <div class="meta-item"><span class="k">Requested Date</span><span class="v"><?= $h($currentDate !== '' ? $currentDate : '—') ?></span></div>
                <div class="meta-item"><span class="k">Reason</span><span class="v"><?= $h($currentReason !== '' ? $currentReason : '—') ?></span></div>
                <div class="meta-item"><span class="k">Amount</span><span class="v"><?= $h($currentAmount !== '' && is_numeric($currentAmount) ? $money($currentAmount, $currency) : '—') ?></span></div>
                <div class="meta-item"><span class="k">Refund Mode</span><span class="v"><?= $h($currentRefundMode !== '' ? $currentRefundMode : '—') ?></span></div>
                <div class="meta-item"><span class="k">Refund Reference</span><span class="v"><?= $h($currentRefundRef !== '' ? $currentRefundRef : '—') ?></span></div>
            </div>
        <?php endif; ?>
    </section>

    <section class="split">
        <div class="card stack">
            <h3>Cancel Request</h3>
            <?php if ($cancelRow): ?>
                <div class="meta-grid">
                    <div class="meta-item"><span class="k">Status</span><span class="v"><span class="badge <?= $h((string)($cancelBadge['class'] ?? 'badge-default')) ?>"><?= $h((string)($cancelBadge['label'] ?? 'Unknown')) ?></span></span></div>
                    <div class="meta-item"><span class="k">Source</span><span class="v"><?= $h($pickValue($cancelRow, ['cancel_source']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Reason Code</span><span class="v"><?= $h($pickValue($cancelRow, ['cancel_reason_code']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Reason Text</span><span class="v"><?= $h($pickValue($cancelRow, ['cancel_reason_text', 'reason_text', 'reason']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Admin Note</span><span class="v"><?= $h($pickValue($cancelRow, ['admin_note']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Refundable Amount</span><span class="v"><?= $h(($pickValue($cancelRow, ['refundable_amount']) !== '' && is_numeric($pickValue($cancelRow, ['refundable_amount']))) ? $money($pickValue($cancelRow, ['refundable_amount']), $currency) : '—') ?></span></div>
                    <div class="meta-item"><span class="k">Refund Status</span><span class="v"><?= $h($pickValue($cancelRow, ['refund_status']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Requested At</span><span class="v"><?= $h($pickDate($cancelRow) ?: '—') ?></span></div>
                </div>
            <?php else: ?>
                <div class="empty">No cancel request found for this order.</div>
            <?php endif; ?>
        </div>

        <div class="card stack">
            <h3>Refund Request</h3>
            <?php if ($refundRow): ?>
                <div class="meta-grid">
                    <div class="meta-item"><span class="k">Refund Code</span><span class="v"><?= $h($pickValue($refundRow, ['refund_code']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Status</span><span class="v"><span class="badge <?= $h((string)($refundBadge['class'] ?? 'badge-default')) ?>"><?= $h((string)($refundBadge['label'] ?? 'Unknown')) ?></span></span></div>
                    <div class="meta-item"><span class="k">Refund Mode</span><span class="v"><?= $h($pickValue($refundRow, ['refund_mode']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Requested Amount</span><span class="v"><?= $h(($pickValue($refundRow, ['requested_refund_amount']) !== '' && is_numeric($pickValue($refundRow, ['requested_refund_amount']))) ? $money($pickValue($refundRow, ['requested_refund_amount']), $currency) : '—') ?></span></div>
                    <div class="meta-item"><span class="k">Approved Amount</span><span class="v"><?= $h(($pickValue($refundRow, ['approved_refund_amount']) !== '' && is_numeric($pickValue($refundRow, ['approved_refund_amount']))) ? $money($pickValue($refundRow, ['approved_refund_amount']), $currency) : '—') ?></span></div>
                    <div class="meta-item"><span class="k">Actual Refunded</span><span class="v"><?= $h(($pickValue($refundRow, ['actual_refunded_amount']) !== '' && is_numeric($pickValue($refundRow, ['actual_refunded_amount']))) ? $money($pickValue($refundRow, ['actual_refunded_amount']), $currency) : '—') ?></span></div>
                    <div class="meta-item"><span class="k">Payment Provider</span><span class="v"><?= $h($pickValue($refundRow, ['payment_provider']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Reference</span><span class="v"><?= $h($pickValue($refundRow, ['payment_reference_snapshot', 'payment_reference', 'refund_reference']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Admin/Internal Note</span><span class="v"><?= $h($pickValue($refundRow, ['admin_note', 'internal_note', 'note']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Requested At</span><span class="v"><?= $h($pickDate($refundRow) ?: '—') ?></span></div>
                </div>
            <?php else: ?>
                <div class="empty">No refund request found for this order.</div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card actions">
        <h2>Take Action</h2>
        <div class="stack">
            <h3>Cancel / Refund Request Actions</h3>
            <?php if (!$requestActionEndpointExists): ?>
                <div class="empty">Read-only mode: seller request action endpoint is not available in this environment.</div>
            <?php elseif (!$canApproveCancel && !$canRejectCancel && !$canApproveRefund && !$canRejectRefund): ?>
                <div class="empty">No actions are currently available for this request state.</div>
            <?php else: ?>
                <form method="post" action="<?= $h($requestActionEndpoint) ?>" class="stack">
                    <input type="hidden" name="order_id" value="<?= $h((string)$orderId) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                    <label for="note" class="muted">Note (optional)</label>
                    <textarea id="note" name="note" placeholder="Add a note for approval/rejection logs"></textarea>
                    <div class="action-row">
                        <?php if ($canApproveCancel): ?>
                            <button type="submit" class="btn-approve" name="action" value="approve_cancel">Approve Cancel</button>
                        <?php endif; ?>
                        <?php if ($canRejectCancel): ?>
                            <button type="submit" class="btn-reject" name="action" value="reject_cancel">Reject Cancel</button>
                        <?php endif; ?>
                        <?php if ($canApproveRefund): ?>
                            <button type="submit" class="btn-approve" name="action" value="approve_refund">Approve Refund</button>
                        <?php endif; ?>
                        <?php if ($canRejectRefund): ?>
                            <button type="submit" class="btn-reject" name="action" value="reject_refund">Reject Refund</button>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="stack">
            <h3>Order Fulfillment Actions</h3>
            <?php if (!$fulfillmentActionEndpointExists): ?>
                <div class="empty">Fulfillment actions are not enabled yet for this environment.</div>
            <?php elseif (empty($fulfillmentActions)): ?>
                <div class="empty">No fulfillment actions are currently available for this order state.</div>
            <?php else: ?>
                <div class="action-row">
                    <?php foreach ($fulfillmentActions as $fulfillmentAction): ?>
                        <form method="post" action="<?= $h($fulfillmentActionEndpoint) ?>">
                            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                            <input type="hidden" name="order_id" value="<?= $h((string)$orderId) ?>">
                            <input type="hidden" name="return_url" value="<?= $h($returnUrl) ?>">
                            <button
                                type="submit"
                                class="<?= $h((string)($fulfillmentAction['class'] ?? '')) ?>"
                                name="action"
                                value="<?= $h((string)($fulfillmentAction['value'] ?? '')) ?>"
                            ><?= $h((string)($fulfillmentAction['label'] ?? 'Action')) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>
</body>
</html>