# [formr.org]

FORMR_DIR?=/var/www/formr
INSTALL_DIR=$(CTSR_ROOT)$(FORMR_DIR)
CONFIG_DIR=$(INSTALL_DIR)/config
CRON_TAB=$(INSTALL_DIR)/config/formr_crontab
SYS_CRON_TAB=$(CTSR_ROOT)/etc/cron.d/formr_crontab
SMI_CONFIG=/etc/smysqlin/formr.ini

GIT_REPO?=https://github.com/rubenarslan/formr.org.git
GIT=$(shell echo "git --git-dir=$(INSTALL_DIR)/.git --work-tree=$(INSTALL_DIR)")
GIT_BRANCH?=master

APACHE_USER?=www-data
APACHE_GROUP?=www-data

COMPOSER=composer

all: install

install: init init_install install_files install_dependencies clean
	@echo "formr.org has been installed successfully to $(INSTALL_DIR)."
	@echo "\nConfigure database and other settings at $(CONFIG_DIR)/settings.php and then activate cron at $(SYS_CRON_TAB)"

install_files:
	@echo "Installing files .....";

	@install -d -m 0755 $(INSTALL_DIR)

	$(GIT) init
	$(GIT) remote add origin $(GIT_REPO)
	$(GIT) pull origin $(GIT_BRANCH)

	install -d -m 0755 $(CONFIG_DIR)
	chmod 0755 $(INSTALL_DIR)/bin/cron.php
	chmod 0755 $(INSTALL_DIR)/bin/cron-run-expires-on.php
	[ -d $(CONFIG_DIR) ] && cp -R $(INSTALL_DIR)/config_default/* $(CONFIG_DIR)/

	[ ! -f $(SYS_CRON_TAB) ] && cp $(CRON_TAB) $(SYS_CRON_TAB)

	@echo "File installation.... Done."

install_dependencies:
	@echo "Installing dependencies ....."

	cd $(INSTALL_DIR) && $(COMPOSER) install
	@echo "Done"

uninstall:
	@echo "Uninstalling config files, log directory and app files .....";
	@rm -rf $(INSTALL_DIR)
	@rm -rf $(SYS_CRON_TAB)
	@echo "Done."

update: init update_files clean
	@echo "Updating.... Done"

update_files:
	$(GIT) pull origin $(GIT_BRANCH)

	cd $(INSTALL_DIR) && $(COMPOSER) install
	@chmod 0755 $(INSTALL_DIR)/bin/cron.php
	@chmod 0755 $(INSTALL_DIR)/bin/cron-run-expires-on.php

init:
	@type git >/dev/null 2>&1 || { echo >&2 "'git' is required but it's not installed. Aborting..."; exit 1; }
	@type composer >/dev/null 2>&1 || { echo >&2 "'composer' is required but it's not installed. Aborting..."; exit 1; }

init_install:
	@if [ -d $(INSTALL_DIR) ]; then echo "'formr.org' is already installed. Run 'make update' or 'make uninstall' instead. Aborting..."; exit 1; fi

clean:
	chown -R $(APACHE_USER):$(APACHE_GROUP) $(INSTALL_DIR)
	@echo "Installation completed..."
