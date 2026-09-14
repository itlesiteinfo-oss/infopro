# Rapport de recette — Horizon Press News Bar 1.0.0

Date : 12 septembre 2026 — Livrable : `dist/horizon-press-news-bar.zip` (51 fichiers).

> **Addendum 3 — version 1.2.0 (présentation mobile « 2026 »)** : sous 768 px (container query `@container hprnb` sur `#hprnb-root`, vérifiée compatible avec `position: fixed` dans Chromium) la barre adopte une **disposition empilée** paramétrable : pastille de label avec point « en direct », compteur « 2/8 », titre sur toute la largeur (16 px, 2 lignes max.), **rotation** par défaut avec ligne de progression, balayage tactile (sans glisser-déposer natif ni clic parasite), repli au défilement (focus clavier = maintien ouvert 4 s) et palette mobile dédiée (`#141414` / `#F5F5F5` / accent `#E11D2A` / label `#FFFFFF`). Quinze nouveaux réglages `mobile_*` (section « Mobile » de l’administration, dépendances grisées, contraste mobile calculé, aperçu Ordinateur / Mobile animé par le script du front) ; seul `mobile_ticker_mode` entre dans la clé de cache, le reste est porté par `#hprnb-root` hors cache (§9.2, §15.5 du cahier des charges v2.2). Vérifications **réellement exécutées** sur cette version : PHPUnit **125 tests, 1 149 assertions, 0 échec** (nouveaux cas : classes/variables/JSON mobile du root, `mobile_ticker()`, `needs_interactive_js()`, clé de cache, style inline `--hprnb-m-height`, schéma des 60 clés) ; Playwright **23 scénarios, 23 réussis** (nouveau scénario mobile empilé : pastille, compteur, progression, rotation, balayage, repli/redéploiement, palette, hauteur 76 px, franchissement 375 ↔ 1366 px ; aperçu admin Mobile/Ordinateur ; axe-core 0 violation) ; PHPCS WordPress 0 erreur / 0 avertissement ; PHPCompatibilityWP 8.0–8.4 0 erreur ; Plugin Check 0 erreur, mêmes 2 avertissements documentés. Budgets minifiés : CSS **9,5 Ko** (objectif révisé à 10 Ko dans le cahier des charges, 8 Ko avant la présentation mobile), bootstrap 2,3 Ko, JS interactif 9,2 Ko. Limites : exécution réelle sur PHP 8.4 et Chromium uniquement (pas de Safari iOS ni de Firefox Android disponibles dans le conteneur) ; sans JavaScript, la présentation mobile affiche la liste complète et masque les contrôles ; `color-mix()` et `backdrop-filter` se dégradent en couleur opaque sur les navigateurs antérieurs à 2023.
>
> **Addendum 2 — version 1.1.0 (réglage `separator_after_last`)** : le séparateur est devenu un pseudo-élément CSS `::after` piloté par `hprnb-bar--sep` / `hprnb-bar--sep-loop` et `--hprnb-sep` sur `#hprnb-root` (hors cache) ; `separator_after_last` (défaut `true`, ignoré si `show_separator = false`, grisé dans l’admin) répète le séparateur après le dernier article. Vérifications rejouées : PHPUnit **124 tests, 1 079 assertions** (nouveaux cas : classes du root, échappement de `--hprnb-sep`, réglages du séparateur absents de la clé de cache, `after_last` ignoré si séparateur désactivé) ; Playwright **22 scénarios réussis** dont le nouveau scénario « 4 modes × LTR/RTL × activé/désactivé » (16 combinaisons : statique, marquee, rotate, manual ; jonction clone → original du marquee avec exactement un séparateur, zéro sans l’option ; aucun séparateur en rotate ; aucun débordement horizontal ; espacement de la jonction de boucle égal à l’espacement entre items) et les bascules de l’aperçu admin sans appel serveur ; PHPCS 0 erreur ; CSS minifié 5 792 octets (≤ 8 Ko). Le ZIP `dist/horizon-press-news-bar.zip` est reconstruit en 1.1.0.
>
> **Addendum 1 (même jour)** : à la demande du client (serveur de production en PHP 8.0.30), le prérequis PHP a été abaissé de 8.1 à **8.0** (`Requires PHP: 8.0`, `HPRNB_MIN_PHP`). Aucune syntaxe propre à PHP 8.1+ n’est utilisée ; PHPCompatibilityWP 8.0–8.4 : 0 erreur ; PHPCS et PHPUnit (123 tests) rejoués sans régression. L’exécution réelle reste sur PHP 8.4 uniquement ; PHP 8.0 n’étant plus maintenu, une mise à niveau vers 8.3+ est recommandée.

Ce rapport ne mentionne comme « réussi » que ce qui a **réellement été exécuté** dans l’environnement décrit ci-dessous. Les points non exécutables sont listés explicitement en fin de document.

## 1. Environnement de recette

| Élément | Valeur |
|---|---|
| WordPress | **7.1** (miroir GitHub `WordPress/WordPress`, tag `7.1`) |
| Suite de tests WordPress | `wordpress-develop` tag `7.1.0` (`tests/phpunit`) |
| PHP | **8.4.19** (CLI) — seule version disponible dans le conteneur |
| Base de données | **SQLite** via *SQLite Database Integration* 3.0.2 (aucun serveur MySQL disponible ; c’est la configuration utilisée par la CI du cœur WordPress) |
| Object cache persistant | **absent** (transients dans `wp_options`) |
| Thème | Twenty Twenty-Five (block theme) ; thèmes classiques disponibles mais non utilisés en recette automatisée |
| Serveur | PHP built-in server (8 workers), `http://127.0.0.1:8080`, permaliens `/%postname%/`, `WP_DEBUG = true`, `WP_DEBUG_LOG` |
| Navigateurs | **Chromium 141** (Playwright 1.56.1). Firefox et WebKit : téléchargement bloqué par le réseau du bac à sable |
| Outils | php -l ; PHP_CodeSniffer 3.13.5 + WordPress Coding Standards 3.4.1 + PHPCompatibilityWP 2.1.x ; Plugin Check 2.1.0 ; PHPUnit 9.6.36 + Yoast PHPUnit Polyfills 1.1.5 ; Playwright 1.56.1 ; axe-core 4.x ; esbuild 0.25 (minification) ; gettext/gettext 5.7 (.mo) |

Réseau sortant : `wordpress.org` et `api.github.com` sont bloqués par la politique du bac à sable ; aucune fonctionnalité de l’extension n’en dépend (aucun appel réseau sortant dans le code).

## 2. Contrôles statiques exécutés

| Contrôle | Commande | Résultat |
|---|---|---|
| Syntaxe PHP | `php -l` sur les 24 fichiers PHP du plugin | **0 erreur** |
| WordPress Coding Standards | `phpcs --standard=phpcs.xml.dist horizon-press-news-bar` (règles `WordPress` + `PHPCompatibilityWP`, préfixes `hprnb`, text domain) | **0 erreur, 0 avertissement** |
| Compatibilité PHP 8.0 → 8.4 | `phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.0-8.4` | **0 erreur** (6 avertissements « fichier minifié non analysable » sur les `.min.css/.min.js`, sans objet) |
| Plugin Check 2.1.0 | `wp plugin check horizon-press-news-bar` (contrôles statiques et runtime, plugin actif) | **0 erreur**, 2 avertissements acceptés : (a) `load_plugin_textdomain()` « discouraged » pour les extensions hébergées sur WordPress.org — extension privée avec fichiers locaux, appel sur `init` conformément au cahier des charges ; (b) `post__not_in` (règle VIP) — uniquement ajouté lorsque l’administrateur exclut des articles, jamais un tableau vide |
| Règles statiques du cahier des charges (test PHPUnit `Static_Rules_Test`) | scan de tous les fichiers PHP/JS/CSS livrés | aucun `eval`, `extract`, `$_REQUEST`, `RAND()`, `wp_is_mobile`, cron, `dbDelta`, `wp_remote_*`, `add_image_size`, autoload `'yes'/'no'` ; garde `ABSPATH` partout ; aucun `console.log`, jQuery, `eval`, URL externe dans le JS ; aucun `@import`/URL externe en CSS ; `get_option( 'hprnb_settings' )` uniquement dans `class-settings.php` ; budgets d’assets respectés ; traductions chargées sur `init` |
| Journal `debug.log` (`WP_DEBUG = true`) | pendant toute la recette (front, admin, REST, WP-CLI, e2e) | **0 fatal, 0 warning, 0 notice, 0 deprecated** provenant du plugin (seules entrées : WP-CLI/Mustache sur PHP 8.4 et échecs de connexion à wordpress.org, hors périmètre) |

## 3. Tests automatisés exécutés

### 3.1 PHPUnit (suite WordPress 7.1, SQLite) — `tests/phpunit/`

Commande : `phpunit -c tests/phpunit/phpunit.xml.dist` — **123 tests, 1 058 assertions, 0 échec, 0 erreur** (2,3 s).

| Fichier | Couverture (cahier des charges §34) |
|---|---|
| `smoke-test.php` | chargement, activation (autoload booléen), scénario par défaut, REST 200, root vide |
| `settings-test.php` | valeurs par défaut identiques au JSON du §5, schéma, clés inconnues ignorées, booléens stricts, bornes entières, bornes de fenêtre par unité, enums, couleurs, label (XSS, longueur), séparateur, listes d’ID (dédoublonnage, plafond 500), `thumbnail_size`, carte `contexts`, sémantique formulaire, idempotence, filtre `hprnb_settings`, update/reset, option corrompue, flag de désinstallation |
| `time-window-test.php` | unités minutes/heures/jours, calcul UTC, **passage DST** (mars et octobre), forme de `date_query`, bornes 23h59/24h01, 30 min, 3 jours, date de modification ignorée, fuseau du site ≠ serveur (New York, Auckland), contenu daté dans le futur exclu |
| `query-test.php` | arguments de base exacts, listes uniquement si non vides, flags de cache term/meta, filtre `hprnb_query_args`, brouillon/privé/en attente/futur/protégé/page exclus, **sticky** ancien exclu et récent trié normalement, ordre et limite, catégories incluses/multiples/exclues, étiquettes, ID exclus, titre vide écarté, forme de l’item normalisé, **miniatures sans N+1** (6 items = même nombre de requêtes que 1 item), repli de taille, filtre `hprnb_items` |
| `cache-test.php` | résultat vide mis en cache, **hit = 0 WP_Query contenu, miss = 1**, mémo requête, format et composants de la clé (couleurs/tailles hors hash, label/fenêtre/ticker/locale dans le hash), TTL filtré et borné, payloads invalides ignorés, epoch créé à la demande, invalidation à la publication (une seule fois par requête), brouillon sans invalidation, édition/dépublication/corbeille/restauration/suppression, catégories/étiquettes/suppression de terme, réglages, changement de thème, image mise en avant, ancien payload inaccessible |
| `renderer-test.php` | zéro item → aucun HTML, markup par défaut (région, `aria-live="off"`, `dir="auto"`), label vide/position, miniatures/séparateur/heure relative uniquement si actifs, contrôles marquee/rotate/manual/fermer, **échappement XSS** titres/URL/label/séparateur, filtre `hprnb_bar_html` et actions, root hybride vs PHP, classes device, variables CSS, seuil filtré, `needs_interactive_js`, heure relative serveur, **surcharge de template thème**, icônes |
| `visibility-test.php` | portée `everywhere`, désactivation, appareils, contextes custom (accueil, article, page, catégorie, recherche, 404), exclusion par ID, flux/embed exclus, filtre `hprnb_should_display` |
| `frontend-test.php` | hybride sans item (root caché, bootstrap seul), PHP sans item (rien), hybride avec items (CSS + style inline + pas de JS interactif, RTL `replace`, stratégie `defer`), PHP avec ticker (JS interactif), overlay sans classe body, inéligible = 0 requête et 0 asset, `auto_display` off, micro-script anti-flash uniquement si mémorisation, **shortcode avant footer = un seul root** |
| `rest-test.php` | routes, 200 + en-têtes (Cache-Control, ETag, Last-Modified), count 0, extension désactivée, même cache que le SSR, **ETag/304** (exact, `W/`, liste), aperçu refusé aux anonymes (401) et éditeurs (403), aperçu admin sans écriture ni cache public, paramètre manquant → 400 |
| `import-export-test.php` | structure d’export, import liste blanche + sanitization + invalidation, avertissement IDs, rejets (JSON invalide, plugin inconnu, schéma trop récent, taille, profondeur), contrôles de fichier téléversé, réinitialisation |
| `shortcode-test.php` | enregistrement, un seul root, deuxième shortcode vide, PHP sans item → vide et root non consommé, hybride → root caché, désactivations, portée ignorée mais exclusions absolues respectées |
| `lifecycle-test.php` | activation idempotente, désactivation (réglages conservés, epoch tourné), prérequis, **désinstallation** conditionnelle (options supprimées, articles conservés) |
| `security-test.php` | export/import/reset refusés sans capacité (403) ou sans nonce (403), abonné avec nonce valide refusé, permission de l’aperçu, REST public sans contenu privé/brouillon/protégé même pour un administrateur connecté |
| `static-rules-test.php` | voir §2 |

### 3.2 Playwright / Chromium — `tests/e2e/`

Commande : `npx playwright test -c tests/e2e/playwright.config.mjs` — **21 scénarios, 21 réussis** (≈ 50 s), sur le site WordPress 7.1 réel (thème Twenty Twenty-Five).

| Scénario | Points vérifiés |
|---|---|
| Desktop | barre `position: fixed` collée en bas (44 px), `body.hprnb-reserve` + padding ≥ 44 px, couleurs, 5–10 items, label, bootstrap `defer` présent, aucun JS interactif ni bouton par défaut, aucun overflow horizontal, **0 erreur console** |
| HTML frais / obsolète | frais → **0 requête REST** ; obsolète (`data-hprnb-generated` réécrit) → **exactement 1 requête**, `generated_at` mis à jour |
| sessionStorage / échec / réponse ancienne | payload de session plus récent et frais → appliqué sans fetch ; **plus ancien que le SSR → jamais appliqué** ; endpoint 500 → SSR conservé, un seul appel, aucune erreur JS ; réponse plus ancienne que le SSR → ignorée |
| Injection hybride | SSR vide obsolète + nouvel article → barre injectée, `<link>` CSS et `<script>` interactif ajoutés, classe body ajoutée, bouton fermer fonctionnel sur la barre injectée |
| Disparition | SSR obsolète + résultat REST vide → barre retirée, root caché, classe body retirée |
| Mobile 375 px | une ligne, label ≤ min(38vw, 220px), hauteur 44 px, pas d’overflow ; `show_on_mobile=false` → masquée et padding 0 (CSS seul) ; `show_on_desktop=false` → inverse |
| Responsive | 320, 375, 390, 430, 768, 1366, 1920 px : barre visible, hauteur 44 px, aucun overflow (avec séparateur + heure relative) |
| RTL | site RTL + label/article arabes : `direction: rtl`, feuille `hprnb-bar-rtl.min.css`, label « end » à gauche, classe `hprnb-bar--rtl`, keyframe `hprnb-marquee-rtl` ; contenu latin sur site LTR : label à droite, keyframe LTR |
| Marquee | clone `aria-hidden` avec liens `tabindex="-1"`, `--hprnb-duration`, animation `running`, bouton Pause/Lecture 44×44 avec `aria-label` mis à jour, pause utilisateur persistante, pause au survol, pause au focus, pause onglet caché, contenu court → aucune animation ni clone, bouton masqué |
| Mouvement réduit | `prefers-reduced-motion: reduce` : aucune animation, classe `--reduced`, bouton masqué ; rotation : tous les items visibles |
| Rotation | un item visible, avance après l’intervalle, pause via bouton et au focus |
| Manuel | précédent désactivé au départ, suivant fait défiler, extrémités désactivées, contenu court → les deux désactivés, `scroll-snap` |
| Fermer | clavier (Entrée), barre masquée, classe body retirée, focus déplacé, sans `localStorage` ; avec mémorisation : micro-script `#hprnb-dismiss`, clé `hprnb_dismissed_until`, rechargement → `html.hprnb-dismissed`, root masqué, padding 0, **0 requête REST** ; suppression de la clé → barre de retour |
| Heure relative / séparateur | `<time data-hprnb-ts>` avec texte relatif, séparateur personnalisé masqué sur le dernier item |
| Mode PHP et états vides | PHP : pas de bootstrap ni d’endpoint ; PHP + 0 item → aucun `#hprnb-root`, aucun CSS ; hybride + 0 item → root caché sans CSS, hauteur 0 |
| Shortcode | page avec deux shortcodes → un seul root, une seule barre, classe body |
| Administration | assets absents du tableau de bord, présents sur la page du plugin ; aperçu réel en `position: static` ; label/couleur/position mis à jour **sans requête** ; avertissement de contraste ; actualisation via `POST /hprnb/v1/preview` ; enregistrement ; **export** (nom de fichier, JSON) ; **import** d’un fichier modifié (HTML retiré, clé inconnue ignorée, avertissement IDs) ; **réinitialisation** avec confirmation ; 0 erreur console (hors avatars Gravatar bloqués par le réseau du bac à sable) |
| Locale française | `aria-label="Dernières actualités"`, libellés admin traduits (`.mo` embarqué) |
| Horloge client incohérente | horloge du navigateur 3 jours en retard → SSR considéré frais, **0 requête**, aucune boucle |
| Admin en RTL | page utilisable, aperçu à gauche, feuille RTL du composant, aucun overflow, mise à jour du label |
| Accessibilité | **axe-core (WCAG 2.0/2.1/2.2 A/AA + bonnes pratiques) : 0 violation** sur 4 variantes (défaut ; marquee + fermer + heure + miniatures + séparateur ; rotation ; manuel) ; focus clavier visible (outline ≥ 2 px) sur liens et boutons ; activation du bouton Pause au clavier (Espace) ; le clone du marquee n’est jamais atteint par Tab |

### 3.3 Vérifications manuelles complémentaires (exécutées via curl / WP-CLI)

- Activation via WP-CLI sur le site ; **installation depuis le ZIP** (`wp plugin install dist/horizon-press-news-bar.zip --force --activate`) puis rendu de la page d’accueil : root + `<aside>` présents.
- Page d’accueil : root avec tous les attributs `data-hprnb-*`, style inline des variables CSS, CSS minifié, bootstrap `defer`, `body.hprnb-reserve`, style inline `body.hprnb-reserve{--hprnb-height:44px}`.
- REST `GET /wp-json/hprnb/v1/items` : `Cache-Control: public, max-age=60, s-maxage=60, stale-while-revalidate=120`, `ETag`, `Last-Modified` ; `If-None-Match` → **304 avec corps vide** (0 octet).
- Traduction française : `?hprnb_lang=fr_FR` (helper du site de dev) → `aria-label="Dernières actualités"`, `<html lang="fr-FR">`, JSON REST traduit.
- Contenu du ZIP inspecté : aucun `.git`, `node_modules`, `vendor`, tests, captures, sauvegardes, fichiers temporaires, secrets ni métadonnées d’IDE.

## 4. Rapport de performance (§37)

| Mesure | Valeur |
|---|---|
| CSS principal minifié | **5 312 octets** (objectif ≤ 8 Ko) — RTL : 5 312 octets |
| Bootstrap hybride minifié | **2 234 octets** (objectif ≤ 3 Ko) |
| JS interactif minifié | **5 727 octets** (objectif ≤ 10 Ko) |
| Admin (page du plugin uniquement) | CSS 2 323 octets, JS 5 915 octets |
| `WP_Query` contenu sur **cache hit** | **0** (PHPUnit `test_cache_hit_runs_no_content_query…` ; site réel : `content_queries=0`) |
| `WP_Query` contenu sur **cache miss** | **1** (PHPUnit ; site réel : `content_queries=1`) |
| Accès SQL techniques **sans** object cache persistant | hit : **1 requête** (lecture du transient dans `wp_options`) ; miss : 3 requêtes liées au transient (lecture + écriture) ; REST hit : 1 |
| Avec object cache persistant | **non testé** (aucun object cache persistant disponible dans l’environnement) |
| Requêtes REST par chargement | HTML frais : **0** ; HTML obsolète : **1** ; échec : 1 sans nouvelle tentative (Playwright) |
| N+1 | **aucun** : avec miniatures, 6 items produisent le même nombre de requêtes qu’un item (5 requêtes bornées : IDs, posts, meta, attachements, meta attachements) |
| CLS observé (Chromium, réseau idle) | **0,0001** à 1366×800, **0,0000** à 375×667 |
| Erreurs console | **0** sur tous les scénarios front (les seules erreurs vues en admin sont les avatars Gravatar bloqués par le réseau du bac à sable, hors plugin) |

Aucune promesse de « 0 SQL total » : sans object cache persistant, WordPress lit le transient dans `wp_options` (une requête technique sur hit).

## 5. Checklist de recette (§35)

Légende : ✅ exécuté et conforme · ⚠️ exécuté avec réserve · ⛔ non exécutable dans l’environnement.

**Fenêtre** — 23h59 dans fenêtre 24h ✅ · 24h01 ✅ · 30 min ✅ · 3 jours ✅ · date modifiée aujourd’hui mais publication ancienne ✅ · fuseau différent du serveur ✅

**Contenu** — toutes catégories ✅ · une catégorie ✅ · plusieurs ✅ · catégorie exclue ✅ · tag ✅ · post ID exclu ✅ · sticky ancien ✅ · sticky récent ✅ · password protected ✅ · draft/private/future ✅

**Vide** — PHP mode + 0 item → aucun HTML ✅ · hybrid + 0 item → root invisible uniquement ✅ · hybrid page ancienne + nouvel article → apparition ✅ · dernier article sort de fenêtre → disparition ✅

**Cache** — hit sans WP_Query ✅ · miss une seule requête principale ✅ · aucun N+1 ✅ · publication → invalidation ✅ · changement catégorie → invalidation ✅ · settings → invalidation ✅ · cache vide mis en cache ✅

**Hybrid** — HTML frais aucun fetch ✅ · stale un fetch max ✅ · sessionStorage plus ancien jamais appliqué ✅ · plus récent appliqué ✅ · endpoint 500 → SSR conservé ✅ · horloge client incohérente → pas de boucle ✅ · réponse plus ancienne → ignorée ✅

**Ticker** — disabled → aucun JS ticker ✅ · marquee boucle propre ✅ · rotate ✅ · manual ✅ · contenu trop court → pas d’animation ✅ · Pause/Lecture ✅ · hover ✅ · focus ✅ · document hidden ✅ · reduced motion ✅

**Responsive** — 320 ✅ · 375 ✅ · 390 ✅ · 430 ✅ · 768 ✅ · 1366 ✅ · 1920 ✅ · aucun overflow horizontal ✅

**RTL** — label end ✅ · ordre ✅ · ticker ✅ · contrôles ✅ (ordre logique, `dir="auto"`) · admin ✅

**A11y** — clavier ✅ · VoiceOver/NVDA ⛔ (non disponibles) · axe ✅ (axe-core 4, 0 violation) · contraste ✅ (avertissement admin + calcul PHP) · zoom 200 % ⚠️ (équivalent vérifié via largeurs 320–768 px et tailles relatives ; pas de zoom navigateur réel) · focus jamais masqué ✅

**Sécurité** — XSS label ✅ · XSS titre ✅ · import clé inconnue ✅ · import HTML/script ✅ · nonce absent ✅ · capability absente ✅ · REST preview anonyme refusé ✅

## 6. Tests non exécutés / non exécutables

- **PHP 8.0, 8.1, 8.2, 8.3** : aucun binaire disponible dans le conteneur (téléchargements bloqués). Compatibilité vérifiée **statiquement** (PHPCompatibilityWP 8.0–8.4, 0 erreur) et exécution réelle sur PHP 8.4 uniquement.
- **MySQL / MariaDB** : aucun serveur disponible ; toute la recette tourne sur SQLite (couche officielle de l’équipe Performance WordPress). Le plugin n’utilise aucune requête SQL manuelle (WP_Query, options, transients uniquement).
- **Firefox et WebKit** : navigateurs Playwright non téléchargeables ; recette navigateur sur Chromium seulement. Safari iOS non testé.
- **Object cache persistant** (Redis/Memcached) : non disponible ; comportement « 0 SQL propre au plugin sur hit » non mesuré.
- **VoiceOver / NVDA** : pas de lecteur d’écran dans l’environnement ; sémantique vérifiée par axe-core et lecture du markup.
- **Zoom 200 %** natif du navigateur : non exécuté (équivalent par largeurs réduites).
- **WPML / Polylang** : non installés ; compatibilité non garantie (documentée).
- **Thème classique** : la recette automatisée utilise Twenty Twenty-Five (block theme) ; le code n’a aucune dépendance au type de thème (`wp_head`, `wp_footer`, `body_class`), mais aucun test navigateur n’a été exécuté sur un thème classique.
- **Multisite** : activation réseau et désinstallation multisite couvertes par le code (boucle bornée à 500 sites) mais non exécutées (suite single-site).

## 7. Limites connues

- Sans JavaScript, le mode hybride affiche le SSR tel quel ; l’heure relative n’est pas recalculée.
- Le mode `overlay` peut recouvrir un élément fixe tiers (documenté dans l’administration et le README).
- Le micro-script anti-flash n’est imprimé que lorsque l’affichage automatique est éligible ou qu’un shortcode est détecté dans le contenu singulier courant.
- `dir="auto"` détermine le sens du composant à partir de son premier caractère fort (le label) : un label latin sur un site RTL donne une barre LTR, conformément à la spécification (`dir="auto"` imposé).
- Plugin Check : deux avertissements acceptés (voir §2).

## 8. Corrections effectuées pendant la recette

- Sanitization booléenne trop permissive (`'nope'` → vrai) → conversion stricte.
- Shortcode consommant le root unique même lorsqu’il ne rendait rien (mode PHP sans item) → root réclamé uniquement après décision de rendu.
- Restauration depuis la corbeille non prise en compte par l’invalidation → hooks `trashed_post` / `untrashed_post` ajoutés.
- Bootstrap hybride : sur un site RTL, WordPress imprime la feuille avec l’identifiant `hprnb-bar-rtl-css` ; la détection de feuille déjà chargée ignorait cet identifiant (risque de double `<link>`) → corrigé.
- Feuille RTL admin déclarée mais inexistante (404 en admin RTL) → la feuille admin n’utilise que des propriétés logiques, déclaration `rtl` retirée.
- Boutons Import/Réinitialiser partageant l’identifiant `submit` avec le bouton principal (HTML invalide) → identifiants distincts.
- `readme.txt` réécrit en anglais (règle WordPress.org contrôlée par Plugin Check) ; le `README.md` reste en français.

## 9. Définition de « terminé » (§40)

1. Plugin créé ✅ · 2. Syntaxe validée ✅ · 3. Tests exécutés ✅ · 4. Erreurs corrigées ✅ · 5. Tests rejoués ✅ (PHPUnit 123/123, Playwright 21/21) · 6. ZIP construit ✅ · 7. Contenu du ZIP inspecté ✅ · 8. Activation vérifiée (WP-CLI, PHPUnit, installation depuis le ZIP) ✅ · 9. Scénario par défaut vérifié (site réel + tests) ✅ · 10. `QA-REPORT.md` produit ✅

**Aucun TODO bloquant : oui.**
