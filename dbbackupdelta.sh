#!/bin/bash
set -euo pipefail
cd /var/www/html/deltabotvps
if [ "$(id -u)" -eq 0 ]; then
    exec runuser -u www-data -- php backup_worker.php scheduled
fi
exec php backup_worker.php scheduled
