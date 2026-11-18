<?php
/**
 * One-time backfill: vouchers for records that existed before the accounting module.
 *
 *   /opt/lampp/bin/php database/backfill_vouchers.php          (DRY RUN - prints, then rolls back)
 *   /opt/lampp/bin/php database/backfill_vouchers.php --run    (real run - commits)
 *
 * Sab kuch ek transaction mein; dry run bilkul wahi vouchers (numbers samet) banata hai jo real run
 * banayega, phir rollback. Jin records ka voucher pehle se hai woh skip hote hain (dobara chalana safe).
 * Wahi functions use hote hain jo live pages (includes/postings.php).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__) . '/includes';
require $root . '/config.php';
date_default_timezone_set(APP_TIMEZONE);
require $root . '/db_connect.php';
require $root . '/functions.php';
require $root . '/accounting.php';
require $root . '/postings.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$run = in_array('--run', $argv, true);
$admin = db_row("SELECT * FROM users WHERE role = 'Admin' AND status = 'Active' ORDER BY id LIMIT 1");
if (!$admin) {
    fwrite(STDERR, "No active Admin user found (vouchers need a 'created by' user).\n");
    exit(1);
}

/* ---------- Plan: har item = [date, order, label, callable] ---------- */
$items = [];

// Fees: amount_paid ka jo hissa fee_payments mein nahi (purane challans)
$res = db_select("SELECT fr.*, u.full_name, s.reg_number,
                         fr.amount_paid - COALESCE((SELECT SUM(fp.amount) FROM fee_payments fp WHERE fp.fee_record_id = fr.id), 0) AS gap
                  FROM fees_records fr JOIN students s ON s.id = fr.student_id JOIN users u ON u.id = s.user_id
                  HAVING gap > 0 ORDER BY fr.id");
while ($r = $res->fetch_assoc()) {
    $date = $r['payment_date'] ?: substr($r['created_at'], 0, 10);
    $items[] = [$date, 1, "Fee challan {$r['challan_number']} (fees_records #{$r['id']}): PKR " . money($r['gap']),
        function () use ($r, $admin) {
            $rec = db_row("SELECT * FROM fees_records WHERE id = ? FOR UPDATE", [(int)$r['id']]);
            return ensure_legacy_fee_payment($rec, $r, $admin);
        }];
}

// Expenses: jin ka koi Posted voucher nahi
$res = db_select("SELECT e.* FROM expenses e
                  WHERE NOT EXISTS (SELECT 1 FROM vouchers v WHERE v.source_type = 'expense' AND v.source_id = e.id AND v.status = 'Posted')
                  ORDER BY e.id");
while ($r = $res->fetch_assoc()) {
    $items[] = [$r['expense_date'], 2, "Expense #{$r['id']} \"{$r['title']}\": PKR " . money($r['amount']),
        function () use ($r, $admin) { return post_expense_voucher($r, $admin); }];
}

// Salaries: Paid bills jin ka koi Posted voucher nahi. Date = paid_date, warna mahine ka aakhri din.
$res = db_select("SELECT ss.*, st.staff_id_number, u.full_name FROM staff_salaries ss JOIN staff st ON st.id = ss.staff_id JOIN users u ON u.id = st.user_id
                  WHERE ss.status = 'Paid'
                    AND NOT EXISTS (SELECT 1 FROM vouchers v WHERE v.source_type = 'salary' AND v.source_id = ss.id AND v.status = 'Posted')
                  ORDER BY ss.id");
while ($r = $res->fetch_assoc()) {
    $date = $r['paid_date'] ?: date('Y-m-t', strtotime($r['month_year'] . '-01'));
    $items[] = [$date, 3, "Salary #{$r['id']} {$r['full_name']} " . month_label($r['month_year']) . ' (' . ($r['paid_date'] ? 'paid ' . $r['paid_date'] : 'no paid date - using last day of month') . "): net PKR " . money($r['net_salary']),
        function () use ($r, $date, $admin) { return post_salary_voucher($r, $date, $admin); }];
}

/* ---------- Execute ---------- */
usort($items, function ($a, $b) { return [$a[0], $a[1]] <=> [$b[0], $b[1]]; });
echo str_repeat('=', 78) . "\n";
echo ($run ? 'REAL RUN' : 'DRY RUN (nothing will be saved)') . ' - database ' . DB_NAME . ' - ' . date('Y-m-d H:i') . "\n";
echo 'Vouchers created by: ' . $admin['full_name'] . " (user #{$admin['id']})\n";
echo str_repeat('=', 78) . "\n";
if (!$items) {
    echo "Nothing to backfill - every record already has a voucher.\n";
    exit(0);
}

db()->begin_transaction();
$count = 0;
try {
    foreach ($items as $it) {
        $v = ($it[3])();
        if (!$v) {
            continue;
        }
        $count++;
        $h = db_row("SELECT * FROM vouchers WHERE id = ?", [(int)$v['id']]);
        printf("\n%s  %s  PKR %s\n  %s\n  %s\n", $h['voucher_no'], $h['voucher_date'], money($h['total_amount']), $it[2], $h['narration']);
        $lines = db_select("SELECT a.code, a.name, vl.debit, vl.credit FROM voucher_lines vl JOIN accounts a ON a.id = vl.account_id WHERE vl.voucher_id = ? ORDER BY vl.debit = 0, vl.id", [(int)$v['id']]);
        while ($l = $lines->fetch_assoc()) {
            printf("    %-6s %-34s %16s %16s\n", $l['code'], substr($l['name'], 0, 34),
                (float)$l['debit'] > 0 ? money($l['debit']) : '', (float)$l['credit'] > 0 ? money($l['credit']) : '');
        }
    }
    $bank = db_value("SELECT SUM(vl.debit) - SUM(vl.credit) FROM voucher_lines vl JOIN vouchers v ON v.id = vl.voucher_id AND v.status = 'Posted' WHERE vl.account_id = ?", [account_id('BANK')], 0);
    $tb = trial_balance('2000-01-01', date('Y-m-d'));
    echo "\n" . str_repeat('-', 78) . "\n";
    printf("%d voucher(s). Bank balance after backfill: PKR %s. Trial balance (all dates): Dr %s / Cr %s %s\n",
        $count, money($bank), money($tb['totals']['close_dr'] / 100), money($tb['totals']['close_cr'] / 100),
        $tb['totals']['close_dr'] === $tb['totals']['close_cr'] ? '- BALANCED' : '- NOT BALANCED!');
} catch (Throwable $t) {
    db()->rollback();
    fwrite(STDERR, "\nERROR: " . $t->getMessage() . "\nNothing was saved.\n");
    exit(1);
}

if ($run) {
    db()->commit();
    echo "Committed.\n";
} else {
    db()->rollback();
    // Rollback auto-increment counters wapas nahi karta; unhe max(id)+1 par le aate hain (sirf safai)
    foreach (['vouchers', 'voucher_lines', 'fee_payments'] as $t) {
        db()->query("ALTER TABLE `$t` AUTO_INCREMENT = 1");
    }
    echo "Rolled back - nothing was saved. Run again with --run to save.\n";
}
