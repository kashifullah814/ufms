#!/bin/bash
# UFMS: remove the DEMO DATA by restoring the backup that was taken just before database/demo_seed.php ran.
#
#   bash database/demo_reset.sh                 restores the newest ~/ufms_backups/ufms_before_demo_seed_*.sql
#   bash database/demo_reset.sh /path/file.sql  restores that backup instead
#
# WARNING: the database goes back to exactly how it was at the time of that backup. Everything entered
# AFTER it (demo data AND any real records added later) is removed. The current state is saved first
# to ~/ufms_backups/ufms_before_demo_reset_<time>.sql, so this can be undone by restoring that file.
set -euo pipefail
MYSQL=/opt/lampp/bin/mysql
DUMP=/opt/lampp/bin/mysqldump
DB="${UFMS_DB:-university_finance_db}"
DIR="$HOME/ufms_backups"

BACKUP="${1:-$(ls -1t "$DIR"/ufms_before_demo_seed_*.sql 2>/dev/null | head -1)}"
if [ -z "$BACKUP" ] || [ ! -s "$BACKUP" ]; then
    echo "No backup found (looked for $DIR/ufms_before_demo_seed_*.sql). Nothing was changed."
    exit 1
fi
if ! grep -q "CREATE TABLE \`users\`" "$BACKUP"; then
    echo "$BACKUP does not look like a UFMS backup. Nothing was changed."
    exit 1
fi

echo "Database : $DB"
echo "Restore  : $BACKUP ($(date -r "$BACKUP" '+%d %b %Y %H:%M'))"
echo "Now      : $($MYSQL -u root -N "$DB" -e "SELECT CONCAT((SELECT COUNT(*) FROM users), ' users, ', (SELECT COUNT(*) FROM vouchers), ' vouchers')")"
echo "Everything entered after that backup will be removed."
read -r -p "Type RESTORE to continue: " answer
if [ "$answer" != "RESTORE" ]; then
    echo "Cancelled. Nothing was changed."
    exit 1
fi

SAFETY="$DIR/ufms_before_demo_reset_$(date +%Y%m%d_%H%M%S).sql"
$DUMP -u root --single-transaction "$DB" > "$SAFETY"
echo "Current state saved to $SAFETY"

# Backup har table ko DROP + CREATE + INSERT karta hai (foreign key checks dump ke andar band hain)
$MYSQL -u root "$DB" < "$BACKUP"
echo "Restored : $($MYSQL -u root -N "$DB" -e "SELECT CONCAT((SELECT COUNT(*) FROM users), ' users, ', (SELECT COUNT(*) FROM vouchers), ' vouchers, ', (SELECT COUNT(*) FROM users WHERE email LIKE '%+demo.%'), ' demo accounts')")"
echo "Done. Log in again (sessions of removed demo accounts no longer work)."
