# Container-Image für den swiyu Trust Registry Explorer.
#
# Ein einziges Image für beide Rollen (siehe docker-compose.yml):
#   - "web":       Apache + mod_php liefert index.php/history.php aus
#   - "collector": führt collect.php in einer Schleife alle 24h aus
#
# Das offizielle PHP-Image bringt curl, session, mbstring und pdo_sqlite
# bereits mit — es muss nichts nachinstalliert werden.
FROM php:8.3-apache

# Zeitzone passend zu den Europe/Zurich-Formatierungen in functions.php,
# damit auch date() im Collector-Log lokale Zeit schreibt.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'date.timezone = Europe/Zurich\nexpose_php = Off\n' > "$PHP_INI_DIR/conf.d/zz-trust-explorer.ini"

COPY docker/apache.conf /etc/apache2/conf-available/trust-explorer.conf
RUN a2enconf trust-explorer

WORKDIR /var/www/html
COPY . .

# Der Code gehört root und ist für www-data nur lesbar; einzig storage/
# gehört www-data, damit ein frisch angelegtes Named Volume diese Rechte vom
# Image übernimmt.
RUN chown root:root /var/www/html \
    && chmod 0755 /var/www/html \
    && mkdir -p storage \
    && chown www-data:www-data storage

VOLUME ["/var/www/html/storage"]
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/index.php") === false ? 1 : 0);'
