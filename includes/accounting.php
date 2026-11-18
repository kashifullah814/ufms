<?php
/**
 * Double-entry accounting helpers: chart of accounts, fiscal year, vouchers.
 * Har voucher sirf post_voucher() se banta hai - wahi check karta hai ke total debit = total credit.
 * Fiscal year July-June hai; $fy = start year (FY 2026-27 => 2026).
 */

/** Voucher validation fail ho to yeh exception aata hai (message user ko dikhane ke qabil hai). */
class VoucherException extends RuntimeException
{
}

const VOUCHER_TYPES   = ['RV' => 'Receipt Voucher', 'PV' => 'Payment Voucher', 'JV' => 'Journal Voucher'];
const VOUCHER_SOURCES = ['fee_payment' => 'Fee Collection', 'salary' => 'Salary', 'expense' => 'Expense', 'receipt' => 'Receipt', 'manual' => 'Manual'];
const ACCOUNT_TYPES   = ['Asset', 'Liability', 'Equity', 'Income', 'Expense'];

/* ======================= Fiscal year ======================= */

/** '2026-09-13' -> 2026, '2026-06-30' -> 2025 */
function fiscal_year_of($date)
{
    $t = strtotime((string)$date);
    $y = (int)date('Y', $t);
    return (int)date('n', $t) >= 7 ? $y : $y - 1;
}

/** 2026 -> "2026-27" */
function fiscal_year_label($fy)
{
    return sprintf('%d-%02d', (int)$fy, ((int)$fy + 1) % 100);
}

/** 2026 -> "2627" (voucher number ke liye) */
function fiscal_year_code($fy)
{
    return sprintf('%02d%02d', (int)$fy % 100, ((int)$fy + 1) % 100);
}

/** 2026 -> ['2026-07-01', '2027-06-30'] */
function fiscal_year_range($fy)
{
    return [sprintf('%d-07-01', (int)$fy), sprintf('%d-06-30', (int)$fy + 1)];
}

/* ======================= Accounts ======================= */

/** Rupees ko paisa (integer) mein - float comparison ki ghalti se bachne ke liye */
function to_paisa($amount)
{
    return (int)round((float)$amount * 100);
}

/** Asset/Expense ka normal balance debit hota hai; Liability/Equity/Income ka credit. */
function account_is_debit_normal($type)
{
    return in_array($type, ['Asset', 'Expense'], true);
}

/** System head ki id, e.g. account_id('BANK'). Code mein account IDs hardcode nahi karte. */
function account_id($systemKey)
{
    static $cache = [];
    if (!isset($cache[$systemKey])) {
        $id = db_value("SELECT id FROM accounts WHERE system_key = ?", [$systemKey]);
        if (!$id) {
            throw new VoucherException("Account head '{$systemKey}' is missing from the chart of accounts.");
        }
        $cache[$systemKey] = (int)$id;
    }
    return $cache[$systemKey];
}

/**
 * Saare accounts tree order mein (parent ke neeche uske children, code ke hisaab se).
 * Har row mein 'depth' (0 = top level) add hota hai.
 */
function account_tree()
{
    $rows = db_select("SELECT * FROM accounts ORDER BY code")->fetch_all(MYSQLI_ASSOC);
    $children = [];
    foreach ($rows as $r) {
        $children[(int)$r['parent_id']][] = $r;
    }
    $out = [];
    $walk = function ($parentId, $depth) use (&$walk, &$out, $children) {
        foreach ($children[$parentId] ?? [] as $r) {
            $r['depth'] = $depth;
            $out[] = $r;
            $walk((int)$r['id'], $depth + 1);
        }
    };
    $walk(0, 0);
    return $out;
}

/** Voucher mein use ho sakne wale heads (active, group nahi) - type ke hisaab se grouped */
function postable_accounts()
{
    $grouped = array_fill_keys(ACCOUNT_TYPES, []);
    $res = db_select("SELECT id, code, name, type FROM accounts WHERE status = 'Active' AND is_group = 0 ORDER BY code");
    while ($r = $res->fetch_assoc()) {
        $grouped[$r['type']][] = $r;
    }
    return array_filter($grouped);
}

/* ======================= Vouchers ======================= */

/**
 * Agla voucher number, e.g. RV-2627-0001. Transaction ke andar call karein:
 * sequence row lock rehti hai jab tak commit na ho, is liye do users ko ek number nahi milta,
 * aur rollback par number bhi wapas ho jata hai (koi gap nahi).
 */
function next_voucher_no($type, $fy)
{
    db_exec("INSERT INTO voucher_sequences (voucher_type, fiscal_year, last_no) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE last_no = last_no + 1", [$type, (int)$fy]);
    $n = (int)db_value("SELECT last_no FROM voucher_sequences WHERE voucher_type = ? AND fiscal_year = ?", [$type, (int)$fy]);
    return sprintf('%s-%s-%04d', $type, fiscal_year_code($fy), $n);
}

/**
 * Balanced voucher post karta hai. Caller pehle db()->begin_transaction() kare aur baad mein commit.
 *
 * $lines: [['account_id' => 2, 'debit' => 500, 'credit' => 0, 'narration' => '...'], ...]
 * Returns ['id' => int, 'voucher_no' => string]. Ghalat data par VoucherException.
 */
function post_voucher($type, $date, $narration, array $lines, $sourceType = 'manual', $sourceId = null, $user = null)
{
    if (!(int)db_value("SELECT @@in_transaction")) {
        throw new LogicException('post_voucher() must be called inside a database transaction.');
    }
    $user = $user ?: current_user();
    $narration = trim((string)$narration);

    if (!isset(VOUCHER_TYPES[$type])) {
        throw new VoucherException('Invalid voucher type.');
    }
    if (!isset(VOUCHER_SOURCES[$sourceType])) {
        throw new VoucherException('Invalid voucher source.');
    }
    if (!valid_date($date)) {
        throw new VoucherException('Please enter a valid voucher date.');
    }
    if ($narration === '' || strlen($narration) > 255) {
        throw new VoucherException('Narration is required (maximum 255 characters).');
    }
    if (count($lines) < 2) {
        throw new VoucherException('A voucher needs at least two lines (one debit and one credit).');
    }

    // Lines saaf karein aur accounts load karein
    $clean = [];
    foreach ($lines as $i => $l) {
        $n = $i + 1;
        $debit  = round((float)($l['debit'] ?? 0), 2);
        $credit = round((float)($l['credit'] ?? 0), 2);
        if ($debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)) {
            throw new VoucherException("Line {$n}: enter either a debit or a credit amount (not both).");
        }
        $clean[] = [
            'account_id' => (int)($l['account_id'] ?? 0),
            'debit'      => $debit,
            'credit'     => $credit,
            'narration'  => substr(trim((string)($l['narration'] ?? '')), 0, 255),
        ];
    }
    $ids = array_values(array_unique(array_column($clean, 'account_id')));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $accounts = [];
    $res = db_select("SELECT id, code, name, is_group, is_cash_bank, status FROM accounts WHERE id IN ($placeholders)", $ids);
    while ($a = $res->fetch_assoc()) {
        $accounts[(int)$a['id']] = $a;
    }

    $totalDr = 0;
    $totalCr = 0;
    $cashDr = false;
    $cashCr = false;
    foreach ($clean as $i => $l) {
        $n = $i + 1;
        $a = $accounts[$l['account_id']] ?? null;
        if (!$a) {
            throw new VoucherException("Line {$n}: please select a valid account head.");
        }
        if ((int)$a['is_group'] === 1) {
            throw new VoucherException("Line {$n}: \"{$a['name']}\" is a group head; select one of its sub-heads.");
        }
        if ($a['status'] !== 'Active') {
            throw new VoucherException("Line {$n}: account \"{$a['name']}\" is inactive.");
        }
        $totalDr += to_paisa($l['debit']);
        $totalCr += to_paisa($l['credit']);
        if ((int)$a['is_cash_bank'] === 1) {
            $cashDr = $cashDr || $l['debit'] > 0;
            $cashCr = $cashCr || $l['credit'] > 0;
        }
    }
    if ($totalDr !== $totalCr) {
        throw new VoucherException('Voucher is not balanced: total debit (' . money($totalDr / 100) . ') must equal total credit (' . money($totalCr / 100) . ').');
    }
    if ($totalDr <= 0) {
        throw new VoucherException('Voucher total must be greater than zero.');
    }
    if ($totalDr > 999999999999) {
        throw new VoucherException('Voucher total is too large.');
    }
    if ($type === 'RV' && !$cashDr) {
        throw new VoucherException('A receipt voucher must debit a Cash/Bank account.');
    }
    if ($type === 'PV' && !$cashCr) {
        throw new VoucherException('A payment voucher must credit a Cash/Bank account.');
    }

    $no = next_voucher_no($type, fiscal_year_of($date));
    db_exec("INSERT INTO vouchers (voucher_no, voucher_type, voucher_date, narration, total_amount, source_type, source_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [$no, $type, $date, $narration, $totalDr / 100, $sourceType, $sourceId === null ? null : (int)$sourceId, $user ? (int)$user['id'] : null]);
    $vid = db_insert_id();
    foreach ($clean as $l) {
        db_exec("INSERT INTO voucher_lines (voucher_id, account_id, debit, credit, line_narration) VALUES (?, ?, ?, ?, ?)",
            [$vid, $l['account_id'], $l['debit'], $l['credit'], $l['narration'] !== '' ? $l['narration'] : null]);
    }
    return ['id' => $vid, 'voucher_no' => $no];
}

/** Posted voucher ko Cancelled mark karta hai (delete kabhi nahi). true = cancel ho gaya. */
function cancel_voucher($voucherId, $reason, $user = null)
{
    $user = $user ?: current_user();
    return db_exec("UPDATE vouchers SET status = 'Cancelled', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ?
                    WHERE id = ? AND status = 'Posted'",
        [$user ? (int)$user['id'] : null, substr(trim((string)$reason), 0, 255), (int)$voucherId]) === 1;
}

/** Kisi record (fee payment, salary, expense...) ka current Posted voucher, ya null */
function voucher_for_source($sourceType, $sourceId)
{
    return db_row("SELECT * FROM vouchers WHERE source_type = ? AND source_id = ? AND status = 'Posted' ORDER BY id DESC LIMIT 1",
        [$sourceType, (int)$sourceId]);
}

/** Bootstrap badge color: RV green, PV red, JV blue */
function voucher_badge_class($type)
{
    $map = ['RV' => 'bg-success', 'PV' => 'bg-danger', 'JV' => 'bg-primary'];
    return $map[$type] ?? 'bg-secondary';
}

/* ======================= Trial Balance ======================= */

/**
 * Trial balance for a date range (sirf Posted vouchers). Sab amounts paisa (int) mein.
 *
 * Asset/Liability/Equity: opening = 'from' se pehle ka poora balance.
 * Income/Expense: har fiscal year zero se shuru hota hai - opening sirf usi FY ke shuru se 'from' tak.
 * Pichle saalon ka net surplus/deficit ek virtual Equity line "Accumulated Fund" mein jata hai
 * (year-end closing jaisa), is liye totals hamesha match karte hain.
 *
 * Returns ['rows' => [...tree rows with open, dr, cr, close...], 'totals' => [...6 columns...]]
 */
function trial_balance($from, $to)
{
    $fyStart = fiscal_year_range(fiscal_year_of($from))[0];
    $moves = [];
    $res = db_select(
        "SELECT vl.account_id,
                SUM(CASE WHEN v.voucher_date < ? THEN vl.debit - vl.credit ELSE 0 END) AS open_all,
                SUM(CASE WHEN v.voucher_date >= ? AND v.voucher_date < ? THEN vl.debit - vl.credit ELSE 0 END) AS open_fy,
                SUM(CASE WHEN v.voucher_date >= ? THEN vl.debit ELSE 0 END) AS dr,
                SUM(CASE WHEN v.voucher_date >= ? THEN vl.credit ELSE 0 END) AS cr
         FROM voucher_lines vl JOIN vouchers v ON v.id = vl.voucher_id
         WHERE v.status = 'Posted' AND v.voucher_date <= ?
         GROUP BY vl.account_id",
        [$from, $fyStart, $from, $from, $from, $to]);
    while ($m = $res->fetch_assoc()) {
        $moves[(int)$m['account_id']] = $m;
    }

    $rows = [];
    $prior = 0; // pichle saalon ka Income/Expense net (debit positive)
    $equityGroup = null;
    foreach (account_tree() as $a) {
        $m = $moves[(int)$a['id']] ?? null;
        $nominal = in_array($a['type'], ['Income', 'Expense'], true);
        $openAll = $m ? to_paisa($m['open_all']) : 0;
        $openFy  = $m ? to_paisa($m['open_fy']) : 0;
        $a['open'] = $nominal ? $openFy : $openAll;
        $a['dr'] = $m ? to_paisa($m['dr']) : 0;
        $a['cr'] = $m ? to_paisa($m['cr']) : 0;
        $a['virtual'] = false;
        if ($nominal) {
            $prior += $openAll - $openFy;
        }
        $rows[] = $a;
        if ($a['type'] === 'Equity' && (int)$a['is_group'] === 1 && !$a['parent_id']) {
            $equityGroup = $a;
        }
    }
    if ($prior !== 0) {
        $virtual = ['id' => 0, 'code' => '-', 'name' => 'Accumulated Fund (prior years surplus / deficit)', 'type' => 'Equity',
                    'parent_id' => $equityGroup ? (int)$equityGroup['id'] : 0, 'is_group' => 0, 'depth' => $equityGroup ? 1 : 0,
                    'open' => $prior, 'dr' => 0, 'cr' => 0, 'virtual' => true, 'status' => 'Active'];
        // Equity group ke aakhri child ke baad rakhein
        $pos = count($rows);
        foreach ($rows as $i => $r) {
            if ($r['type'] === 'Equity') {
                $pos = $i + 1;
            }
        }
        array_splice($rows, $pos, 0, [$virtual]);
    }

    $totals = array_fill_keys(['open_dr', 'open_cr', 'dr', 'cr', 'close_dr', 'close_cr'], 0);
    foreach ($rows as &$r) {
        $r['close'] = $r['open'] + $r['dr'] - $r['cr'];
        if ((int)$r['is_group'] === 0) {
            $totals['open_dr']  += max($r['open'], 0);
            $totals['open_cr']  += max(-$r['open'], 0);
            $totals['dr']       += $r['dr'];
            $totals['cr']       += $r['cr'];
            $totals['close_dr'] += max($r['close'], 0);
            $totals['close_cr'] += max(-$r['close'], 0);
        }
    }
    unset($r);

    // Group heads: children ke columns ka total
    $byId = [];
    foreach ($rows as $i => $r) {
        if ($r['id']) {
            $byId[(int)$r['id']] = $i;
        }
    }
    foreach ($rows as $i => $r) {
        if ((int)$r['is_group'] === 1) {
            $rows[$i] = array_merge($rows[$i], array_fill_keys(['g_open_dr', 'g_open_cr', 'g_dr', 'g_cr', 'g_close_dr', 'g_close_cr'], 0));
        }
    }
    foreach ($rows as $r) {
        if ((int)$r['is_group'] === 1) {
            continue;
        }
        for ($p = (int)$r['parent_id']; $p && isset($byId[$p]); $p = (int)$rows[$byId[$p]]['parent_id']) {
            $g = &$rows[$byId[$p]];
            $g['g_open_dr']  += max($r['open'], 0);
            $g['g_open_cr']  += max(-$r['open'], 0);
            $g['g_dr']       += $r['dr'];
            $g['g_cr']       += $r['cr'];
            $g['g_close_dr'] += max($r['close'], 0);
            $g['g_close_cr'] += max(-$r['close'], 0);
            unset($g);
        }
    }
    return ['rows' => $rows, 'totals' => $totals];
}

/* ======================= Amount in words ======================= */

function words_below_1000($n)
{
    static $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    static $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $parts = [];
    if ($n >= 100) {
        $parts[] = $ones[intdiv($n, 100)] . ' Hundred';
        $n %= 100;
    }
    if ($n >= 20) {
        $parts[] = $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
    } elseif ($n > 0) {
        $parts[] = $ones[$n];
    }
    return implode(' ', $parts);
}

/** Pakistani system: 12500000 -> "One Crore Twenty-Five Lakh" */
function integer_in_words($n)
{
    $n = (int)$n;
    if ($n === 0) {
        return 'Zero';
    }
    $parts = [];
    foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand'] as $unit => $label) {
        if ($n >= $unit) {
            $parts[] = integer_in_words(intdiv($n, $unit)) . ' ' . $label;
            $n %= $unit;
        }
    }
    if ($n > 0) {
        $parts[] = words_below_1000($n);
    }
    return implode(' ', $parts);
}

/** 1250.50 -> "Rupees One Thousand Two Hundred Fifty and Fifty Paisa Only" */
function amount_in_words($amount)
{
    $paisa = to_paisa($amount);
    $words = 'Rupees ' . integer_in_words(intdiv($paisa, 100));
    if ($paisa % 100) {
        $words .= ' and ' . integer_in_words($paisa % 100) . ' Paisa';
    }
    return $words . ' Only';
}
