# infopro — Horizon Press News Bar

Dépôt de développement de l’extension WordPress **Horizon Press News Bar** (barre d’actualités à fenêtre temporelle glissante).

| Dossier / fichier | Rôle |
|---|---|
| `horizon-press-news-bar/` | **Source de l’extension** (contenu du ZIP de production) — voir son `README.md` |
| `dist/horizon-press-news-bar-<version>.zip` | ZIP installable (`bin/build-zip.sh`), nommé d'après la version |
| `QA-REPORT.md` | Rapport de recette (tests exécutés, résultats, limites) |
| `docs/cahier-des-charges.md` | Cahier des charges (source de vérité) |
| `docs/dev/CONTRACT.md` | Contrat d’implémentation (API des classes, markup, JS) |
| `tests/phpunit/` | Tests PHPUnit sur la suite de tests WordPress (hors ZIP) |
| `tests/e2e/` | Tests Playwright (Chromium) sur un site WordPress réel (hors ZIP) |
| `bin/` | Outils : minification (`build-assets.mjs`), i18n (`extract-strings.php`, `build-i18n.php`, `fr_FR.php`), ZIP (`build-zip.sh`) |

## Outils de développement

```bash
composer install                 # PHPUnit, PHPCS/WPCS, PHPCompatibility, gettext
npm install                      # esbuild, @playwright/test, axe-core
node bin/build-assets.mjs        # génère les .min.* et hprnb-bar-rtl.css
php bin/build-i18n.php           # génère .pot / fr_FR.po / fr_FR.mo
vendor/bin/phpcs --standard=phpcs.xml.dist horizon-press-news-bar
WP_TESTS_DIR=/chemin/wordpress-develop/tests/phpunit vendor/bin/phpunit -c tests/phpunit/phpunit.xml.dist
npx playwright test -c tests/e2e/playwright.config.mjs   # site WordPress attendu sur http://127.0.0.1:8080 (admin/admin)
bin/build-zip.sh                 # dist/horizon-press-news-bar-<version>.zip
```

Les tests e2e s’appuient sur WP-CLI (`/opt/wp/bin/wp-cli.phar`) pour changer les réglages entre scénarios et sur deux mu-plugins du site de développement (`?hprnb_rtl=1`, `?hprnb_lang=fr_FR`, et depuis la 2.15 `?hprnb_classic=1` qui force l’éditeur classique sur l’écran d’édition) qui ne font pas partie de l’extension.
