<?php
/**
 * DEMO DATA for the FYP presentation (fiscal year 2026-27, July - October 2026).
 *
 *   /opt/lampp/bin/php database/demo_seed.php                    (DRY RUN - prints the summary, then rolls back)
 *   /opt/lampp/bin/php database/demo_seed.php --run              (real run - commits)
 *   ... --use-empty-draft   allow using an existing EMPTY draft budget of FY 2026-27 (the app allows only
 *                           one Original budget per fiscal year)
 *
 * Pehle backup lein (database/demo_reset.sh usi backup ko wapas restore karta hai).
 * - Sab kuch ek transaction mein; wahi functions jo live pages use karte hain (includes/postings.php,
 *   post_voucher) - is liye har voucher balanced hai aur Trial Balance barabar rehta hai.
 * - Koi email nahi jati: MAIL_ENABLED yahan false hai aur koi mail function call nahi hota.
 * - Purane (real) records ko nahi chherta: aakhir mein har table ke purane rows ka checksum compare hota hai.
 * - Demo accounts ke email "+demo." wale hain (example.com, koi asli inbox nahi); dobara chalane par inkaar.
 * - Demo password har run par random banta hai aur sirf screen par dikhta hai (file mein kahin nahi).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// config.php se pehle: seeding ke dauran email band
set_error_handler(function ($no, $str) { return strpos($str, 'already defined') !== false; });
if (!defined('MAIL_ENABLED')) define('MAIL_ENABLED', false);
$root = dirname(__DIR__) . '/includes';
require $root . '/config.php';
restore_error_handler();
date_default_timezone_set(APP_TIMEZONE);
require $root . '/db_connect.php';
require $root . '/functions.php';
require $root . '/accounting.php';
require $root . '/postings.php';
require $root . '/budgets.php';
require $root . '/security.php';
require $root . '/reminders.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$run = in_array('--run', $argv, true);
$useEmptyDraft = in_array('--use-empty-draft', $argv, true);
const DEMO_FY = 2026;
const DEMO_EMAIL = 'demo+demo.%s@example.com';
$today = date('Y-m-d');
mt_srand(20261014);   // har run par wahi data

function stop($msg) { fwrite(STDERR, "STOP: {$msg}\n"); exit(1); }
function pick(array $a) { return $a[mt_rand(0, count($a) - 1)]; }
function between($from, $to) { return date('Y-m-d', mt_rand(strtotime($from), strtotime($to))); }
function slug($name) { return strtolower(preg_replace('/[^a-z]+/i', '.', trim($name))); }

if (mail_configured()) stop('Email is still enabled - refusing to seed.');
if (fiscal_year_of($today) !== DEMO_FY || $today < '2026-10-01') stop('This demo data is written for October 2026 (FY 2026-27).');
if (db_value("SELECT COUNT(*) FROM users WHERE email LIKE '%+demo.%'", [], 0)) stop('Demo data is already loaded (run database/demo_reset.sh first).');
$admin = db_row("SELECT * FROM users WHERE role = 'Admin' AND status = 'Active' ORDER BY id LIMIT 1");
if (!$admin) stop('No active Admin user found.');

/* ---------- Snapshot of the existing (real) rows ---------- */
function table_snapshot()
{
    $snap = [];
    foreach (db_select("SHOW TABLES")->fetch_all(MYSQLI_NUM) as $t) {
        $t = $t[0];
        $count = (int)db_value("SELECT COUNT(*) FROM `{$t}`", [], 0);
        $hasId = (bool)db_value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'id'", [$t], 0);
        $snap[$t] = ['count' => $count, 'max' => $hasId ? (int)db_value("SELECT COALESCE(MAX(id), 0) FROM `{$t}`", [], 0) : null];
    }
    return $snap;
}
function old_rows_hash($table, $maxId)
{
    $res = db_select("SELECT * FROM `{$table}` WHERE id <= ? ORDER BY id", [(int)$maxId]);
    $h = hash_init('md5');
    while ($r = $res->fetch_assoc()) { hash_update($h, json_encode($r)); }
    return hash_final($h);
}
$before = table_snapshot();
$beforeHash = [];
foreach ($before as $t => $s) { if ($s['max'] !== null) $beforeHash[$t] = old_rows_hash($t, $s['max']); }

$password = generate_temp_password(12);
$hash = password_hash($password, PASSWORD_BCRYPT);
$logs = [];   // "a few activity logs": [datetime, action, details, user]
$db = db();
$db->begin_transaction();

/* ======================= 1. Programs ======================= */
$programs = [];
foreach ([['Software Engineering', 34000, 10000, 4000, 12000], ['Computer Science', 32000, 10000, 4000, 6000], ['Electrical Engineering', 38000, 12000, 5000, 9000]] as $p) {
    $id = db_value("SELECT id FROM fee_structure WHERE program_name = ?", [$p[0]]);
    if (!$id) {
        db_exec("INSERT INTO fee_structure (program_name, tuition_fee, admission_fee, exam_fee, other_charges) VALUES (?, ?, ?, ?, ?)", $p);
        $id = db_insert_id();
    }
    $programs[] = ['id' => (int)$id, 'name' => $p[0], 'code' => ['Software Engineering' => 'SE', 'Computer Science' => 'CS', 'Electrical Engineering' => 'EE'][$p[0]]];
}

/* ======================= 2. Users: accountant, staff, students ======================= */
$usedEmails = [];
function demo_email($name, $tag)
{
    global $usedEmails;
    $e = sprintf(DEMO_EMAIL, slug($name) . '.' . $tag);
    if (isset($usedEmails[$e])) stop("Duplicate demo email {$e}");
    return $usedEmails[$e] = $e;
}
function demo_user($name, $email, $role, $hash, array $extra = [])
{
    $cols = array_merge(['full_name' => $name, 'email' => $email, 'password' => $hash, 'role' => $role, 'status' => 'Active', 'must_change_password' => 0,
                         'accepted_policy_at' => in_array($role, POLICY_ROLES, true) ? date('Y-m-d H:i:s') : null], $extra);
    db_exec("INSERT INTO users (" . implode(', ', array_keys($cols)) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")", array_values($cols));
    return db_insert_id();
}

// Accountant (sab permissions) - demo ke sab finance kaam isi ke naam se
$acc_email = demo_email('Sana Gul', 'accounts');
$acc_id = demo_user('Sana Gul', $acc_email, 'Accountant', $hash, ['phone' => '0300-0000000', 'employee_id' => 'EMP-D100',
    'can_manage_fees' => 1, 'can_manage_salaries' => 1, 'can_manage_expenses' => 1, 'can_manage_accounts' => 1]);
$acc = db_row("SELECT * FROM users WHERE id = ?", [$acc_id]);

// 15 staff: 8 Regular, 3 Contract, 2 Visiting, 2 Daily Wages. [name, designation, type, monthly base | rate]
$staff_plan = [
    ['Dr. Farooq Ahmad',     'Professor (Computer Science)',        'Regular',     185000],
    ['Dr. Saima Bibi',       'Associate Professor (Software Engg.)', 'Regular',     150000],
    ['Dr. Imran Khattak',    'Assistant Professor (Electrical)',    'Regular',     120000],
    ['Engr. Bilal Wazir',    'Lecturer (Electrical Engineering)',   'Regular',      95000],
    ['Ms. Hira Marwat',      'Lecturer (Computer Science)',         'Regular',      95000],
    ['Engr. Asad Ullah',     'Lab Engineer',                        'Regular',      80000],
    ['Mr. Zahid Mehmood',    'Assistant Registrar',                 'Regular',      70000],
    ['Mr. Rahim Dad',        'Office Assistant',                    'Regular',      48000],
    ['Ms. Nadia Rehman',     'Lecturer (Contract)',                 'Contract',     75000],
    ['Mr. Adnan Afridi',     'Network Administrator (Contract)',    'Contract',     60000],
    ['Mr. Waqar Shah',       'Junior Clerk (Contract)',             'Contract',     45000],
    ['Dr. Tariq Bangash',    'Visiting Faculty (Mathematics)',      'Visiting',      2000],
    ['Ms. Mehwish Durrani',  'Visiting Faculty (English)',          'Visiting',      1800],
    ['Gul Zaman',            'Gardener',                            'Daily Wages',   1300],
    ['Sher Afzal',           'Security Guard',                      'Daily Wages',   1400],
];
$units_plan = ['Visiting' => [[24, 32, 36, 36], [20, 30, 34, 34]], 'Daily Wages' => [[26, 25, 26, 26], [27, 26, 25, 26]]];
$staff = [];
$typeSeen = [];
foreach ($staff_plan as $i => $s) {
    list($name, $desig, $type, $pay) = $s;
    $monthly = EMPLOYMENT_TYPES[$type] === 'Monthly';
    $no = sprintf('EMP-D%03d', 101 + $i);
    $email = demo_email(preg_replace('/^(Dr|Engr|Mr|Ms)\.\s+/', '', $name), 'staff');
    $uid = demo_user($name, $email, 'Staff', $hash);
    db_exec("INSERT INTO staff (user_id, staff_id_number, designation, employment_type, base_salary, pay_rate) VALUES (?, ?, ?, ?, ?, ?)",
        [$uid, $no, $desig, $type, $monthly ? $pay : 0, $monthly ? 0 : $pay]);
    $k = $typeSeen[$type] = ($typeSeen[$type] ?? -1) + 1;
    $staff[] = ['id' => db_insert_id(), 'user_id' => $uid, 'name' => $name, 'email' => $email, 'no' => $no, 'designation' => $desig, 'type' => $type, 'pay' => $pay,
                'units' => $monthly ? null : $units_plan[$type][$k]];
}

// 60 students: 20 per program, semesters 1-8
$first_m = ['Ahmad', 'Hamza', 'Usman', 'Bilal', 'Zeeshan', 'Fahad', 'Saad', 'Haris', 'Junaid', 'Shahzaib', 'Waleed', 'Talha', 'Arsalan', 'Danish', 'Faizan', 'Irfan', 'Kamran', 'Noman', 'Owais', 'Rizwan',
            'Salman', 'Tahir', 'Umair', 'Yasir', 'Zubair', 'Adeel', 'Basit', 'Daniyal', 'Ehsan', 'Furqan', 'Hassan', 'Jawad', 'Luqman', 'Mubashir', 'Naveed', 'Qasim', 'Rehan', 'Sohail', 'Taimoor', 'Wajid'];
$first_f = ['Ayesha', 'Fatima', 'Maryam', 'Zainab', 'Hafsa', 'Iqra', 'Sadia', 'Rabia', 'Sana', 'Mahnoor', 'Laiba', 'Kainat', 'Amna', 'Bushra', 'Farah', 'Hina', 'Javeria', 'Nimra', 'Sidra', 'Uzma'];
$last = ['Khan', 'Khattak', 'Wazir', 'Marwat', 'Afridi', 'Yousafzai', 'Bangash', 'Shah', 'Durrani', 'Orakzai', 'Mehsud', 'Dawar', 'Gul', 'Rehman', 'Ali', 'Hussain', 'Iqbal', 'Awan', 'Qureshi', 'Jan'];
$names = [];
while (count($names) < 60) {
    $n = (count($names) % 3 === 2 ? pick($first_f) : pick($first_m)) . ' ' . pick($last);
    $names[$n] = true;
}
$names = array_keys($names);
$scholar = [3 => 15000, 9 => 10000, 14 => 20000, 21 => 8000, 27 => 12000, 33 => 15000, 40 => 5000, 46 => 10000, 52 => 18000, 57 => 7500];   // student index => scholarship
$students = [];
foreach ($names as $i => $name) {
    $prog = $programs[$i % 3];
    $sem = (int)(floor($i / 3) % 8) + 1;
    $intake = 2026 - (int)floor(($sem - 1) / 2);
    $reg = sprintf('%d-USTB-%s-%03d', $intake, $prog['code'], 101 + $i);
    $email = demo_email($name, sprintf('s%02d', $i + 1));
    $uid = demo_user($name, $email, 'Student', $hash);
    db_exec("INSERT INTO students (user_id, reg_number, program_id, semester, scholarship_discount) VALUES (?, ?, ?, ?, ?)", [$uid, $reg, $prog['id'], $sem, $scholar[$i] ?? 0]);
    $students[] = ['id' => db_insert_id(), 'user_id' => $uid, 'full_name' => $name, 'email' => $email, 'reg_number' => $reg, 'program_id' => $prog['id'],
                   'program' => $prog['name'], 'semester' => $sem, 'scholarship_discount' => $scholar[$i] ?? 0];
}

/* ======================= 3. Plan the transactions (date order) ======================= */
$events = [];   // [date, order, callable]
$seq = 0;
$add = function ($date, callable $fn) use (&$events, &$seq, $today) {
    $events[] = [min($date, $today), $seq++, $fn];
};

// ---- Fees: 36 paid, 15 partial, 9 unpaid; 8 challans with a late fine ----
$status_plan = array_merge(array_fill(0, 36, 'Paid'), array_fill(0, 15, 'Partial'), array_fill(0, 9, 'Unpaid'));
shuffle($status_plan);
$fined = [];
foreach (array_keys($status_plan) as $i) {
    if ($status_plan[$i] !== 'Unpaid' && count($fined) < 8 && $i % 6 === 1) $fined[$i] = pick([1000, 1500, 2000]);
}
$fee_stats = ['Paid' => 0, 'Partial' => 0, 'Unpaid' => 0, 'payments' => 0, 'installments' => 0];
$fee_logs = [];
foreach ($students as $i => $st) {
    $status = $status_plan[$i];
    $fine = $fined[$i] ?? 0;
    $fee = semester_fee_breakdown($st, $st['semester']);
    $split = fee_challan_split($fee);
    $challan = sprintf('CH-2627-%04d', 101 + $i);
    $payable = $fee['total'] + $fine;
    $d1 = $fine ? between('2026-09-21', '2026-10-03') : between('2026-07-13', '2026-09-18');   // fine = der se jama
    $two = $status === 'Paid' && !$fine && $i % 3 === 0;                                        // do qiston mein
    if ($status === 'Paid')        $pay1 = $two ? round($payable * 0.5, -3) : $payable;
    elseif ($status === 'Partial') $pay1 = round($payable * pick([0.3, 0.4, 0.5, 0.6, 0.7]), -3);
    else                           $pay1 = 0;
    $fee_stats[$status]++;

    // Naya challan (+ pehli payment) - collect_fee.php "save_fee" jaisa
    $add($pay1 > 0 ? $d1 : between('2026-07-13', '2026-08-15'), function () use ($st, $challan, $fee, $split, $fine, $pay1, $d1, $acc, &$students, $i, &$fee_stats, &$fee_logs) {
        db_exec("INSERT INTO fees_records (student_id, challan_number, semester_no, total_fee, tuition_amt, admission_amt, exam_amt, other_amt,
                                           late_fee_fine, amount_paid, paid_status, payment_date, collected_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$st['id'], $challan, $st['semester'], $fee['total'], $split['tuition'], $split['admission'], $split['exam'], $split['other'],
             $fine, $pay1, fee_status($fee['total'], $fine, $pay1), $pay1 > 0 ? $d1 : null, (int)$acc['id']]);
        $students[$i]['fee_id'] = db_insert_id();
        if ($pay1 > 0) {
            $rec = db_row("SELECT * FROM fees_records WHERE id = ?", [$students[$i]['fee_id']]);
            $v = record_fee_payment($rec, $pay1, $d1, $st, $acc);
            $fee_stats['payments']++;
            $fee_logs[] = [$d1 . ' 11:' . sprintf('%02d', 10 + $i % 45) . ':00', 'FEE_COLLECTED', "Challan {$challan}: PKR " . money($pay1) . " from {$st['full_name']} ({$st['reg_number']}), Semester {$st['semester']}, {$v['voucher_no']}"];
        }
    });
    if ($two) {
        // Baqi raqam usi challan par - collect_fee.php "add_payment" jaisa
        $d2 = date('Y-m-d', min(strtotime($d1 . ' +' . mt_rand(12, 30) . ' days'), strtotime('2026-10-03')));
        $add($d2, function () use ($st, $payable, $pay1, $d2, $acc, &$students, $i, &$fee_stats, &$fee_logs, $challan) {
            $rec = db_row("SELECT * FROM fees_records WHERE id = ? FOR UPDATE", [$students[$i]['fee_id']]);
            $amount = round($payable - $pay1, 2);
            $new_paid = round((float)$rec['amount_paid'] + $amount, 2);
            db_exec("UPDATE fees_records SET amount_paid = ?, paid_status = ?, payment_date = ?, collected_by = ? WHERE id = ?",
                [$new_paid, fee_status($rec['total_fee'], $rec['late_fee_fine'], $new_paid), $d2, (int)$acc['id'], (int)$rec['id']]);
            $v = record_fee_payment($rec, $amount, $d2, $st, $acc);
            $fee_stats['payments']++;
            $fee_stats['installments']++;
            $fee_logs[] = [$d2 . ' 12:' . sprintf('%02d', 10 + $i % 45) . ':00', 'FEE_COLLECTED', "Challan {$challan}: PKR " . money($amount) . " received from {$st['full_name']} ({$st['reg_number']}), {$v['voucher_no']}"];
        });
    }
}

// ---- Salaries: July - September paid (1st of next month), October pending ----
$months = ['2026-07' => '2026-08-01', '2026-08' => '2026-09-01', '2026-09' => '2026-10-01', '2026-10' => null];
$sal_logs = [];
$sal_stats = ['Paid' => 0, 'Pending' => 0];
foreach ($staff as $s) {
    $mi = 0;
    foreach ($months as $month => $payDate) {
        if ($s['units'] === null) {            // Regular / Contract: monthly
            $base = $s['pay'];
            $allow = round($base * ($s['type'] === 'Regular' ? 0.25 : 0.10), -2);
            $ded = round(($base + $allow) * ($s['type'] === 'Regular' ? 0.06 : 0.03), -2);
            $units = $rate = null;
        } else {                               // Visiting (hours) / Daily Wages (days)
            $units = $s['units'][$mi];
            $rate = $s['pay'];
            $base = round($units * $rate, 2);
            $allow = $ded = 0;
        }
        $mi++;
        // Bill banana - manage_salaries.php "generate" jaisa
        $add($month . '-25', function () use ($s, $month, $units, $rate, $base, $allow, $ded, $acc, $payDate, $add, &$sal_logs, &$sal_stats) {
            db_exec("INSERT INTO staff_salaries (staff_id, month_year, designation, employment_type, units, rate, base_salary, allowances, deductions, status, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)",
                [$s['id'], $month, $s['designation'], $s['type'], $units, $rate, $base, $allow, $ded, (int)$acc['id']]);
            $sid = db_insert_id();
            $sal_stats['Pending']++;
            if ($payDate === null) return;
            // Pay - manage_salaries.php "pay" jaisa (usi din ke events ke baad chalta hai)
            $add($payDate, function () use ($sid, $payDate, $acc, $month, &$sal_logs, &$sal_stats) {
                $rec = db_row("SELECT ss.*, u.full_name, st.staff_id_number FROM staff_salaries ss JOIN staff st ON st.id = ss.staff_id JOIN users u ON u.id = st.user_id
                               WHERE ss.id = ? FOR UPDATE", [$sid]);
                db_exec("UPDATE staff_salaries SET status = 'Paid', paid_date = ? WHERE id = ?", [$payDate, $sid]);
                $v = post_salary_voucher($rec, $payDate, $acc);
                $sal_stats['Paid']++;
                $sal_stats['Pending']--;
                $sal_logs[] = [$payDate . ' 10:' . sprintf('%02d', count($sal_logs) % 60) . ':00', 'SALARY_PAID', "{$rec['full_name']} - " . month_label($month) . ' PKR ' . money($rec['net_salary']) . ", {$v['voucher_no']}"];
            });
        });
    }
}

// ---- 40 expenses ----
$expenses = [
    ['EXP_ELECTRICITY', '2026-07-12', 486300, 'PESCO bill June 2026 - Main Campus'], ['EXP_ELECTRICITY', '2026-07-12', 128450, 'PESCO bill June 2026 - Hostels'],
    ['EXP_ELECTRICITY', '2026-08-11', 534900, 'PESCO bill July 2026 - Main Campus'], ['EXP_ELECTRICITY', '2026-08-11', 141200, 'PESCO bill July 2026 - Hostels'],
    ['EXP_ELECTRICITY', '2026-09-10', 512750, 'PESCO bill August 2026 - Main Campus'], ['EXP_ELECTRICITY', '2026-09-10', 133600, 'PESCO bill August 2026 - Hostels'],
    ['EXP_ELECTRICITY', '2026-07-25', 52000, 'LED tube lights for the Academic Block'], ['EXP_ELECTRICITY', '2026-08-22', 64500, 'Replacement of transformer fuses and cables'],
    ['EXP_ELECTRICITY', '2026-09-18', 38200, 'Repair of generator AVR and wiring'],
    ['EXP_POL', '2026-07-05', 214000, 'Diesel for student buses - July 2026'], ['EXP_POL', '2026-08-05', 226500, 'Diesel for student buses - August 2026'],
    ['EXP_POL', '2026-09-05', 231800, 'Diesel for student buses - September 2026'], ['EXP_POL', '2026-07-20', 96400, 'Diesel for standby generators - July 2026'],
    ['EXP_POL', '2026-08-20', 104700, 'Diesel for standby generators - August 2026'], ['EXP_POL', '2026-09-20', 88900, 'Diesel for standby generators - September 2026'],
    ['EXP_POL', '2026-07-28', 41300, 'Petrol for official vehicles - July 2026'], ['EXP_POL', '2026-08-28', 44850, 'Petrol for official vehicles - August 2026'],
    ['EXP_POL', '2026-09-28', 46200, 'Petrol for official vehicles - September 2026'],
    ['EXP_PURCHASING', '2026-07-08', 68400, 'Stationery for the Examination Section'], ['EXP_PURCHASING', '2026-07-22', 54750, 'Printer toners and photocopy paper'],
    ['EXP_PURCHASING', '2026-08-04', 187300, 'Laboratory glassware and chemicals'], ['EXP_PURCHASING', '2026-08-18', 142600, 'Library books - Computer Science'],
    ['EXP_PURCHASING', '2026-08-27', 58200, 'Sports goods for the Directorate of Sports'], ['EXP_PURCHASING', '2026-09-02', 198000, '30 classroom chairs and 6 whiteboards'],
    ['EXP_PURCHASING', '2026-09-15', 1150000, '10 desktop computers for the Software Engineering lab'], ['EXP_PURCHASING', '2026-09-24', 164500, 'Network switches and cabling'],
    ['EXP_PURCHASING', '2026-10-01', 36900, 'Stationery for the Admissions Office'],
    ['EXP_REPAIRING', '2026-07-15', 74300, 'Repair of water supply line - Boys Hostel'], ['EXP_REPAIRING', '2026-07-30', 212000, 'Whitewash and paint - Admin Block'],
    ['EXP_REPAIRING', '2026-08-09', 86500, 'Air-conditioner servicing and gas refill'], ['EXP_REPAIRING', '2026-08-25', 168400, 'Engine overhaul of student bus BU-1142'],
    ['EXP_REPAIRING', '2026-09-08', 245000, 'Roof waterproofing - Central Library'], ['EXP_REPAIRING', '2026-09-19', 57800, 'Repair of laboratory equipment'],
    ['EXP_REPAIRING', '2026-09-29', 43600, 'Plumbing and sanitary repair - washrooms'], ['EXP_REPAIRING', '2026-10-02', 39500, 'Repair of classroom furniture'],
    ['EXP_OTHER', '2026-07-18', 135000, 'Internet bandwidth (PERN) - July 2026'], ['EXP_OTHER', '2026-08-18', 135000, 'Internet bandwidth (PERN) - August 2026'],
    ['EXP_OTHER', '2026-09-18', 135000, 'Internet bandwidth (PERN) - September 2026'], ['EXP_OTHER', '2026-08-14', 62300, 'Independence Day function arrangements'],
    ['EXP_OTHER', '2026-09-12', 118000, 'Advertisement - Fall 2026 admissions'],
];
$exp_logs = [];
foreach ($expenses as $n => $x) {
    // manage_expenses.php "add" jaisa
    $add($x[1], function () use ($x, $acc, $n, &$exp_logs) {
        $aid = account_id($x[0]);
        db_exec("INSERT INTO expenses (title, account_id, amount, expense_date, description, receipt, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$x[3], $aid, $x[2], $x[1], 'Bill verified by the Finance Office', null, (int)$acc['id']]);
        $v = post_expense_voucher(db_row("SELECT * FROM expenses WHERE id = ?", [db_insert_id()]), $acc);
        $head = db_value("SELECT name FROM accounts WHERE id = ?", [$aid]);
        $exp_logs[] = [$x[1] . ' 14:' . sprintf('%02d', 5 + $n) . ':00', 'EXPENSE_ADDED', "{$x[3]} ({$head}) PKR " . money($x[2]) . ", {$v['voucher_no']}"];
    });
}

// ---- Grants (2 HEC, 1 HED) and 25 student charges - receipts_page.php jaisa ----
$receipt = function ($kind, $key, $studentId, $amount, $date, $ref, $desc) use ($acc) {
    db_exec("INSERT INTO other_receipts (receipt_kind, account_id, student_id, amount, receipt_date, reference_no, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [$kind, account_id($key), $studentId, $amount, $date, $ref, $desc, (int)$acc['id']]);
    $r = db_row("SELECT r.*, a.name AS head_name, u.full_name, s.reg_number FROM other_receipts r JOIN accounts a ON a.id = r.account_id
                 LEFT JOIN students s ON s.id = r.student_id LEFT JOIN users u ON u.id = s.user_id WHERE r.id = ?", [db_insert_id()]);
    return post_other_receipt_voucher($r, $acc);
};
foreach ([['GRANT_HEC', 25000000, '2026-07-15', 'HEC/FIN/2026-27/RG-118', 'Recurring grant, 1st quarter FY 2026-27'],
          ['GRANT_HED', 12000000, '2026-08-20', 'HED-KP/B&A/2026/3-27', 'Grant-in-aid, 1st instalment FY 2026-27'],
          ['GRANT_HEC', 8500000, '2026-09-10', 'HEC/P&D/2026-27/DG-064', 'Development grant: laboratory upgradation']] as $g) {
    $add($g[2], function () use ($g, $receipt) { $receipt('Grant', $g[0], null, $g[1], $g[2], $g[3], $g[4]); });
}
$charge_plan = array_merge(array_fill(0, 8, ['FEE_HOSTEL', 18000, 'Hostel fee, Fall 2026']), array_fill(0, 8, ['FEE_TRANSPORT', 6000, 'Bus pass, Fall 2026']),
                           array_fill(0, 4, ['FEE_DEGREE', 5000, 'Degree fee']), array_fill(0, 5, ['FEE_DMC', 1500, 'Detailed marks certificate']));
$final_year = array_values(array_filter($students, function ($s) { return $s['semester'] >= 7; }));
foreach ($charge_plan as $n => $c) {
    $st = in_array($c[0], ['FEE_DEGREE', 'FEE_DMC'], true) ? $final_year[$n % count($final_year)] : $students[($n * 7 + 2) % 60];
    $date = between('2026-07-20', '2026-10-02');
    $add($date, function () use ($c, $st, $date, $n, $receipt) { $receipt('Student Charge', $c[0], $st['id'], $c[1], $date, sprintf('SC-2627-%03d', 101 + $n), $c[2]); });
}

/* ======================= 4. Post everything in date order ======================= */
// (pay events are added while running, so keep sorting until the queue is empty)
$done = 0;
while ($events) {
    usort($events, function ($a, $b) { return [$a[0], $a[1]] <=> [$b[0], $b[1]]; });
    $ev = array_shift($events);
    $ev[2]();
    $done++;
}

/* ======================= 5. Budgets ======================= */
$budget_note = '';
$existing = db_row("SELECT b.*, (SELECT COUNT(*) FROM budget_lines bl WHERE bl.budget_id = b.id) AS n_lines,
                           (SELECT COUNT(*) FROM budget_approvals ba WHERE ba.budget_id = b.id) AS n_hist
                    FROM budgets b WHERE b.fiscal_year = ? AND b.budget_type = 'Original'", [DEMO_FY]);
$bid = null;
if (!$existing) {
    // budgets.php "create_budget" jaisa
    db_exec("INSERT INTO budgets (fiscal_year, budget_type, title, created_by) VALUES (?, 'Original', ?, ?)", [DEMO_FY, 'Budget Estimates FY ' . fiscal_year_label(DEMO_FY), (int)$acc['id']]);
    $bid = db_insert_id();
} elseif ($existing['status'] === 'Draft' && !(int)$existing['n_lines'] && !(int)$existing['n_hist']) {
    if ($useEmptyDraft) {
        $bid = (int)$existing['id'];
        $budget_note = "Used your existing EMPTY draft budget #{$bid} \"{$existing['title']}\" (the app allows one Original budget per fiscal year).";
    } else {
        $budget_note = "SKIPPED the budget: FY " . fiscal_year_label(DEMO_FY) . " already has an empty draft (#{$existing['id']}). Re-run with --use-empty-draft to fill and approve that draft.";
    }
} else {
    $budget_note = "SKIPPED the budget: FY " . fiscal_year_label(DEMO_FY) . " already has budget #{$existing['id']} ({$existing['status']}, {$existing['n_lines']} lines).";
}
$budget_levels = [];
if ($bid) {
    $actual = budget_actuals(DEMO_FY);   // paisa, demo + real vouchers
    $fixed = ['GRANT_HEC' => 110000000, 'GRANT_HED' => 45000000, 'FEE_TUITION' => 9500000, 'FEE_ADMISSION' => 900000, 'FEE_EXAM' => 1100000, 'FEE_OTHER' => 2200000,
              'FEE_FINE' => 150000, 'FEE_HOSTEL' => 1800000, 'FEE_TRANSPORT' => 700000, 'FEE_REPEAT' => 200000, 'FEE_SUMMER' => 400000, 'FEE_DMC' => 80000,
              'FEE_DEGREE' => 250000, 'FEE_VERIFICATION' => 60000, 'FEE_MIGRATION' => 40000,
              'SAL_REGULAR' => 13500000, 'SAL_CONTRACT' => 2600000, 'SAL_VISITING' => 1600000, 'SAL_DAILY' => 900000, 'EXP_PURCHASING' => 6500000, 'EXP_OTHER' => 2400000];
    $target = ['EXP_ELECTRICITY' => 0.88, 'EXP_POL' => 0.84, 'EXP_REPAIRING' => 1.08];   // 2 Warning + 1 Exceeded
    $lines = [];
    foreach (budget_sheet_rows() as $r) {
        if ((int)$r['is_group']) continue;
        $key = (string)$r['system_key'];
        $act = ($actual[(int)$r['id']] ?? 0) / 100;
        if (isset($target[$key])) {
            $amt = round($act / $target[$key], -4);
            $pct = $act / $amt * 100;
            if (($target[$key] < 1 && ($pct < 81 || $pct > 98)) || ($target[$key] > 1 && $pct < 102)) stop("Budget for {$key} misses its target ({$pct}%).");
            $budget_levels[$r['name']] = round($pct, 1);
        } elseif (isset($fixed[$key])) {
            $amt = $fixed[$key];
            while ($r['type'] === 'Expense' && $act > $amt * 0.7) $amt = round($amt * 1.5, -5);   // baqi payment heads Warning se neeche
        } else {
            continue;   // Admin ke apne banaye heads: budget nahi
        }
        $lines[(int)$r['id']] = $amt;
    }
    // budget_detail.php: save_lines -> submit -> F&PC -> Syndicate -> Senate
    $title = 'Budget Estimates FY ' . fiscal_year_label(DEMO_FY);
    db_exec("UPDATE budgets SET title = ?, remarks = ? WHERE id = ?", [$title, 'Annual budget estimates as recommended by the Finance & Planning Committee.', $bid]);
    db_exec("DELETE FROM budget_lines WHERE budget_id = ?", [$bid]);
    foreach ($lines as $aid => $amt) {
        db_exec("INSERT INTO budget_lines (budget_id, account_id, amount) VALUES (?, ?, ?)", [$bid, $aid, $amt]);
    }
    db_exec("UPDATE budgets SET status = 'Submitted' WHERE id = ?", [$bid]);
    db_exec("INSERT INTO budget_approvals (budget_id, stage, action, remarks, recorded_by) VALUES (?, 'F&PC', 'Submitted', ?, ?)", [$bid, 'Submitted for consideration of the F&PC.', (int)$acc['id']]);
    foreach ([['Submitted', '2026-06-12', 'F&PC/41/2026-07'], ['FPC Approved', '2026-06-22', 'SYN/63/2026-04'], ['Syndicate Approved', '2026-06-29', 'SEN/29/2026-02']] as $step) {
        $stage = BUDGET_STAGES[$step[0]];
        db_exec("INSERT INTO budget_approvals (budget_id, stage, action, meeting_date, reference_no, minutes_file, remarks, recorded_by) VALUES (?, ?, 'Approved', ?, ?, NULL, ?, ?)",
            [$bid, $stage['stage'], $step[1], $step[2], 'Approved as presented.', (int)$admin['id']]);
        if ($stage['next'] === 'Senate Approved') {
            db_exec("UPDATE budgets SET status = 'Senate Approved', is_active = 1 WHERE id = ?", [$bid]);
        } else {
            db_exec("UPDATE budgets SET status = ? WHERE id = ?", [$stage['next'], $bid]);
        }
    }
    // Revised budget (active ki copy, 3 heads barhaye) - F&PC ke paas pending. budgets.php "revise_id" + submit jaisa
    $rtitle = 'Revised Budget FY ' . fiscal_year_label(DEMO_FY);
    db_exec("INSERT INTO budgets (fiscal_year, budget_type, revision_of, title, remarks, created_by) VALUES (?, 'Revised', ?, ?, ?, ?)",
        [DEMO_FY, $bid, $rtitle, 'Revision for higher electricity, fuel and repair costs.', (int)$acc['id']]);
    $rid = db_insert_id();
    db_exec("INSERT INTO budget_lines (budget_id, account_id, amount, remarks) SELECT ?, account_id, amount, remarks FROM budget_lines WHERE budget_id = ?", [$rid, $bid]);
    foreach (['EXP_ELECTRICITY' => 1.5, 'EXP_POL' => 1.4, 'EXP_REPAIRING' => 1.8] as $key => $f) {
        db_exec("UPDATE budget_lines SET amount = ROUND(amount * ?, -4) WHERE budget_id = ? AND account_id = ?", [$f, $rid, account_id($key)]);
    }
    db_exec("UPDATE budgets SET status = 'Submitted' WHERE id = ?", [$rid]);
    db_exec("INSERT INTO budget_approvals (budget_id, stage, action, remarks, recorded_by) VALUES (?, 'F&PC', 'Submitted', ?, ?)", [$rid, 'Revised estimates submitted to the F&PC.', (int)$acc['id']]);
    $logs[] = ['2026-10-03 10:20:00', 'BUDGET_CREATED', "{$rtitle} (revision of #{$bid})", $acc];
    $logs[] = ['2026-10-03 10:45:00', 'BUDGET_SUBMITTED', "{$rtitle} submitted to F&PC", $acc];
}

/* ======================= 6. Two fee reminders (dashboard only - no email) ======================= */
// Sirf demo programs (Computer Science, Electrical Engineering) ko, taake real students ke dashboard par kuch na badle
$rem_count = 0;
foreach ([[$programs[1], '2026-09-28', '2026-10-12', 'Fee payment reminder'], [$programs[2], '2026-10-03', '2026-10-15', 'Last date: Fall 2026 semester fee']] as $n => $rm) {
    $d = ['type' => 'Fee', 'title' => $rm[3], 'audience' => 'Students', 'fee_scope' => 'Filter', 'target_program_id' => $rm[0]['id'], 'target_semester' => null, 'target_student_id' => null,
          'due_date' => $rm[2], 'end_date' => null, 'is_pinned' => $n, 'email' => 0,
          'body' => "Dear {name},\nPKR {balance} is pending against your registration number {reg_no}. Please pay by {due_date} to avoid a late fine."];
    $recipients = reminder_recipients($d);
    db_exec("INSERT INTO announcements (type, title, body, audience, fee_scope, target_program_id, target_semester, target_student_id,
                                        start_date, end_date, due_date, is_pinned, email_requested, recipients_count, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$d['type'], $d['title'], $d['body'], $d['audience'], $d['fee_scope'], $d['target_program_id'], null, null, $rm[1], null, $d['due_date'], $d['is_pinned'], 0, count($recipients), (int)$acc['id']]);
    $aid = db_insert_id();
    reminder_save_recipients($aid, $recipients);
    // Bhejne ki tareekh reminder wali (warna 24-ghante wala duplicate guard demo ke din lag jata)
    db_exec("UPDATE reminder_recipients SET created_at = ? WHERE reminder_id = ?", [$rm[1] . ' 09:30:00', $aid]);
    $rem_count++;
    $logs[] = [$rm[1] . ' 09:30:00', 'REMINDER_SENT', "\"{$d['title']}\" (Fee; Students with dues: {$rm[0]['name']}): " . count($recipients) . ' students, dashboard only', $acc];
}

/* ======================= 7. A few activity logs (latest actions only) ======================= */
foreach ([$fee_logs, $exp_logs, $sal_logs] as $set) {
    usort($set, function ($a, $b) { return strcmp($a[0], $b[0]); });
    foreach (array_slice($set, -4) as $l) { $logs[] = [$l[0], $l[1], $l[2], $acc]; }
}
usort($logs, function ($a, $b) { return strcmp($a[0], $b[0]); });
foreach ($logs as $l) {
    audit($l[1], $l[2], $l[3]);
    db_exec("UPDATE audit_logs SET created_at = ? WHERE id = ?", [min($l[0], date('Y-m-d H:i:s')), db_insert_id()]);
}

/* ======================= 8. Checks + summary ======================= */
$after = table_snapshot();
$changed = [];
foreach ($beforeHash as $t => $h) {
    if ($before[$t]['max'] > 0 && old_rows_hash($t, $before[$t]['max']) !== $h) $changed[] = $t;
}
$unbalanced = (int)db_value("SELECT COUNT(*) FROM (SELECT v.id FROM vouchers v JOIN voucher_lines vl ON vl.voucher_id = v.id GROUP BY v.id HAVING SUM(vl.debit) <> SUM(vl.credit)) x", [], 0);
$tb = trial_balance(fiscal_year_range(DEMO_FY)[0], $today)['totals'];
$challans = db_row("SELECT SUM(paid_status = 'Paid') p, SUM(paid_status = 'Partial') pa, SUM(paid_status = 'Unpaid') u, SUM(late_fee_fine > 0) f FROM fees_records WHERE id > ?", [$before['fees_records']['max']]);
$bank = db_value("SELECT SUM(vl.debit) - SUM(vl.credit) FROM voucher_lines vl JOIN vouchers v ON v.id = vl.voucher_id WHERE v.status = 'Posted' AND vl.account_id = ?", [account_id('BANK')], 0);

echo ($run ? "REAL RUN" : "DRY RUN (nothing is saved)") . " on database " . db_value("SELECT DATABASE()") . "\n\n";
printf("%-22s %8s %8s %8s\n", 'Table', 'Before', 'After', 'Added');
foreach ($after as $t => $s) {
    $d = $s['count'] - $before[$t]['count'];
    if ($d !== 0) printf("%-22s %8d %8d %+8d\n", $t, $before[$t]['count'], $s['count'], $d);
}
echo "\nDemo data: " . count($programs) . " programs, " . count($students) . " students (" . count($scholar) . " with a scholarship), " . count($staff) . " staff, 1 accountant\n";
echo "Challans:  {$challans['p']} paid, {$challans['pa']} partial, {$challans['u']} unpaid; {$challans['f']} with a late fine; {$fee_stats['payments']} payments ({$fee_stats['installments']} second instalments)\n";
echo "Salaries:  {$sal_stats['Paid']} paid (July - September), {$sal_stats['Pending']} pending (October)\n";
echo "Expenses:  " . count($expenses) . " | Grants: 3 | Student charges: " . count($charge_plan) . " | Fee reminders: {$rem_count} | Activity logs: " . count($logs) . "\n";
if ($budget_levels) {
    echo "Budget:    Senate approved (active); Revised budget waiting at F&PC. Utilization: ";
    foreach ($budget_levels as $name => $pct) { echo "{$name} {$pct}%  "; }
    echo "\n";
}
if ($budget_note) echo "NOTE:      {$budget_note}\n";

echo "\nTrial Balance " . date('d M Y', strtotime(fiscal_year_range(DEMO_FY)[0])) . " - " . date('d M Y') . ":\n";
foreach ([['Opening', 'open_dr', 'open_cr'], ['Period', 'dr', 'cr'], ['Closing', 'close_dr', 'close_cr']] as $c) {
    printf("  %-8s Debit %18s   Credit %18s   %s\n", $c[0], money($tb[$c[1]] / 100), money($tb[$c[2]] / 100), $tb[$c[1]] === $tb[$c[2]] ? 'EQUAL' : 'NOT EQUAL');
}
echo "  Unbalanced vouchers: {$unbalanced} | Bank balance: PKR " . money($bank) . "\n";
echo "\nExisting records changed: " . ($changed ? implode(', ', $changed) : 'none') . "\n";

$ok = $unbalanced === 0 && $tb['open_dr'] === $tb['open_cr'] && $tb['dr'] === $tb['cr'] && $tb['close_dr'] === $tb['close_cr'] && $bank > 0
   && !array_diff($changed, $bid && $existing ? ['budgets'] : []);
if (!$ok) {
    $db->rollback();
    stop('A check failed - everything was rolled back.');
}
if (!$run) {
    $db->rollback();
    echo "\nDRY RUN finished - rolled back. Run again with --run to save.\n";
    exit(0);
}
$db->commit();
$demo_student = null;
// Computer Science ka partial-paid student: us ke dashboard par dues aur fee reminder dono nazar aate hain
foreach ($students as $i => $s) { if ($status_plan[$i] === 'Partial' && $s['program_id'] === $programs[1]['id']) { $demo_student = $s; break; } }
echo "\nSAVED. Demo logins (password for every demo account: {$password})\n";
printf("  %-11s %-52s %s\n", 'Student', $demo_student['email'], "{$demo_student['full_name']}, {$demo_student['program']}, Semester {$demo_student['semester']} (has pending dues)");
printf("  %-11s %-52s %s\n", 'Staff', $staff[3]['email'], "{$staff[3]['name']}, {$staff[3]['designation']}");
printf("  %-11s %-52s %s\n", 'Accountant', $acc_email, 'Sana Gul (all four permissions)');
echo "This password is not stored anywhere else - note it down now.\n";
