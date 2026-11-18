<?php
/**
 * Budgets: approval workflow aur budget vs actual (utilization) helpers.
 * Workflow: Draft -> Submitted (F&PC) -> FPC Approved -> Syndicate Approved -> Senate Approved (active).
 * Koi bhi stage "Returned" kar sakta hai (phir edit ho kar dobara Submit). Sirf Senate-approved budget active.
 * Budget amounts sirf Income/Expense sub-heads par hote hain; fiscal year = July-June ($fy = start year).
 */

/** Har pending status par kaunsa forum faisla karta hai aur approve hone par agla status */
const BUDGET_STAGES = [
    'Submitted'          => ['stage' => 'F&PC',      'next' => 'FPC Approved'],
    'FPC Approved'       => ['stage' => 'Syndicate', 'next' => 'Syndicate Approved'],
    'Syndicate Approved' => ['stage' => 'Senate',    'next' => 'Senate Approved'],
];
const BUDGET_IN_PROGRESS = ['Draft', 'Submitted', 'FPC Approved', 'Syndicate Approved', 'Returned'];

/** Utilization alerts (sirf payment heads): 80% warning, 100% exceeded */
const BUDGET_WARN_PCT = 80;
const BUDGET_EXCEED_PCT = 100;

function budget_status_label($status)
{
    $map = [
        'Draft' => 'Draft', 'Submitted' => 'Submitted to F&PC', 'FPC Approved' => 'Approved by F&PC',
        'Syndicate Approved' => 'Approved by Syndicate', 'Senate Approved' => 'Approved by Senate (Final)',
        'Returned' => 'Returned for revision', 'Superseded' => 'Superseded by revision',
    ];
    return $map[$status] ?? $status;
}

function budget_status_badge($status)
{
    $map = ['Draft' => 'bg-secondary', 'Submitted' => 'bg-info text-dark', 'FPC Approved' => 'bg-primary', 'Syndicate Approved' => 'bg-primary',
            'Senate Approved' => 'bg-success', 'Returned' => 'bg-warning text-dark', 'Superseded' => 'bg-light text-muted border'];
    return $map[$status] ?? 'bg-secondary';
}

function budget_editable(array $budget)
{
    return in_array($budget['status'], ['Draft', 'Returned'], true);
}

/** Minutes PDFs ka folder (uploads/ ke andar, browser se band). BUDGET_MINUTES_DIR se badla ja sakta hai. */
function budget_minutes_dir()
{
    return defined('BUDGET_MINUTES_DIR') ? BUDGET_MINUTES_DIR : __DIR__ . '/../uploads/budget_minutes/';
}

/** Fiscal year ka active (Senate-approved) budget, ya null */
function active_budget($fy)
{
    return db_row("SELECT * FROM budgets WHERE fiscal_year = ? AND is_active = 1", [(int)$fy]);
}

/** Budget lines: [account_id => paisa] */
function budget_line_amounts($budgetId)
{
    $out = [];
    $res = db_select("SELECT account_id, amount FROM budget_lines WHERE budget_id = ?", [(int)$budgetId]);
    while ($r = $res->fetch_assoc()) {
        $out[(int)$r['account_id']] = to_paisa($r['amount']);
    }
    return $out;
}

/**
 * Budget sheet ke rows: Receipts aur Payments ke tree (group heads samet), code order mein.
 * Sub-heads: active wale + jo $keepIds mein hon (purane budget mein inactive head).
 */
function budget_sheet_rows(array $keepIds = [])
{
    $keep = array_flip(array_map('intval', $keepIds));
    $rows = [];
    foreach (account_tree() as $a) {
        if (!in_array($a['type'], ['Income', 'Expense'], true)) {
            continue;
        }
        if ((int)$a['is_group'] === 0 && $a['status'] !== 'Active' && !isset($keep[(int)$a['id']])) {
            continue;
        }
        $rows[] = $a;
    }
    return $rows;
}

/**
 * Fiscal year ka actual (Posted vouchers): [account_id => paisa], normal side positive
 * (Income = credit - debit, Expense = debit - credit).
 */
function budget_actuals($fy)
{
    list($from, $to) = fiscal_year_range($fy);
    $out = [];
    $res = db_select("SELECT vl.account_id, a.type, SUM(vl.debit) - SUM(vl.credit) AS net
                      FROM voucher_lines vl JOIN vouchers v ON v.id = vl.voucher_id JOIN accounts a ON a.id = vl.account_id
                      WHERE v.status = 'Posted' AND v.voucher_date BETWEEN ? AND ? AND a.type IN ('Income', 'Expense')
                      GROUP BY vl.account_id, a.type", [$from, $to]);
    while ($r = $res->fetch_assoc()) {
        $net = to_paisa($r['net']);
        $out[(int)$r['account_id']] = $r['type'] === 'Income' ? -$net : $net;
    }
    return $out;
}

/**
 * Budget vs actual har head ke liye (utilization report + dashboard alerts).
 * Returns rows (tree order, group subtotals) with budget, actual, remaining, pct, level:
 *   Expense: 'ok' | 'warn' (>= 80%) | 'exceeded' (>= 100%, ya bina budget ke kharch)
 *   Income:  'income' (sirf % realized)
 */
function budget_utilization($fy, $budget = null)
{
    $budget = $budget ?: active_budget($fy);
    $lines = $budget ? budget_line_amounts($budget['id']) : [];
    $actual = budget_actuals($fy);
    $rows = budget_sheet_rows(array_merge(array_keys($lines), array_keys($actual)));
    $byId = [];
    foreach ($rows as $i => $r) {
        $rows[$i]['budget'] = (int)$r['is_group'] ? 0 : ($lines[(int)$r['id']] ?? 0);
        $rows[$i]['actual'] = (int)$r['is_group'] ? 0 : ($actual[(int)$r['id']] ?? 0);
        $byId[(int)$r['id']] = $i;
    }
    // Group heads mein children ka total
    foreach ($rows as $r) {
        if ((int)$r['is_group']) continue;
        for ($p = (int)$r['parent_id']; $p && isset($byId[$p]); $p = (int)$rows[$byId[$p]]['parent_id']) {
            $rows[$byId[$p]]['budget'] += $r['budget'];
            $rows[$byId[$p]]['actual'] += $r['actual'];
        }
    }
    foreach ($rows as $i => $r) {
        $rows[$i]['remaining'] = $r['budget'] - $r['actual'];
        $rows[$i]['pct'] = $r['budget'] > 0 ? round($r['actual'] * 100 / $r['budget'], 1) : null;
        if ($r['type'] === 'Income') {
            $rows[$i]['level'] = 'income';
        } elseif ($r['budget'] <= 0) {
            $rows[$i]['level'] = $r['actual'] > 0 ? 'exceeded' : 'ok';
        } else {
            $pct = $r['actual'] * 100 / $r['budget'];
            $rows[$i]['level'] = $pct >= BUDGET_EXCEED_PCT ? 'exceeded' : ($pct >= BUDGET_WARN_PCT ? 'warn' : 'ok');
        }
    }
    return ['budget' => $budget, 'rows' => $rows];
}

/** Dashboard ke liye: current FY ke payment sub-heads jo 80% ya zyada use ho chuke (sab se zyada pehle) */
function budget_alerts($fy = null)
{
    $fy = $fy ?: fiscal_year_of(date('Y-m-d'));
    $u = budget_utilization($fy);
    $alerts = array_values(array_filter($u['rows'], function ($r) {
        return (int)$r['is_group'] === 0 && $r['type'] === 'Expense' && in_array($r['level'], ['warn', 'exceeded'], true);
    }));
    usort($alerts, function ($a, $b) {
        return ($b['pct'] ?? INF) <=> ($a['pct'] ?? INF);
    });
    return ['fy' => $fy, 'budget' => $u['budget'], 'alerts' => $alerts];
}
