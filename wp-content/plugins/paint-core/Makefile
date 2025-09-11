# -------- i18n for Paint Core --------
LANGDIR := languages
TEXTDOM := paint-core

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
pot:
	wp i18n make-pot . $(POT) --domain=$(TEXTDOM) --exclude=node_modules,vendor,dist,build,tests

# 2) влить свежий POT в po (сохраняя переводы)
merge: pot
	msgmerge --update $(PO_UA) $(POT)
	msgmerge --update $(PO_RU) $(POT)

# 3) ВСЕГДА пересобираем mo заново (даже если make думает, что не нужно)
mo: FORCE
	@rm -f $(MOs)
	msgfmt --check-format $(PO_UA) -o $(MO_UA)
	msgfmt --check-format $(PO_RU) -o $(MO_RU)

# утилиты
clean-mo:
	rm -f $(MOs)

clean: clean-mo
	rm -f $(POT)

debug:
	@echo "Locale files:"
	@ls -l $(LANGDIR) | sed 's/^/  /'
	@echo
	@echo "Domain loaded in WP? Use footer debug or:"
	@echo "  wp eval 'load_plugin_textdomain(\"$(TEXTDOM)\",false,\"$(LANGDIR)\"); var_dump(is_textdomain_loaded(\"$(TEXTDOM)\"));'"
	@echo
	@echo "Check a string from MO:"
	@echo "  msgunfmt $(MO_UA) | grep -A1 '^msgid \"Auto\"'"

# пустая цель, чтобы форсить пересборку
FORCE: