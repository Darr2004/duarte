#!/bin/bash
set -e

# Render provides the port in the $PORT environment variable (defaults to 80)
PORT="${PORT:-80}"

sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
