<?php
/**
 * SmartCart — Shared payment processing functions.
 * Included by webhook.php and paypal_return.php.
 */

require_once __DIR__ . '/../services/PaymentService.php';
require_once __DIR__ . '/notifications.php';

/**
 * Mark a payment as paid or failed, create the order row, and trigger
 * the all-paid check.  Safe to call multiple times (idempotent).
 */
function process_payment_by_id(int $payment_id, string $new_status): void
{
    $pdo = getPDO();

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT pay.id, pay.status, pay.group_id, pay.user_id,
                   pay.product_id, pay.amount_ils
            FROM payments pay
            WHERE pay.id = ?
            FOR UPDATE
        ");
        $stmt->execute([$payment_id]);
        $pay = $stmt->fetch();

        if (!$pay) {
            $pdo->rollBack();
            PaymentService::log('processor_pay_row_not_found', ['payment_id' => $payment_id]);
            return;
        }

        // Idempotency: skip if already terminal
        if (in_array($pay['status'], ['paid', 'completed', 'failed'], true)) {
            $pdo->rollBack();
            return;
        }

        if ($new_status === 'paid') {
            $pdo->prepare("UPDATE payments SET status='paid', paid_at=NOW() WHERE id=?")
                ->execute([$payment_id]);

            $product_id = (int)($pay['product_id'] ?? 0);
            if (!$product_id) {
                $ps = $pdo->prepare("SELECT product_id FROM group_purchases WHERE id=?");
                $ps->execute([$pay['group_id']]);
                $product_id = (int)($ps->fetchColumn() ?: 0);
            }

            $pdo->prepare("UPDATE group_members SET status='paid' WHERE group_id=? AND user_id=?")
                ->execute([$pay['group_id'], $pay['user_id']]);

            if ($product_id) {
                $pdo->prepare("
                    INSERT IGNORE INTO orders
                           (group_id, payment_id, user_id, product_id, amount_paid, shipping_status)
                    VALUES (?, ?, ?, ?, ?, 'pending')
                ")->execute([$pay['group_id'], $payment_id, $pay['user_id'], $product_id, $pay['amount_ils']]);
            }

            $pdo->commit();

            notify(
                (int)$pay['user_id'],
                'payment_confirmed',
                'Payment confirmed!',
                'Your payment has been received. We\'ll notify you when the order is ready.',
                defined('APP_URL') ? APP_URL . '/pages/my-orders.php' : ''
            );

            check_all_paid((int)$pay['group_id']);

        } else {
            $pdo->prepare("UPDATE payments SET status='failed' WHERE id=?")->execute([$payment_id]);
            $pdo->commit();

            notify(
                (int)$pay['user_id'],
                'payment_failed',
                'Payment failed',
                'Your payment could not be processed. Please try again from the group page.',
                defined('APP_URL') ? APP_URL . '/pages/group.php?id=' . $pay['group_id'] : ''
            );
        }

        PaymentService::log('payment_processed', [
            'payment_id' => $payment_id,
            'group_id'   => $pay['group_id'],
            'user_id'    => $pay['user_id'],
            'new_status' => $new_status,
        ]);

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        PaymentService::log('processor_error', [
            'payment_id' => $payment_id,
            'error'      => $e->getMessage(),
        ]);
    }
}

/**
 * After each paid payment, check if all members of the group have paid.
 * If yes → set group status to order_ready and notify business + members.
 */
function check_all_paid(int $group_id): void
{
    $pdo = getPDO();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS paid_count
        FROM group_members
        WHERE group_id = ?
    ");
    $stmt->execute([$group_id]);
    $row = $stmt->fetch();

    if (!$row || (int)$row['total'] === 0) return;
    if ((int)$row['paid_count'] < (int)$row['total']) return;

    $pdo->prepare("
        UPDATE group_purchases SET status='order_ready'
        WHERE id=? AND status='ready_for_payment'
    ")->execute([$group_id]);

    if ($pdo->rowCount() === 0) return;

    $bstmt = $pdo->prepare("
        SELECT b.user_id, p.name AS product_name
        FROM   group_purchases gp
        JOIN   products p  ON p.id  = gp.product_id
        JOIN   businesses b ON b.id = p.business_id
        WHERE  gp.id = ?
    ");
    $bstmt->execute([$group_id]);
    $biz = $bstmt->fetch();
    if ($biz) {
        notify(
            (int)$biz['user_id'],
            'order_ready',
            'All payments received!',
            'Group #' . $group_id . ' (' . $biz['product_name'] . ') — all members paid. Ready to fulfil.',
            defined('APP_URL') ? APP_URL . '/pages/business/dashboard.php' : ''
        );
    }

    notify_group_members(
        $group_id,
        'order_ready',
        'Your order is confirmed!',
        'All members have paid. Your order is being prepared.',
        defined('APP_URL') ? APP_URL . '/pages/my-orders.php' : ''
    );

    PaymentService::log('group_order_ready', ['group_id' => $group_id]);
}
