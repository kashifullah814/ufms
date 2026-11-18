<?php
/** Purana "Announcements" page - ab Reminders (fee reminders + general notices) finance area mein hai. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login(['Admin', 'Accountant']);
redirect(url('admin/accountant_portal/reminders.php'));
