# UFMS – Automatic Security Rules

All rules run automatically; the Admin does not have to do anything. The code is in
`includes/security.php` (loaded by `includes/bootstrap.php`). Settings are in `includes/config.php`
(template: `includes/config.sample.php`); if a setting is missing, the default below is used.
Database: `database/migrate_07_security.sql` (`users.accepted_policy_at`, table `login_attempts`) and
`database/migrate_08_portal_strikes.sql` (`users.lock_reason`, table `portal_strikes`).

| # | Rule | Default | Setting(s) |
|---|---|---|---|
| 1 | ACCESS_DENIED logging | on | – |
| 2 | Auto-lock after repeated refused pages | 5 in 10 min → 30 min lock | `AUTO_LOCK_DENIALS`, `AUTO_LOCK_WINDOW_MINUTES`, `AUTO_LOCK_MINUTES` |
| 3 | Login throttling per IP | > 20 failures in 15 min → 15 min block | `IP_MAX_FAILED_LOGINS`, `IP_WINDOW_MINUTES`, `IP_BLOCK_MINUTES` |
| 4 | Login warning + Acceptable Use Policy | on | – |
| 5 | Campus-only Administration / Finance Office | off (allow all) | `ADMIN_ALLOWED_IPS` |
| 6 | Admin two-factor login (email OTP) | off | `ADMIN_2FA`, `ADMIN_OTP_MINUTES` |
| 7 | Wrong-portal strikes | 3 in 24 h → 24 h block | `WRONG_PORTAL_MAX_STRIKES`, `WRONG_PORTAL_BLOCK_HOURS` |
| 8 | Session timeout | 30 min idle (Admin 15), 8 h maximum | `SESSION_IDLE_MINUTES`, `ADMIN_IDLE_MINUTES`, `SESSION_MAX_HOURS` |

## 1. ACCESS_DENIED logging
When `require_login()` (wrong role) or `require_permission()` (missing permission) refuses a
**logged-in** user, the audit log gets `ACCESS_DENIED` with `Page: <path> | Reason: <reason>`.
The same user + page (query string ignored) is logged at most once per minute. Visitors who are
not logged in are not logged. The user is sent back to their own dashboard as before.

## 2. Auto-lock
If a user collects **5 ACCESS_DENIED events within 10 minutes**, the account is locked for
**30 minutes** (`users.locked_until`), the user is logged out, and `ACCOUNT_AUTO_LOCKED` is logged.
- A lock also ends sessions that are already open (checked on every page by `require_login()`).
- Logging in during the lock is refused with the remaining minutes.
- **Admin accounts are never auto-locked** (their refused pages are still logged).
- The lock ends by itself after 30 minutes. A successful password reset (email OTP) also clears
  it, as for the wrong-password lock. There is no manual unlock button.
- Because of the once-per-minute rule, 5 events means 5 different pages, or the same page over 5 minutes.

## 3. Login throttling per IP
Every failed login (wrong password, unknown email, wrong Admin 2FA code) is stored in
`login_attempts`. If an IP has **more than 20 failures in 15 minutes**, that IP cannot log in
for **15 minutes** (`blocked_until` on the failure that triggered it) and `LOGIN_IP_BLOCKED` is logged.
- While blocked, every login from that IP (even with a correct password) gets only
  "Too many attempts. Please try again later." Nothing else is checked or revealed.
- Refused attempts are not stored, so the block does not keep extending.
- Other IPs are not affected. Rows older than 1 day are deleted automatically.
- The per-account lock (5 wrong passwords → 15 minutes, `MAX_LOGIN_ATTEMPTS`) still applies as well.
- Only `REMOTE_ADDR` is used (proxy headers such as `X-Forwarded-For` are not trusted).

## 4. Login warning and Acceptable Use Policy
- Every login page (all four portals, the generic login, forgot/verify/reset password and the
  Admin 2FA page) shows: **"Unauthorized access is prohibited. All activity is logged."**
- A **Student or Staff member** who has not yet accepted the policy (`accepted_policy_at IS NULL`)
  is sent to `auth/accept_policy.php` from every protected page. This is enforced on the server in
  `require_login()`, exactly like the temporary-password rule, so typing a dashboard, receipt or
  salary-slip URL directly does not work. Only the policy page, `change_password.php` and logout stay open.
- Order at first login: change the temporary password, then accept the policy, then the dashboard.
- **Accept and continue** is disabled until the box is ticked, and the server also rejects a POST
  without the tick ("Please tick the box to accept the policy."). Only then is `accepted_policy_at`
  set and `POLICY_ACCEPTED` logged.
- **Decline and log out** logs the user out (POST + CSRF) and logs `POLICY_DECLINED`.
  Existing students and staff see the policy at their next login.
- Admin and Accountant accounts are not asked.

## 5. Campus-only access (`ADMIN_ALLOWED_IPS`)
Empty (default) = allow from everywhere. When set, e.g.
`define('ADMIN_ALLOWED_IPS', '127.0.0.1, ::1, 10.0.0.0/8, 192.168.1.0/24');`
(exact IPs and CIDR ranges, IPv4/IPv6, separated by commas or spaces):
- The **Administration** and **Finance Office** login pages show
  "This portal is available only on the university network." instead of the form.
- Admin/Accountant logins from other IPs are refused, also through the generic login
  (`NETWORK_DENIED` is logged).
- **Every page under `admin/`** returns 403 with the same message from other IPs, even for a
  session that is already logged in.
- **Student and Staff portals always work.**
- When testing locally, include `127.0.0.1, ::1` or you will lock yourself out of the Admin pages
  (fix: set the value back to `''` in `includes/config.php`).

## 6. Admin two-factor login (`ADMIN_2FA`)
`false` by default. When `true`, after a correct **Admin** password:
1. A 6-digit code (`random_int`, stored only as a bcrypt hash in the server-side session) is
   emailed to the Admin; `ADMIN_2FA_SENT` is logged.
2. The login session starts only after the correct code is entered on `auth/verify_2fa.php`.
3. The code is valid for **10 minutes** (`ADMIN_OTP_MINUTES`) and allows **5 attempts**
   (`OTP_MAX_ATTEMPTS`); after 5 wrong codes the Admin must log in again (`ADMIN_2FA_FAILED`).
   Wrong codes also count toward the IP throttling.
4. If the email cannot be sent (no internet, email not set up), the Admin **cannot** log in and
   is told to set `ADMIN_2FA` to `false`. Turn it off before a viva without internet.
Other roles are not affected.

## 7. Wrong-portal strikes
A wrong-portal login means the **password was correct** but the account belongs to another portal
(e.g. a student signing in to the Administration portal). Each one is a strike in `portal_strikes`,
counted over a **rolling 24 hours**:
1. **Strike 1:** refused as before ("This account cannot log in to the … portal").
2. **Strike 2:** refused, plus "Warning: one more attempt and your account will be blocked for 24 hours."
3. **Strike 3:** `locked_until` = now + 24 hours, `lock_reason` = `WRONG_PORTAL`, `ACCOUNT_BLOCKED_24H`
   is logged, the user sees "Your account is blocked until <date time> due to repeated unauthorized
   portal access. Contact the Finance Office." and is emailed (if email is set up).
- Every attempt is still logged as `LOGIN_WRONG_PORTAL`, with "(strike N of 3)".
- While blocked, every login (any portal, right or wrong password) shows the block message, and
  wrong passwords are not counted.
- **Admin accounts never get strikes** and are never blocked by this rule.
- The strikes are reset when the block starts, so after the block the count starts again at 1.
- A password reset does **not** lift this block (it still lifts the wrong-password and auto-lock locks).
- **Unlock:** Admin dashboard → **Blocked accounts** lists users blocked by this rule (name, role,
  until when). **Unlock** (POST + CSRF, with confirmation) clears the block and the strikes and logs
  `ACCOUNT_UNLOCKED`. Otherwise the block ends by itself after 24 hours.
- The **wrong-password rule is unchanged** (5 wrong passwords → 15 minutes, `MAX_LOGIN_ATTEMPTS` /
  `LOCKOUT_MINUTES`), because anyone can type someone else's email with a wrong password. A
  wrong-password lock is not shown in Blocked accounts.

## 8. Session timeout
Checked on the **server** for every request (`check_session_timeout()` in `bootstrap.php`), so reopening
the browser with "continue where you left off" cannot bring an old session back.
- **Idle:** no page opened for **30 minutes** (**Admin: 15 minutes**) → the next request ends the session
  and goes to that user's portal login with "Your session expired due to inactivity. Please log in again."
- **Absolute:** a session older than **8 hours** always ends, even when the user is active
  ("Your session reached the 8-hour limit. Please log in again.").
- Both are logged as `SESSION_EXPIRED` (with the idle time or "Session older than 8 hours").
- Every page view (`require_login()`) counts as activity. **1 minute before** the end, the page shows
  "Your session is about to expire" with a countdown: **Stay logged in** calls `auth/keep_alive.php`
  (POST + CSRF) to restart the idle time (it cannot extend past the 8-hour limit); **Logout** logs out;
  doing nothing reloads the page when the time is up, which sends the user to the login page.
- A form submitted after expiry goes to the login page (not a "Request expired" error) and is not saved.
- Sessions that existed before this rule was added start being timed from their next request.

## Logout
Every Logout link has `data-confirm-logout`: one handler in `includes/footer.php` asks "Logout?" (SweetAlert2;
the browser's own confirm box if SweetAlert cannot load offline) and only on **Yes, logout** sends a
**POST with the CSRF token** to `auth/logout.php`. A plain GET to `logout.php` does not log out (it returns to
the dashboard), so another website cannot log users out with a hidden link or image.

After logout the user sees `auth/signed_out.php` in the portal's colour: "You have been logged out",
"Thank you for using UFMS, <name>" (the name comes from a one-time session value, never the URL; a refresh
or direct visit goes to the landing page), a shared-computer note, **Login again** (same portal) and
**Home**, and an automatic move to the landing page after 5 seconds with a countdown. All pages send
no-cache headers and the footer reloads any page restored from the browser's back/forward cache, so the
Back button never shows a dashboard after logout. Session expiry and locked/blocked accounts still go to
the login page with their own messages. Login forms use `autocomplete="username"` /
`"current-password"`, never pre-fill the password, and remind users not to save passwords on shared computers.

## Where to see it
- **Admin dashboard → Security card:** today's auto-locked accounts, access denied and
  wrong-portal logins. Each number opens Activity Logs filtered to that event and today.
- **Activity Logs** accepts `?action=A,B` and `?day=YYYY-MM-DD`.
- **Admin dashboard → Blocked accounts:** accounts blocked by the wrong-portal rule, with Unlock.
- Audit actions: `SESSION_EXPIRED`, `ACCESS_DENIED`, `ACCOUNT_AUTO_LOCKED`, `ACCOUNT_BLOCKED_24H`, `ACCOUNT_UNLOCKED`, `LOGIN_WRONG_PORTAL`, `LOGIN_FAILED`,
  `LOGIN_LOCKED`, `LOGIN_IP_BLOCKED`, `NETWORK_DENIED`, `POLICY_ACCEPTED`, `POLICY_DECLINED`, `ADMIN_2FA_SENT`,
  `ADMIN_2FA_FAILED`, `LOGIN`.

## Not covered
- The student receipt and staff slip pages refuse other people's IDs with their own checks, so
  those attempts are not ACCESS_DENIED events.
- Throttling is per IP: many users behind one campus NAT share one IP, so a burst of 21 wrong
  logins from the same network can briefly block that network's logins.
