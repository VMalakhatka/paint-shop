# Makefile (wp-content/plugins/paint-core/Makefile)

POT     = languages/paint-core.pot
PO_UA   = languages/paint-core-uk.po
PO_RU   = languages/paint-core-ru_RU.po

.PHONY: pot merge mo i18n clean

pot:
	wp i18n make-pot . $(POT) --domain=paint-core --exclude=node_modules,vendor,dist,build,tests

merge: pot
	msgmerge --update $(PO_UA) $(POT)
	msgmerge --update $(PO_RU) $(POT)

mo:
	msgfmt $(PO_UA) -o languages/paint-core-uk.mo
	msgfmt $(PO_RU) -o languages/paint-core-ru_RU.mo

i18n: pot merge mo

clean:
	@rm -f languages/paint-core-uk.mo languages/paint-core-ru_RU.mo