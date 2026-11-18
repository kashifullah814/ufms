<?php
/**
 * Automatic vouchers for existing modules (fees, expenses, salaries, receipts).
 * Users debit/credit kabhi type nahi karte - yeh functions balanced voucher banate hain.
 * Sab functions transaction ke andar call hote hain (post_voucher() yehi check karta hai).
 * database/backfill_vouchers.php bhi yahi functions use karta hai.
 */

/* ======================= Fees (RV) ======================= */

/**
 * Naye challan ka head-wise split. Scholarship pehle tuition se, phir admission, exam, other se minus.
 * Returns ['tuition' => .., 'admission' => .., 'exam' => .., 'other' => ..] (total = $fee['total'])
 */
function fee_challan_split(array $fee)
{
    $split = ['tuition' => $fee['tuition'], 'admission' => $fee['admission'], 'exam' => $fee['exam'], 'other' => $fee['other']];
    $discount = to_paisa($fee['discount']);
    foreach ($split as $k => $v) {
        $p = to_paisa($v);
        $cut = min($p, $discount);
        $discount -= $cut;
        $split[$k] = ($p - $cut) / 100;
    }
    return $split;
}

/** Challan ke har income head ki kul raqam (paisa), fine samet. Purane challan (split NULL) = sab tuition. */
function fee_challan_heads(array $rec)
{
    if ($rec['tuition_amt'] === null) {
        $heads = ['FEE_TUITION' => to_paisa($rec['total_fee'])];
    } else {
        $heads = ['FEE_TUITION' => to_paisa($rec['tuition_amt']), 'FEE_ADMISSION' => to_paisa($rec['admission_amt']),
                  'FEE_EXAM' => to_paisa($rec['exam_amt']), 'FEE_OTHER' => to_paisa($rec['other_amt'])];
    }
    $heads['FEE_FINE'] = to_paisa($rec['late_fee_fine']);
    return $heads;
}

/**
 * Payment ko heads mein baantna: har head ke BAQI hisse ke tanasub se (pehle ki vouchers dekh kar).
 * Is tarah challan poora hone par har head bilkul apni raqam tak pahunchta hai. Rounding sab se bare
 * baqi head (aam tor par Tuition) mein jati hai. Returns [system_key => paisa].
 */
function fee_payment_allocation(array $rec, $amountPaisa)
{
    $heads = fee_challan_heads($rec);
    $done = [];
    $res = db_select(
        "SELECT a.system_key, SUM(vl.credit) - SUM(vl.debit) AS amt
         FROM fee_payments fp
         JOIN vouchers v ON v.source_type = 'fee_payment' AND v.source_id = fp.id AND v.status = 'Posted'
         JOIN voucher_lines vl ON vl.voucher_id = v.id
         JOIN accounts a ON a.id = vl.account_id
         WHERE fp.fee_record_id = ? AND a.is_cash_bank = 0
         GROUP BY a.system_key", [(int)$rec['id']]);
    while ($r = $res->fetch_assoc()) {
        $done[(string)$r['system_key']] = to_paisa($r['amt']);
    }
    $remaining = [];
    foreach ($heads as $key => $p) {
        $remaining[$key] = max(0, $p - ($done[$key] ?? 0));
    }
    $totalRemaining = array_sum($remaining);
    if ($totalRemaining <= 0) {
        return ['FEE_TUITION' => $amountPaisa]; // hona nahi chahiye; safe fallback
    }
    $alloc = [];
    foreach ($remaining as $key => $rem) {
        $alloc[$key] = min($rem, (int)floor((float)$amountPaisa * $rem / $totalRemaining));
    }
    // Rounding ka farq: sab se bara baqi head
    arsort($remaining);
    $left = $amountPaisa - array_sum($alloc);
    foreach (array_keys($remaining) as $key) {
        if ($left <= 0) break;
        $room = max(0, $remaining[$key] - $alloc[$key]);
        $take = min($room, $left);
        $alloc[$key] += $take;
        $left -= $take;
    }
    if ($left > 0) {
        $alloc['FEE_TUITION'] = ($alloc['FEE_TUITION'] ?? 0) + $left; // payment baqi se zyada (purana data)
    }
    return array_filter($alloc);
}

/**
 * Ek fee payment row ka RV: Dr Bank / Cr fee heads. $rec = fees_records row (payment se PEHLE ka fine samet).
 * $student: full_name, reg_number. Returns voucher ['id', 'voucher_no'].
 */
function post_fee_payment_voucher(array $rec, $paymentId, $amount, $date, array $student, $user, $legacy = false)
{
    $amountPaisa = to_paisa($amount);
    $lines = [['account_id' => account_id('BANK'), 'debit' => $amountPaisa / 100, 'narration' => 'Fee received']];
    foreach (fee_payment_allocation($rec, $amountPaisa) as $key => $p) {
        $lines[] = ['account_id' => account_id($key), 'credit' => $p / 100];
    }
    $narration = "Fee received - Challan {$rec['challan_number']}, {$student['full_name']} ({$student['reg_number']})"
               . ($rec['semester_no'] ? ", Semester {$rec['semester_no']}" : '')
               . ($legacy && $rec['tuition_amt'] === null ? ' (legacy challan - not itemized)' : '');
    return post_voucher('RV', $date, substr($narration, 0, 255), $lines, 'fee_payment', $paymentId, $user);
}

/** fee_payments row + uska RV. $rec = locked fees_records row (update se pehle). */
function record_fee_payment(array $rec, $amount, $date, array $student, $user, $legacy = false)
{
    db_exec("INSERT INTO fee_payments (fee_record_id, amount, payment_date, received_by) VALUES (?, ?, ?, ?)",
        [(int)$rec['id'], round((float)$amount, 2), $date, $legacy ? ($rec['collected_by'] ? (int)$rec['collected_by'] : null) : ($user ? (int)$user['id'] : null)]);
    $pid = db_insert_id();
    $v = post_fee_payment_voucher($rec, $pid, $amount, $date, $student, $user, $legacy);
    return ['payment_id' => $pid] + $v;
}

/**
 * Purana challan (fee_payments se pehle ka): amount_paid ka jo hissa fee_payments mein nahi,
 * uski ek payment row + RV, challan ki (purani) payment_date par. Kuch baqi na ho to null.
 */
function ensure_legacy_fee_payment(array $rec, array $student, $user)
{
    $recorded = to_paisa(db_value("SELECT SUM(amount) FROM fee_payments WHERE fee_record_id = ?", [(int)$rec['id']], 0));
    $gap = to_paisa($rec['amount_paid']) - $recorded;
    if ($gap <= 0) {
        return null;
    }
    $date = $rec['payment_date'] ?: substr((string)$rec['created_at'], 0, 10);
    return record_fee_payment($rec, $gap / 100, $date, $student, $user, true) + ['amount' => $gap / 100, 'date' => $date];
}

/* ======================= Account head lists ======================= */

/**
 * Kisi group (system key) ke neeche ke heads, e.g. accounts_under('GRP_NON_SALARIES').
 * Default: sirf active, voucher ke qabil (group nahi) heads, code ke hisaab se.
 */
function accounts_under($groupKey, $postableOnly = true, $activeOnly = true)
{
    $groupId = account_id($groupKey);
    $children = [];
    foreach (db_select("SELECT * FROM accounts ORDER BY code")->fetch_all(MYSQLI_ASSOC) as $a) {
        $children[(int)$a['parent_id']][] = $a;
    }
    $out = [];
    $stack = [$groupId];
    while ($stack) {
        $p = array_pop($stack);
        foreach ($children[$p] ?? [] as $a) {
            $stack[] = (int)$a['id'];
            if ((!$postableOnly || !(int)$a['is_group']) && (!$activeOnly || $a['status'] === 'Active')) {
                $out[(int)$a['id']] = $a;
            }
        }
    }
    uasort($out, function ($x, $y) { return strcmp($x['code'], $y['code']); });
    return $out;
}

/* ======================= Expenses (PV) ======================= */

/** Expense ka PV: Dr expense head / Cr Bank. $exp = expenses row. */
function post_expense_voucher(array $exp, $user)
{
    $amount = round((float)$exp['amount'], 2);
    $lines = [
        ['account_id' => (int)$exp['account_id'], 'debit' => $amount, 'narration' => $exp['title']],
        ['account_id' => account_id('BANK'), 'credit' => $amount],
    ];
    return post_voucher('PV', $exp['expense_date'], substr('Expense: ' . $exp['title'], 0, 255), $lines, 'expense', (int)$exp['id'], $user);
}

/* ======================= Salaries (PV) ======================= */

const EMPLOYMENT_TYPES = ['Regular' => 'Monthly', 'Contract' => 'Monthly', 'Visiting' => 'Hourly', 'Daily Wages' => 'Daily'];

/** Employment type ka salary expense head (Salaries - Established ke neeche) */
function salary_expense_key($employmentType)
{
    $map = ['Regular' => 'SAL_REGULAR', 'Contract' => 'SAL_CONTRACT', 'Visiting' => 'SAL_VISITING', 'Daily Wages' => 'SAL_DAILY'];
    return $map[$employmentType] ?? 'SAL_REGULAR';
}

/** Hourly/Daily pay ka label: "12.5 hours x 1,500.00" */
function salary_units_label(array $sal)
{
    if ($sal['units'] === null || $sal['pay_basis'] === 'Monthly') {
        return '';
    }
    $u = rtrim(rtrim(number_format((float)$sal['units'], 2, '.', ''), '0'), '.');
    return $u . ($sal['pay_basis'] === 'Hourly' ? ' hours' : ' days') . ' x ' . money($sal['rate']);
}

/**
 * Paid salary ka PV: Dr Salaries-<type> (gross) / Cr Bank (net) / Cr Salary Deductions Payable.
 * $sal = staff_salaries row + full_name, staff_id_number. $date = payment date.
 * (Agar poori salary deductions mein chali jaye to Bank line nahi banti, is liye JV.)
 */
function post_salary_voucher(array $sal, $date, $user)
{
    $gross = to_paisa($sal['base_salary']) + to_paisa($sal['allowances']);
    $ded = to_paisa($sal['deductions']);
    $net = $gross - $ded;
    $lines = [['account_id' => account_id(salary_expense_key($sal['employment_type'])), 'debit' => $gross / 100,
               'narration' => trim('Gross salary ' . salary_units_label($sal))]];
    if ($net > 0) {
        $lines[] = ['account_id' => account_id('BANK'), 'credit' => $net / 100, 'narration' => 'Net salary paid'];
    }
    if ($ded > 0) {
        $lines[] = ['account_id' => account_id('SAL_DEDUCTIONS'), 'credit' => $ded / 100, 'narration' => 'Deductions withheld'];
    }
    $narration = 'Salary ' . month_label($sal['month_year']) . " - {$sal['full_name']} ({$sal['staff_id_number']}), {$sal['employment_type']}";
    return post_voucher($net > 0 ? 'PV' : 'JV', $date, substr($narration, 0, 255), $lines, 'salary', (int)$sal['id'], $user);
}

/* ======================= Grants & student charges (RV) ======================= */

/** Fee collection page jin heads ko khud post karta hai - Student Charges page par nahi dikhte */
const FEE_COLLECTION_KEYS = ['FEE_TUITION', 'FEE_ADMISSION', 'FEE_EXAM', 'FEE_OTHER', 'FEE_FINE'];

/** Grant heads: Receipts ke neeche, Student Related Charges ke ilawa (HEC, HED, aur Admin ke naye) */
function grant_heads()
{
    return array_diff_key(accounts_under('GRP_RECEIPTS'), accounts_under('GRP_STUDENT_CHARGES', true, false));
}

/** Student charge heads: Hostel, Transport, Repeat Paper, Summer, DMC, Degree, Verification, Migration ... */
function student_charge_heads()
{
    return array_filter(accounts_under('GRP_STUDENT_CHARGES'), function ($a) {
        return !in_array($a['system_key'], FEE_COLLECTION_KEYS, true);
    });
}

/** other_receipts row ka RV: Dr Bank / Cr receipt head. $r mein head_name, aur student ho to full_name, reg_number. */
function post_other_receipt_voucher(array $r, $user)
{
    $amount = round((float)$r['amount'], 2);
    if ($r['receipt_kind'] === 'Grant') {
        $narration = "{$r['head_name']} received" . ($r['reference_no'] ? " - Ref {$r['reference_no']}" : '') . ($r['description'] ? ": {$r['description']}" : '');
    } else {
        $narration = "{$r['head_name']}" . (!empty($r['full_name']) ? " - {$r['full_name']} ({$r['reg_number']})" : '')
                   . ($r['reference_no'] ? ", Receipt {$r['reference_no']}" : '') . ($r['description'] ? ": {$r['description']}" : '');
    }
    $lines = [
        ['account_id' => account_id('BANK'), 'debit' => $amount],
        ['account_id' => (int)$r['account_id'], 'credit' => $amount, 'narration' => $r['description'] ?: null],
    ];
    return post_voucher('RV', $r['receipt_date'], substr($narration, 0, 255), $lines, 'receipt', (int)$r['id'], $user);
}
