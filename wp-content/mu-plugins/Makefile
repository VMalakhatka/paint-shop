# ===== i18n for MU-plugins (multiple textdomains) =====
SHELL    := /bin/sh
LANGDIR  := languages
TMPROOT  := .i18n

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

LOCALES := uk ru_RU
UTF8    := LC_ALL=en_US.UTF-8 LANG=en_US.UTF-8

POTS := $(foreach D,$(DOMAINS),$(LANGDIR)/$(D).pot)
POS  := $(foreach D,$(DOMAINS),$(foreach L,$(LOCALES),$(LANGDIR)/$(D)-$(L).po))
MOS  := $(foreach D,$(DOMAINS),$(foreach L,$(LOCALES),$(LANGDIR)/$(D)-$(L).mo))

.PHONY: i18n pot merge mo clean clean-mo list debug ensure-pos \
        pot-one merge-one mo-one FORCE
FORCE:

# ===== Основные цели =====
i18n: pot merge mo
pot: $(POTS)

# Всегда пересобирать .pot из изолированной папки и чистить "протечки"
$(LANGDIR)/%.pot: FORCE
	@set -e; \
	mkdir -p "$(LANGDIR)" "$(TMPROOT)"; \
	tmp="$(TMPROOT)/$*"; \
	rm -rf "$$tmp"; mkdir -p "$$tmp"; \
	cp -f "$*.php" "$$tmp/" 2>/dev/null || true; \
	for f in $*-*.php; do [ -e "$$f" ] && cp -f "$$f" "$$tmp/"; done || true; \
	echo ">> make-pot for $* (tmp=$$tmp)"; \
	$(UTF8) wp i18n make-pot "$$tmp" "$@" \
	  --domain=$* \
	  --package-name=$* \
	  --exclude=node_modules,vendor,dist,build,tests; \
	echo ">> strip foreign refs from $@"; \
	re=""; for d in $(DOMAINS); do [ "$$d" != "$*" ] && re="$${re:+$$re|}$$d\\.php"; done; \
	if [ -n "$$re" ]; then $(UTF8) msggrep --location=source -E -v "$$re" "$@" -o "$@.clean" && mv "$@.clean" "$@"; fi; \
	rm -rf "$$tmp"

# merge гарантированно после pot
merge: pot ensure-pos
	@for d in $(DOMAINS); do \
	  for l in $(LOCALES); do \
	    echo ">> msgmerge $$d-$$l"; \
	    $(UTF8) msgmerge --update $(LANGDIR)/$$d-$$l.po $(LANGDIR)/$$d.pot; \
	  done; \
	done

# Создать пустые PO, если нет (и при необходимости — собрать POT)
ensure-pos:
	@set -e; \
	mkdir -p "$(LANGDIR)"; \
	for d in $(DOMAINS); do \
	  if [ ! -f "$(LANGDIR)/$$d.pot" ]; then \
	    echo "!! Missing POT for $$d — generating..."; \
	    $(MAKE) pot-one D=$$d; \
	  fi; \
	  for l in $(LOCALES); do \
	    f="$(LANGDIR)/$$d-$$l.po"; \
	    if [ ! -f "$$f" ]; then \
	      echo ">> init $$f"; \
	      $(UTF8) msginit --no-translator --locale=$$l --input="$(LANGDIR)/$$d.pot" --output-file="$$f"; \
	    fi; \
	  done; \
	done

mo: $(MOS)

$(LANGDIR)/%.mo: $(LANGDIR)/%.po
	$(UTF8) msgfmt --check-format $< -o $@

# ===== По одному домену =====
# Пример: make pot-one D=pc-account-tweaks
pot-one:
	@if [ -z "$(D)" ]; then echo "Usage: make pot-one D=<domain>"; exit 2; fi
	@set -e; \
	mkdir -p "$(LANGDIR)" "$(TMPROOT)"; \
	tmp="$(TMPROOT)/$(D)"; \
	rm -rf "$$tmp"; mkdir -p "$$tmp"; \
	cp -f "$(D).php" "$$tmp/" 2>/dev/null || true; \
	for f in $(D)-*.php; do [ -e "$$f" ] && cp -f "$$f" "$$tmp/"; done || true; \
	echo ">> make-pot for $(D) (tmp=$$tmp)"; \
	$(UTF8) wp i18n make-pot "$$tmp" "$(LANGDIR)/$(D).pot" \
	  --domain=$(D) \
	  --package-name=$(D) \
	  --exclude=node_modules,vendor,dist,build,tests; \
	echo ">> strip foreign refs from $(LANGDIR)/$(D).pot"; \
	re=""; for d in $(DOMAINS); do [ "$$d" != "$(D)" ] && re="$${re:+$$re|}$$d\\.php"; done; \
	if [ -n "$$re" ]; then $(UTF8) msggrep --location=source -E -v "$$re" "$(LANGDIR)/$(D).pot" -o "$(LANGDIR)/$(D).pot.clean" && mv "$(LANGDIR)/$(D).pot.clean" "$(LANGDIR)/$(D).pot"; fi; \
	rm -rf "$$tmp"

merge-one:
	@if [ -z "$(D)" ]; then echo "Usage: make merge-one D=<domain>"; exit 2; fi
	@if [ ! -f "$(LANGDIR)/$(D).pot" ]; then $(MAKE) pot-one D=$(D); fi
	@for l in $(LOCALES); do \
	  echo ">> msgmerge $(D)-$$l"; \
	  $(UTF8) msgmerge --update "$(LANGDIR)/$(D)-$$l.po" "$(LANGDIR)/$(D).pot"; \
	done

mo-one:
	@if [ -z "$(D)" ]; then echo "Usage: make mo-one D=<domain>"; exit 2; fi
	@for l in $(LOCALES); do \
	  $(UTF8) msgfmt --check-format "$(LANGDIR)/$(D)-$$l.po" -o "$(LANGDIR)/$(D)-$$l.mo"; \
	done

# ===== Утилиты =====
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
	rm -rf $(TMPROOT)

debug:
	@echo "Locale files:"; ls -l $(LANGDIR) | sed 's/^/  /' || true