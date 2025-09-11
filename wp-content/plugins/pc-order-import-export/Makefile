# -------- i18n for PC Order Import/Export --------
LANGDIR := languages
TEXTDOM := pc-order-import-export

POT   := $(LANGDIR)/$(TEXTDOM).pot
PO_UA := $(LANGDIR)/$(TEXTDOM)-uk.po
PO_RU := $(LANGDIR)/$(TEXTDOM)-ru_RU.po
MO_UA := $(LANGDIR)/$(TEXTDOM)-uk.mo
MO_RU := $(LANGDIR)/$(TEXTDOM)-ru_RU.mo

POs := $(PO_UA) $(PO_RU)
MOs := $(MO_UA) $(MO_RU)

.PHONY: i18n pot merge mo clean clean-mo debug FORCE

# Полный цикл
i18n: pot merge mo

# 1) собрать POT из кода
UTF8 := LC_ALL=en_US.UTF-8 LANG=en_US.UTF-8

pot:
	$(UTF8) wp i18n make-pot . $(POT) --domain=$(TEXTDOM) --exclude=node_modules,vendor,dist,build,tests

merge: pot
	$(UTF8) msgmerge --update $(PO_UA) $(POT)
	$(UTF8) msgmerge --update $(PO_RU) $(POT)

mo: FORCE
	@rm -f $(MOs)
	$(UTF8) msgfmt --check-format $(PO_UA) -o $(MO_UA)
	$(UTF8) msgfmt --check-format $(PO_RU) -o $(MO_RU)

# утилиты
clean-mo:
	rm -f $(MOs)

clean: clean-mo
	rm -f $(POT)

debug:
	@echo "Locale files:"
	@ls -l $(LANGDIR) | sed 's/^/  /'
	@echo
	@echo "WP load test:"
	@echo "  wp eval 'load_plugin_textdomain(\"$(TEXTDOM)\",false,\"$(LANGDIR)\"); var_dump(is_textdomain_loaded(\"$(TEXTDOM)\"));'"
	@echo
	@echo "Check a string from MO (example):"
	@echo "  msgunfmt $(MO_UA) | grep -A1 '^msgid \"Download CSV\"' || true"

# пустая цель, чтобы форсить пересборку
FORCE: