<?php
/**
 * UFMS - Central configuration.
 * Sirf yahi file edit karein. Is file ko GitHub par push na karein (.gitignore mein hai).
 * Template ke liye config.sample.php dekhein.
 */

// ---------- Database ----------
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'university_finance_db');

// ---------- Application ----------
define('APP_NAME', 'UFMS');
define('APP_FULL_NAME', 'University Finance Management System');
define('APP_ORG', 'UST Bannu Finance Department');
define('APP_TIMEZONE', 'Asia/Karachi');
// Project ka URL path. null = auto-detect (WAMP/XAMPP mein "/ufms_project" khud mil jata hai).
// Agar links ghalat banein to yahan likh dein, e.g. '/ufms_project'
define('APP_BASE_PATH', null);
// Emails mein login link ke liye poora URL
define('APP_URL', 'http://localhost/ufms_project');

// ---------- Landing page footer (khali value landing page par nahi dikhti) ----------
define('APP_VERSION', '1.0');
define('FINANCE_OFFICE_ADDRESS', 'Finance Office, University of Science & Technology Bannu, Khyber Pakhtunkhwa');
define('FINANCE_OFFICE_PHONE', '');    // e.g. '0928-000000'
define('FINANCE_OFFICE_EMAIL', '');    // e.g. 'finance@ustb.edu.pk'
define('FINANCE_OFFICE_HOURS', '');    // e.g. 'Monday - Friday, 9:00 AM - 4:00 PM'

// ---------- Email (Gmail SMTP) ----------
// Gmail App Password yahan likhein (Google Account > Security > App passwords).
// Khali chhor dein to system email ke bajaye credentials screen par dikhayega.
define('MAIL_ENABLED', true);
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USERNAME', '');   // e.g. 'finance.ufms@gmail.com'
define('MAIL_PASSWORD', '');   // 16-character App Password (no spaces)
define('MAIL_FROM_NAME', 'USTB Finance Department');
// SSL certificate verify. Sirf local WAMP par certificate error aaye to false karein.
define('MAIL_VERIFY_SSL', true);

// ---------- Security ----------
define('MAX_LOGIN_ATTEMPTS', 5);       // itni ghalat koshishon ke baad account temporarily lock
define('LOCKOUT_MINUTES', 15);
define('OTP_VALID_MINUTES', 15);
define('OTP_MAX_ATTEMPTS', 5);
define('MIN_PASSWORD_LENGTH', 8);

// ---------- Security rules (docs/security_rules.md) ----------
// University network: sirf in IPs/ranges se Administration aur Finance Office portals + admin/ pages khulte hain.
// '' = har jagah se. Misal: '127.0.0.1, ::1, 10.0.0.0/8, 192.168.1.0/24'. Student/Staff portals hamesha khule.
define('ADMIN_ALLOWED_IPS', '');
// Admin login par email OTP (internet + email zaroori). Viva mein internet na ho to false.
define('ADMIN_2FA', false);
define('ADMIN_OTP_MINUTES', 10);
define('AUTO_LOCK_DENIALS', 5);          // itne ACCESS_DENIED ...
define('AUTO_LOCK_WINDOW_MINUTES', 10);  // ... itne minute mein -> account lock (Admin kabhi nahi)
define('AUTO_LOCK_MINUTES', 30);
define('IP_MAX_FAILED_LOGINS', 20);      // ek IP se itne se zyada ghalat logins ...
define('IP_WINDOW_MINUTES', 15);         // ... itne minute mein -> IP se login band
define('IP_BLOCK_MINUTES', 15);
define('WRONG_PORTAL_MAX_STRIKES', 3);  // sahi password + ghalat portal itni dafa (24 ghante mein) ...
define('WRONG_PORTAL_BLOCK_HOURS', 24);  // ... -> account itne ghante block (Admin kabhi nahi)
define('SESSION_IDLE_MINUTES', 30);     // itne minute kuch na karne par session khatam (Student, Staff, Accountant)
define('ADMIN_IDLE_MINUTES', 15);       // Admin ke liye
define('SESSION_MAX_HOURS', 8);         // is se purani session hamesha dobara login

// ---------- Uploads ----------
define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024); // 5 MB

// ---------- Lists ----------
define('MAX_SEMESTERS', 8);
