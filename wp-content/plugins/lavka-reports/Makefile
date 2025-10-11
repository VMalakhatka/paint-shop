PLUGIN=lavka-reports
VERSION=0.1.0

pot:
	@echo "Generating POT..."
	@wp i18n make-pot . languages/$(PLUGIN).pot --domain=$(PLUGIN)

zip:
	@mkdir -p build
	@rm -f build/$(PLUGIN)-$(VERSION).zip
	@zip -rq build/$(PLUGIN)-$(VERSION).zip \
		lavka-reports.php inc reports.css reports.js languages README.txt || true
	@echo "Built build/$(PLUGIN)-$(VERSION).zip"
```

---
**README.txt**
```
=== Lavka Reports ===
Contributors: volodymyr
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 0.1.0
License: GPLv2 or later
