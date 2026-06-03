# Commands

```bash
PATH=/Applications/ServBay/package/php/8.2/8.2.29/bin:$PATH /Applications/ServBay/bin/composer validate --strict
PATH=/Applications/ServBay/package/php/8.2/8.2.29/bin:$PATH /Applications/ServBay/bin/composer run validate:larena
PATH=/Applications/ServBay/package/php/8.2/8.2.29/bin:$PATH /Applications/ServBay/bin/composer run test
PATH=/Applications/ServBay/package/php/8.2/8.2.29/bin:$PATH /Applications/ServBay/bin/composer run lint
PATH=/Applications/ServBay/package/php/8.2/8.2.29/bin:$PATH /Applications/ServBay/bin/composer run analyse
PATH=/Applications/ServBay/package/php/8.2/8.2.29/bin:$PATH /Applications/ServBay/bin/composer run evidence:check
PATH=/Applications/ServBay/package/php/8.2/8.2.29/bin:$PATH /Applications/ServBay/bin/composer run scope:check
PATH=/Applications/ServBay/package/php/8.2/8.2.29/bin:$PATH /Applications/ServBay/bin/composer run quality:gate
```

Local PHP limitation: ServBay PHP is `8.2.29`; Larena target remains PHP `^8.3`, so release readiness still requires `php83_release_recheck_required`.
