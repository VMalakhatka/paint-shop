# ===== i18n for MU-plugins (multiple textdomains) =====
LANGDIR := languages

# Список текст-доменов MU-плагинов (правь под себя)
DOMAINS := \
  pc-account-tweaks \
  pc-cart-guard \
  pc-stock-tap \
  pc-wholesale-quick-order \
  psu-force-per-page \
  psu-search-filters \
  role-price-import-lite \
  stock-import-csv-lite \
  stock-locations-ui \
  stock-sync-to-woo

# Список локалей (добавляй при необходимости)
LOCALES := uk ru_RU

# Настройка locale для корректной работы msgfmt/msgmerge на macOS
UTF8 := LC_ALL=en_US.UTF-8 LANG=en_US.UTF-8

# ---- Автогенерация списков файлов ----
POTS := $(foreach D,$(DOMAINS),$(LANGDIR)/$(D).pot)
POS  := $(foreach D,$(DOMAINS),$(foreach L,$(LOCALES),$(LANGDIR)/$(D)-$(L).po))
MOS  := $(foreach D,$(DOMAINS),$(foreach L,$(LOCALES),$(LANGDIR)/$(D)-$(L).mo))

.PHONY: i18n pot merge mo clean clean-mo list debug ensure-pos

# Полный цикл
i18n: pot merge mo

# 1) собрать POT из кода по каждому домену
pot: $(POTS)

$(LANGDIR)/%.pot:
	@mkdir -p $(LANGDIR)
	# Извлекаем только строки указанного домена
	$(UTF8) wp i18n make-pot . $@ --domain=$* --exclude=node_modules,vendor,dist,build,tests

# 2) слить POT -> PO (создать PO, если ещё нет)
merge: ensure-pos
	@for d in $(DOMAINS); do \
	  for l in $(LOCALES); do \
	    echo ">> msgmerge $$d-$$l"; \
	    $(UTF8) msgmerge --update $(LANGDIR)/$$d-$$l.po $(LANGDIR)/$$d.pot; \
	  done; \
	done

# Создать пустые PO, если нет
ensure-pos:
	@mkdir -p $(LANGDIR)
	@for d in $(DOMAINS); do \
	  for l in $(LOCALES); do \
	    f="$(LANGDIR)/$$d-$$l.po"; \
	    if [ ! -f "$$f" ]; then \
	      echo ">> init $$f"; \
	      $(UTF8) msginit --no-translator --locale=$$l --input=$(LANGDIR)/$$d.pot --output-file="$$f"; \
	    fi; \
	  done; \
	done

# 3) собрать MO
mo: $(MOS)

$(LANGDIR)/%.mo: $(LANGDIR)/%.po
	$(UTF8) msgfmt --check-format $< -o $@

# Утилиты
list:
	@echo "DOMAINS: $(DOMAINS)"
	@echo "LOCALES: $(LOCALES)"
	@echo "POTS: $(POTS)"
	@echo "POS: $(POS)"
	@echo "MOS: $(MOS)"

clean-mo:
	rm -f $(MOS)

clean: clean-mo
	rm -f $(POTS)

debug:
	@echo "Locale files:"; ls -l $(LANGDIR) | sed 's/^/  /' || true
	@echo
	@echo "WP load test (пример для одного домена):"
	@echo "  wp eval 'load_muplugin_textdomain(\"paint-core\",\"$(LANGDIR)\"); var_dump(is_textdomain_loaded(\"paint-core\"));'"