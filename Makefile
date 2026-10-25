# Entwicklungs-Helfer. Nichts davon braucht die App im Betrieb - auf dem
# Webserver liegen nur die PHP-Dateien und assets/ (siehe README, "Setup").
#
#   make dev      lokalen PHP-Server starten (braucht PHP auf der Maschine)
#   make docker   dasselbe im Container, ohne PHP lokal zu installieren
#
# Beide laufen im Vordergrund und werden mit Ctrl-C beendet; das Repository
# ist live eingebunden, Änderungen sind also nach einem Reload da.

# Host-Ports. Zwei getrennte Vorgaben, damit ein lokaler Server und ein
# Container gleichzeitig laufen können (z.B. zum Vergleich).
PORT        ?= 8000
DOCKER_PORT ?= 8111
PHP_IMAGE   ?= php:8.3-cli
CONTAINER   ?= tre-dev

# php:8.3-cli bringt kein pdo_sqlite mit - history.php läuft damit auf einen
# Fehler, der Explorer selbst braucht es nicht. SQLITE=1 baut die Erweiterung
# beim Start nachträglich; der Port öffnet dann 10-60 s später (je nach Download).
#
# docker-php-ext-install allein genügt nicht: das Image hat die sqlite3-Header
# nicht, ./configure bricht sonst mit "Package 'sqlite3' not found" ab. Deshalb
# vorher libsqlite3-dev nachinstallieren.
#
# Das "exec" ist nicht Kosmetik: ohne es bleibt die sh als PID 1 im Container
# stehen, und eine PID 1 ohne eigenen Handler ignoriert SIGINT - Ctrl-C würde
# den Container dann NICHT beenden. Mit exec ist php -S selbst PID 1 und hört
# auf das Signal.
ifeq ($(SQLITE),1)
DOCKER_CMD = sh -c 'apt-get update -qq && apt-get install -y -qq libsqlite3-dev \
	&& docker-php-ext-install pdo_sqlite >/dev/null && exec php -S 0.0.0.0:8000'
else
DOCKER_CMD = php -S 0.0.0.0:8000
endif

.DEFAULT_GOAL := help
.PHONY: help dev docker

help:
	@echo 'Swiyu Trust Registry Explorer - Entwicklung'
	@echo
	@echo '  make dev                   PHP-Server auf http://localhost:$(PORT)'
	@echo '  make docker                Container auf http://localhost:$(DOCKER_PORT)'
	@echo '  make docker SQLITE=1       dito, mit pdo_sqlite (History funktioniert)'
	@echo
	@echo 'Ports/Image überschreiben: make dev PORT=9000, make docker DOCKER_PORT=9000,'
	@echo 'make docker PHP_IMAGE=php:8.4-cli'

dev:
	@command -v php >/dev/null 2>&1 || { \
		echo 'Kein php in PATH. Entweder PHP installieren (brew install php)'; \
		echo 'oder "make docker" nehmen - das braucht nur Docker.'; \
		exit 1; \
	}
	@echo "http://localhost:$(PORT) - Ctrl-C beendet den Server"
	php -S localhost:$(PORT)

docker:
	@echo "http://localhost:$(DOCKER_PORT) - Ctrl-C beendet den Container"
	docker run --rm --name $(CONTAINER) \
		-v "$(CURDIR)":/app -w /app -p $(DOCKER_PORT):8000 \
		$(PHP_IMAGE) $(DOCKER_CMD)
