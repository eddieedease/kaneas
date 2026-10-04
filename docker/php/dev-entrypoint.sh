#!/bin/sh
# Development only: auto install Kaneas (tables + dev accounts) on an empty database,
# then start Apache. The web installer stays available for production installs.
php /var/www/html/install/cli.php --from-env || echo "[kaneas] auto install skipped (see above)"
exec docker-php-entrypoint apache2-foreground
