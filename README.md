# UFMS – University Finance Management System (UST Bannu)

Final-year project: a web portal for the **UST Bannu Finance Department**. Fee collection, payroll,
expenses, grants, double-entry vouchers, trial balance and budgets, with four separate portals:
**Administration, Finance Office (Accountant), Staff and Student**.

Built with PHP (7.4 / 8.x) + MySQL/MariaDB, Bootstrap 5, SweetAlert2, Chart.js, html2pdf and PHPMailer.
Runs on XAMPP/WAMP.

---

## 1. What you can do

### Everyone
- Open the home page and choose a portal: **Administration, Finance Office, Staff Portal, Student Portal**.
- Sign in with email + password; an account can only use its own portal.
- **Forgot password:** a 6-digit OTP by email, then a new password.
- **Change password** at any time; new accounts get a temporary password that must be changed at first login.
- **Logout** asks for confirmation and ends on a "You have been logged out" page.
- Smooth, subtle animations everywhere (switched off for printing, print pages and "reduce motion" settings); the login
  page slides between **Login** and **Forgot Password** (there is no self-registration).

### Administration (Admin)
- **Dashboard:**
  - Fee income, pending dues, expenses and salaries paid.
  - A 6-month income vs expenses chart and recent fees/expenses.
  - The activity feed, and Pending Payrolls.
  - Budget alerts, the **Security** card (today's auto-locks, refused pages, wrong-portal logins) and **Blocked accounts** with an Unlock button.
- **Accountants:**
  - Create them; login details are emailed, or shown on screen if email fails.
  - Edit, activate/deactivate or delete them.
  - Grant permissions: **Fees, Salaries, Expenses, Accounts**.
- **Fee Structure:** tuition, admission, exam and other fees for every program.
- **Chart of Accounts:** add, edit or delete receipt and payment heads (group heads and sub-heads).
- **Vouchers:**
  - See every RV/PV/JV.
  - Create **manual Journal Vouchers**; they must balance.
  - Cancel a manual voucher with a reason (automatic vouchers follow their fee, expense or salary record).
- **Budgets:**
  - Record the **F&PC → Syndicate → Senate** decisions (approve or return), with meeting date, reference and minutes PDF.
- **Everything the Finance Office can do:** every finance page is open to the Admin, with a back button to the Admin Dashboard.
- **Reminders:** general notices for everyone (shown on the home page and every dashboard) or only for students, staff or
  accountants, plus everything the Finance Office can do with fee reminders (below).
- **Activity Logs:** every login, change, payment and security event; search and filter by action and day.

### Finance Office (Accountant) – each module needs the matching permission
- **Students (Fees):**
  - Register students; the login account is created and the details are emailed.
  - Edit or delete students; search, filter and page through the list.
- **Fee Collection (Fees):**
  - The semester challan is calculated from the fee structure (admission fee only in semester 1, scholarship subtracted).
  - Partial payments and late fines are recorded.
  - Fee receipts print or download as PDF.
  - Every payment creates a **receipt voucher** automatically.
- **Reminders (Fees):**
  - Send a **fee reminder** to all students with pending dues, to a program and/or semester, or to one student.
  - The message can use `{name}`, `{balance}`, `{due_date}` and `{reg_no}`; each student sees their own values on their
    dashboard, and the reminder disappears for a student once the balance is paid.
  - Sending is **manual only** (the Send Reminder button; there is no automatic or scheduled sending). Only the Admin or an
    accountant with the Fees permission can send.
  - **Also send by email** is ticked by default for fee reminders (one email per student) and can be unticked.
  - After sending, a **delivery report** shows how many emails were sent and which failed (address and reason), with a
    **Retry failed** button. Students who paid in the meantime are skipped on retry.
  - The history shows **Seen by X of N** (a student counts as "seen" the first time the reminder appears on their dashboard).
  - **Duplicate guard:** students who already got a fee reminder in the last 24 hours are listed and you must confirm
    before sending again.
  - The Fee Collection list shows **Last reminded: date** for each student.
  - Buttons on the Fee Collection page: **Remind** per student and **Remind all students with dues**.
- **Staff & Payroll (Salaries):**
  - Register staff as **Regular, Contract, Visiting (per hour) or Daily Wages (per day)**.
  - Create monthly salary bills (one per staff member per month) with deductions, then pay them; the payment creates a **payment voucher**.
  - Print salary slips, and see a summary by employment type.
- **Expenses (Expenses):**
  - Record expenses against expense heads, with a receipt upload; the expense creates a **payment voucher**.
  - Edit an expense (the voucher is replaced) or delete it (the voucher is cancelled), or remove just the receipt.
  - Filter by head and date range, and print or PDF an **Expense Report**.
- **Accounts permission:**
  - **Voucher Generator:** list, filter, print or PDF any voucher.
  - **Trial Balance:** any date range; always balanced; print and PDF.
  - **Grants:** record HEC/HED grants received.
  - **Student Charges:** record hostel, transport, degree and similar charges. Both of these create **receipt vouchers**; only the Admin can cancel one.
  - **Budgets:** prepare the yearly budget per head, submit it to F&PC, and revise an approved budget.
  - **Budget Utilization:** budget vs actual per head; warning at 80% and exceeded at 100%; print and PDF.

### Staff
- See your profile (employment type, salary or rate), a salary summary with a year filter, your salary history, and your own salary slips (print or PDF).

### Student
- See your profile, a fee summary (payable / paid / balance), your challan history with details, other payments (hostel, transport…), and your own fee receipts (print or PDF).

Students and staff accept an **Acceptable Use Policy** once, at their first login.

---

## 2. Login portals and automatic security rules

All four portals use the same secure login (`auth/login.php?portal=...`). Full details:
`docs/security_rules.md` (code: `includes/security.php`).

| Rule | What happens (defaults, changeable in `config.php`) |
|---|---|
| Basic protection | Prepared statements (SQL injection), `e()` escaping (XSS), CSRF tokens on every form, hashed passwords, session ID renewed at login, no-cache pages. |
| Wrong password | 5 wrong passwords → that account is locked for 15 minutes. |
| IP throttling | More than 20 failed logins from one IP in 15 minutes → that IP cannot log in for 15 minutes. |
| Access denied | A logged-in user refused by a page's role/permission check is logged as `ACCESS_DENIED` with the page. |
| Auto-lock | 5 `ACCESS_DENIED` in 10 minutes → logged out and locked for 30 minutes. Admin is never auto-locked. |
| Wrong-portal strikes | Correct password, wrong portal: refused; the 2nd time adds a warning; the 3rd time in 24 hours blocks the account for 24 hours (emailed; Admin exempt; the Admin can Unlock it). |
| Warning + policy | Every login page shows "Unauthorized access is prohibited. All activity is logged." Students and staff accept the usage policy once. |
| Campus-only (optional) | `ADMIN_ALLOWED_IPS` (empty = everywhere): Administration, Finance Office and all `admin/` pages work only from those IPs. |
| Admin 2FA (optional) | `ADMIN_2FA` (default `false`): a 6-digit code is emailed after the Admin password. Needs internet, so keep it `false` for an offline viva. |
| Session timeout | 30 minutes without activity (Admin: 15) or 8 hours in total → log in again (checked on the server). A popup 1 minute before offers **Stay logged in**. |
| Logout | Confirmation popup; POST + CSRF only (other sites cannot log you out); then the signed-out page; Back shows no dashboard. |
| Uploads | Only JPG/PNG/PDF up to 5 MB (content checked), random file names, `uploads/` closed to direct access. |

---

## 3. Financial Statement module

Double-entry accounting (cash basis) for the supervisor's requirements: **Trial Balance, Budgets,
Utilization, Voucher Generator**. Design and implementation log: `docs/financial_module_plan.md`.

- Fee collection, expenses, salary payments, grants and student charges create balanced vouchers
  automatically (`includes/postings.php`); users never type debit/credit.
- `post_voucher()` (`includes/accounting.php`) is the only way to create a voucher: it checks debit =
  credit and must run inside a DB transaction. Vouchers are cancelled, never deleted.
- The fiscal year runs July–June; voucher numbers restart each year (`RV-2627-0001` = FY 2026-27).
- Budget workflow: Draft → Submitted to F&PC → F&PC → Syndicate → Senate approved (active); any stage can return it for revision.

---

## 4. Setup

1. Copy the `ufms_project` folder into the web root (`C:\wamp64\www\` or `/opt/lampp/htdocs/`).
2. Copy `includes/config.sample.php` to `includes/config.php` and set the database, `APP_URL` and email (section 5).
3. Database – **choose ONE option**:
   - **A) Fresh install with demo data:** phpMyAdmin → **Import** → `database/ufms_schema.sql`
     (this deletes the old tables), then run `php database/backfill_vouchers.php --run` once.
   - **B) Upgrade an old UFMS database (take a backup first!):** import `database/migrate_existing_db.sql`
     once, then `database/migrate_01_*.sql` … `migrate_11_*.sql` in order. Then run
     `php database/backfill_vouchers.php` (a dry run that shows what it would do) and
     `php database/backfill_vouchers.php --run` to create vouchers for old records.
4. Open `http://localhost/ufms_project/`.

### Demo accounts (option A only)

| Portal | Email | Password |
|---|---|---|
| Administration | admin@ustb.edu | Admin@1234 (asks for a new password at first login) |
| Finance Office | accountant@ustb.edu | Account@1234 |
| Student Portal | student@ustb.edu | Student@1234 |
| Staff Portal | staff@ustb.edu | Staff@1234 |

> **Note:** Demo credentials for local testing only. Change all passwords before any real deployment.

## 5. Email (Gmail)

1. Google Account → Security → App passwords → create an app password.
2. Put the Gmail address in `MAIL_USERNAME` and the 16-character app password in `MAIL_PASSWORD` in `includes/config.php`.

Email is used for login details of new accounts, Forgot Password OTPs, Admin 2FA codes,
account-blocked notices and (when ticked) reminders. Without email the system still works: temporary passwords are shown on screen
instead, but Forgot Password and Admin 2FA need email. On a local SSL error, set `MAIL_VERIFY_SSL` to
`false` (local testing only).

`includes/config.php` is in `.gitignore`: never push it to GitHub (use `config.sample.php` as the template).

### Home page details
- **Logo and favicon:** save the university logo as `assets/img/ustb_logo.png` (square PNG). It then appears in the home page
  header and as the browser-tab icon on every page; without the file a generic icon is used.
- **Footer:** set `FINANCE_OFFICE_ADDRESS`, `FINANCE_OFFICE_PHONE`, `FINANCE_OFFICE_EMAIL`, `FINANCE_OFFICE_HOURS` and
  `APP_VERSION` in `includes/config.php`. An empty value is simply not shown.

## 6. Folder structure

```
index.php        portal selection (home page)
auth/            login (4 portals), logout + signed-out page, forgot/verify/reset/change password, Admin 2FA, usage policy
admin/dashboard/ Admin: dashboard, accountants, fee structure, chart of accounts, activity logs
admin/accountant_portal/ students, fees, expenses, payroll, staff, slips, vouchers, trial balance, grants,
                 student charges, budgets, utilization
student/         student portal + fee receipt
staff/           staff portal
assets/          img/ustb_logo.png (optional logo + favicon), css/animations.css + css/dialog.css + css/responsive.css + js/ui.js (shared animations, the one confirm-dialog theme, tablet/phone layout; finance figures are never animated)
includes/        config, DB, helpers, accounting engine, postings, budgets, security rules, header/footer/nav, PHPMailer
database/        ufms_schema.sql (fresh) + migrate_existing_db.sql + migrate_01…11 (upgrades, in order) + backfill_vouchers.php
                 demo_seed.php (demo data for a presentation) + demo_reset.sh (removes it by restoring the backup)
docs/            financial_module_plan.md, security_rules.md, test_checklist.md
uploads/         expense receipts, budget minutes (protected, not in git)
```


## Demo data (presentation)

`database/demo_seed.php` loads realistic demo data for FY 2026-27 (July - October 2026): 3 programs, 60 students,
15 staff, challans and payments, salaries, 40 expenses, grants, student charges, a Senate-approved budget with
Warning / Exceeded heads, a Revised budget at F&PC, and two fee reminders. It uses the same posting functions as the
pages, so every voucher is balanced. No email is sent while seeding, and existing records are not touched.

```
mysqldump -u root university_finance_db > ~/ufms_backups/ufms_before_demo_seed_$(date +%Y%m%d_%H%M%S).sql
php database/demo_seed.php                           # dry run: prints the summary, saves nothing
php database/demo_seed.php --run --use-empty-draft   # real run; prints the demo password once
bash database/demo_reset.sh                          # removes the demo data (restores the backup above)
```

Demo accounts use `demo+demo.<name>@example.com` addresses and share one random password that is printed only at the
end of the real run. `demo_reset.sh` restores the whole database to the backup, so anything entered after the
backup is removed too (it saves the current state first).

## 7. Documents and testing

- `docs/financial_module_plan.md` – accounting design and implementation log.
- `docs/security_rules.md` – every security rule, its settings and audit actions.
- `docs/test_checklist.md` – an 82-step browser test checklist with Pass/Fail columns.

## 8. History: what was fixed in the original code

#### Crashes and broken pages
- Admin dashboard "Recent Payments" query used columns that do not exist (`s.full_name`, `registration_number`), which crashed the page. Fixed.
- Broken links caused by the `accountatnt_portal` typo: the staff salary slip and the admin "Pending Payrolls" button. Fixed.
- `staff/view_slips.php` and `auth/register*.php` were empty files. Removed; slips now open from the staff portal.
- The root `index.php` showed testing text, and logout ended on a blank page. Now: one login page for all roles, and logout returns to login with a message.
- Duplicate files removed: 4 `logout.php` copies, 2 login processes, `add_student.php`, the `PHPMailer-master` folder and zip, and the `" reports"` folder (its name started with a space).

#### Database
- Tables converted from MyISAM to InnoDB. Transactions now work (a user and a student are created together or not at all), and foreign keys block orphan records.
- The `UNIQUE staff_id_number` column in `staff_salaries` allowed only **one salary per staff member, ever**.
  - Staff profile now lives in a new `staff` table.
  - Payroll lives in `staff_salaries`, with a limit of one bill per staff member per month.
  - Months are stored as `YYYY-MM` (old values such as "June 2026" / "june 2026" were converted).
- `fees_records` columns were misused: `amount_due` held the amount **paid** and `late_fee_fine` held the **balance**.
  - New columns: `total_fee`, `amount_paid`, `late_fee_fine` (the real fine), and `balance`, which is calculated automatically.
  - Pending dues are no longer double-counted.
- The total fee is no longer typed by hand; it is calculated from the Fee Structure:
  - Tuition + Exam + Other.
  - Admission fee is added only in the 1st semester.
  - The student's scholarship is subtracted.
- Partial payments are added to the same challan, and the amount can never exceed the balance.
- Students are linked to a program (`program_id`) instead of free-text departments. A program with enrolled students cannot be deleted.
- Fee records of deleted students (student IDs 8, 11, 12) were moved to `backup_orphan_fees_records`.

#### Security
- **SQL injection:** every query now uses prepared statements.
- **XSS:** every output is escaped with `e()`. For example, a student name containing `<script>` is now shown as plain text.
- **CSRF:** every form carries a token. Delete and pay actions now use POST instead of GET links, with a SweetAlert confirmation.
- **Permissions:** the Fees, Salaries and Expenses permissions were never checked. Now:
  - Every page checks them.
  - Modules the accountant has no permission for are shown as locked.
  - If the Admin revokes a permission or deactivates an account, it takes effect immediately.
- **IDOR:** a student could open any receipt by changing `?id=`. Now a student sees only their own receipts and a staff member sees only their own slips.
- **Passwords:**
  - The registration number was used as the password. New accounts now get a random temporary password, which must be changed at first login.
  - Minimum 8 characters, containing letters and numbers.
  - Confirm-password field and show/hide eye toggle added.
- **Login:**
  - The account locks for 15 minutes after 5 wrong attempts.
  - The error message is generic, so it does not reveal which emails are registered.
  - The session ID is regenerated at login.
  - Inactive accounts cannot log in.
  - After logout, the Back button does not reopen the dashboard.
- **OTP:**
  - Generated with `random_int` and stored as a hash in the database.
  - Valid for 15 minutes, maximum 5 attempts, and cannot be reused.
  - It cannot be re-sent within 1 minute.
- **Uploads:**
  - Only JPG, PNG and PDF files up to 5 MB are accepted, and the file content is checked, not just the extension.
  - Files are saved under random names.
  - `uploads/` is closed to direct access; receipts open only after login.
- Database errors (`mysqli_error`) are no longer shown to users.

#### Features that were fake or missing
- The "System Activity Logs" widget was hardcoded text. There is now a real `audit_logs` table recording logins, adds, deletes and payments, plus an Activity Logs page.
- The "System Status: Secure" card was hardcoded. It now shows the real Pending Dues figure.
- The dashboard chart had only 2 bars. It now shows 6 months of fee income, expenses and salaries.
- Expenses: edit, delete (the receipt file is deleted too), category filter and total.
- Salaries:
  - Selecting a staff member fills in the designation and base salary automatically.
  - Deductions field added.
  - Duplicate-month warning.
  - Paid salaries cannot be deleted.
- Student portal: summary cards (payable/paid/balance), challan detail popup, receipt Print/PDF.
- Staff portal: summary card, year filter, and slip Print/PDF/Back.
- Change Password page for every role.
