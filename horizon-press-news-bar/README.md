# Horizon Press News Bar

Extension WordPress affichant une **barre d’actualités fixe en bas du site**, alimentée par les articles publiés dans une **fenêtre temporelle glissante** (par défaut : les dernières 24 heures), filtrés par catégories, étiquettes et exclusions.

- Version : **1.0.0**
- WordPress : **6.6 minimum** (testé sur 7.1)
- PHP : **8.1 à 8.4** (8.3+ recommandé)
- Licence : GPL-2.0-or-later
- Text domain : `horizon-press-news-bar` (français fourni)

Aucune dépendance externe : pas de SaaS, pas d’appel réseau sortant, pas de télémétrie, pas de framework JavaScript, pas de jQuery, pas de CDN, pas de police externe, pas de table SQL, pas de tâche cron.

---

## 1. Règle métier

La barre affiche les articles (`post_type = post`) qui sont **à la fois** :

1. publiés (`publish`, sans mot de passe) ;
2. dans la fenêtre temporelle glissante : `maintenant − durée ≤ date de publication ≤ maintenant`, calculée en **UTC** sur `post_date_gmt` ;
3. conformes aux filtres de contenu (catégories incluses / exclues, étiquettes, articles exclus).

Exemple : à 16:20 avec une fenêtre de 24 heures, seuls les articles publiés depuis la veille à 16:20 sont éligibles.

Ce n’est **jamais** « les articles du jour », « depuis minuit », « les X derniers sans date », « les articles récemment modifiés » ni « les plus populaires ». La date de modification est ignorée. Les articles épinglés (sticky) sont traités comme des articles normaux : un sticky ancien ne contourne pas la fenêtre.

**Barre vide :** si aucun article ne correspond, aucune barre visible n’apparaît. En mode hybride, seul un conteneur technique vide (`#hprnb-root`, attribut `hidden`, aucune feuille de style) est rendu pour permettre l’apparition d’un nouvel article derrière un cache de page.

## 2. Installation

1. Téléverser le dossier `horizon-press-news-bar/` dans `wp-content/plugins/` (ou installer le ZIP via *Extensions → Ajouter → Téléverser*).
2. Activer l’extension. L’activation crée les options `hprnb_settings`, `hprnb_cache_epoch` et `hprnb_schema_version`. Aucun flush des permaliens, aucune table, aucun cron, aucune redirection.
3. Régler la barre dans **Réglages → Barre d’actualités** (capacité `manage_options`).

Si PHP ou WordPress est trop ancien, l’extension refuse de s’activer avec un message clair et ne provoque jamais d’erreur fatale.

Sur un multisite, les réglages sont propres à chaque site ; il n’y a pas d’interface réseau. Une activation réseau initialise les 500 premiers sites ; les autres reçoivent les valeurs par défaut à la volée.

## 3. Réglages

Toutes les valeurs sont validées par un schéma unique (`Settings::schema()`), utilisé à l’identique par le formulaire d’administration, l’import JSON et l’aperçu. Les clés inconnues sont ignorées.

### 3.1 Général

| Clé | Type | Défaut | Bornes / valeurs |
|---|---|---|---|
| `enabled` | booléen | `true` | |
| `label_text` | texte | `TOUTE L’ACTUALITÉ` | 120 caractères max, vide autorisé (label masqué) |
| `label_position` | énumération | `end` | `start`, `end` |
| `window_value` | entier | `24` | selon l’unité |
| `window_unit` | énumération | `hours` | `minutes` (1–1440), `hours` (1–720), `days` (1–30) |
| `categories_include` | liste d’ID | `[]` | vide = toutes les catégories |
| `max_items` | entier | `10` | 1–50 |
| `orderby` | énumération | `date_desc` | `date_desc`, `date_asc` (aucun tri aléatoire) |

### 3.2 Contenu (filtres avancés, repliés par défaut)

| Clé | Type | Défaut |
|---|---|---|
| `categories_exclude` | liste d’ID | `[]` |
| `tags_include` | liste d’ID | `[]` |
| `content_exclude_post_ids` | liste d’ID | `[]` |

### 3.3 Apparence

| Clé | Type | Défaut | Bornes / valeurs |
|---|---|---|---|
| `bg_color` | couleur hex | `#B00000` | |
| `text_color` | couleur hex | `#FFFFFF` | |
| `label_bg_color` | couleur hex | `#8F0000` | |
| `label_text_color` | couleur hex | `#FFFFFF` | |
| `link_hover_color` | couleur hex | `#FFFFFF` | |
| `font_size` | entier (px) | `14` | 10–24 |
| `bar_height` | entier (px) | `44` | 28–120 |
| `z_index` | entier | `99990` | 1–2147483647 |
| `layout_mode` | énumération | `reserve` | `reserve`, `overlay` |
| `show_thumbnail` | booléen | `false` | |
| `thumbnail_size` | clé | `thumbnail` | taille d’image WordPress existante |
| `show_separator` | booléen | `false` | |
| `separator_char` | texte | `•` | 8 caractères max, jamais vide |
| `show_relative_time` | booléen | `false` | |
| `relative_time_max_hours` | entier | `48` | 1–720 |

L’administration calcule le contraste texte / fond et label / fond du label et affiche un avertissement sous 4,5:1, **sans bloquer** l’enregistrement.

### 3.4 Comportement

| Clé | Type | Défaut | Bornes / valeurs |
|---|---|---|---|
| `ticker_enabled` | booléen | `false` | |
| `ticker_mode` | énumération | `marquee` | `marquee`, `rotate`, `manual` |
| `ticker_speed` | entier (px/s) | `60` | 10–300 |
| `rotate_interval` | entier (ms) | `5000` | 1000–60000 |
| `pause_on_hover` | booléen | `false` | |
| `close_button` | booléen | `false` | |
| `remember_dismiss` | booléen | `false` | |
| `dismiss_duration_hours` | entier | `24` | 1–720 |
| `show_on_desktop` | booléen | `true` | |
| `show_on_mobile` | booléen | `true` | |

### 3.5 Visibilité

| Clé | Type | Défaut |
|---|---|---|
| `display_scope` | énumération | `everywhere` (`everywhere`, `custom`) |
| `contexts` | carte de booléens | tous `true` : `front_page`, `blog_home`, `single_post`, `page`, `category`, `tag`, `archive`, `search`, `not_found` |
| `display_exclude_ids` | liste d’ID | `[]` |

### 3.6 Avancé

| Clé | Type | Défaut | Bornes / valeurs |
|---|---|---|---|
| `render_mode` | énumération | `hybrid` | `hybrid`, `php` |
| `cache_ttl` | entier (s) | `120` | 30–600 |
| `stale_threshold` | entier (s) | `180` | 30–3600 |
| `auto_display` | booléen | `true` | |
| `shortcode_enabled` | booléen | `true` | |
| `uninstall_delete_data` | booléen | `false` | |

## 4. Modes de rendu

- **`hybrid` (défaut, recommandé)** : rendu serveur (SSR) + contrôle de fraîcheur côté client. Le conteneur `#hprnb-root` porte `data-hprnb-generated` (horodatage du payload) et `data-hprnb-stale` (seuil). Un petit script (`hprnb-bootstrap`, ≈ 2 Ko minifié) :
  - ne fait **rien** si le HTML est frais (`âge ≤ stale_threshold`) — aucune requête REST ;
  - sinon réutilise un payload `sessionStorage` s’il est valide, encore frais **et au moins aussi récent que le SSR** ;
  - sinon effectue **un seul** appel `GET /wp-json/hprnb/v1/items` ; en cas d’échec le SSR est conservé, sans nouvelle tentative ni message ; une réponse plus ancienne que le SSR est ignorée ;
  - si la réponse est vide, retire la barre et la classe `body.hprnb-reserve` ; sinon charge la feuille de style si nécessaire, injecte le HTML (uniquement si `typeof payload.html === 'string'`) et le script interactif si une option l’exige.
- **`php`** : rendu serveur uniquement, aucun bootstrap. Utile pour diagnostiquer ou sur un site sans cache de page long.

Il n’existe pas de mode « REST seul ».

## 5. Cache serveur et invalidation

- Primitive : **API Transients** (`hprnb_bar_{epoch}_{hash}`), jamais autoloadée, jamais de table personnalisée.
- Le hash dépend des réglages qui influent sur la sélection, l’ordre, le label et le markup, de la locale (`determine_locale()`) et de la version de l’extension. Couleurs, tailles et z-index passent par des variables CSS et ne créent pas de nouveau payload.
- Le payload contient l’`<aside>` complet rendu par le `Renderer` (source unique du HTML pour le SSR, le REST, le shortcode et l’aperçu). Un résultat vide est mis en cache également.
- TTL : `cache_ttl` (30–600 s, défaut 120 s), filtrable via `hprnb_cache_ttl`. Le TTL est court car un article peut sortir de la fenêtre sans aucun événement WordPress.
- Invalidation par **rotation d’epoch** : `hprnb_cache_epoch` reçoit un nouvel UUID ; les anciens transients deviennent inaccessibles et expirent naturellement (au plus 600 s). Déclencheurs : passage vers / hors `publish`, modification d’un article publié, corbeille / restauration / suppression, changement de catégorie ou d’étiquette, suppression d’un terme, changement d’image mise en avant, enregistrement / import / réinitialisation des réglages, changement de thème, désactivation. Une garde statique limite l’opération à une fois par requête PHP. La table `wp_options` n’est jamais parcourue pour supprimer des transients.

### Performance

- **Cache hit** : 0 `WP_Query` de contenu, aucun parcours d’articles, aucun N+1. Avec un object cache persistant, aucun accès SQL propre à l’extension ; **sans** object cache persistant, WordPress lit le transient dans `wp_options` (un accès technique). Cette extension ne promet donc jamais « 0 SQL absolu ».
- **Cache miss** : une requête principale `WP_Query` ; avec les miniatures, deux à trois requêtes supplémentaires bornées (métadonnées, fichiers joints) via `update_post_thumbnail_cache()`, jamais une requête par article.
- Budgets front (minifiés) : CSS ≈ 5,3 Ko, bootstrap ≈ 2,2 Ko, JS interactif ≈ 5,7 Ko. Le JS interactif n’est chargé que si le ticker, le bouton fermer ou l’heure relative est activé.

## 6. Endpoint REST public

`GET /wp-json/hprnb/v1/items` — aucun paramètre, `permission_callback` `__return_true`, lecture seule, aucun cookie lu, aucune session, indépendant de l’utilisateur connecté. Même cache et même `Renderer` que le SSR.

```json
{ "version": "1.0.0", "generated_at": 1757600000, "count": 7, "html": "<aside class=\"hprnb-bar\">…</aside>" }
```

Résultat vide : `"count": 0, "html": ""`. Extension désactivée : idem.

En-têtes ajoutés sur cette réponse uniquement : `Cache-Control: public, max-age=60, s-maxage=60, stale-while-revalidate=120`, `ETag`, `Last-Modified`. Si `If-None-Match` correspond à l’ETag (préfixe `W/` toléré, listes acceptées), le contrôleur renvoie `304` avec un corps vide. Aucun filtre global permanent sur `rest_send_nocache_headers`.

`POST /wp-json/hprnb/v1/preview` est réservé à l’administration (`manage_options` + nonce REST) : il rend un aperçu à partir de réglages non enregistrés, sans écriture ni cache public.

## 7. Shortcode

`[hprnb_news_bar]` (sans attribut), disponible si `shortcode_enabled` est vrai. Il utilise le `Renderer` commun, respecte les exclusions absolues mais ignore volontairement la portée contextuelle et les exclusions par ID (son insertion est explicite).

Anti-double rendu : un seul `#hprnb-root` par page ; si le shortcode est rendu, `wp_footer` ne rend pas de seconde barre ; un second shortcode renvoie une chaîne vide.

## 8. Visibilité

Portée `everywhere` par défaut, ou `custom` avec les contextes cochés. Les identifiants de `display_exclude_ids` masquent la barre sur les articles / pages concernés.

Exclusions absolues, sans réglage : wp-admin, AJAX, cron, REST, XML-RPC, WP-CLI, flux, `robots.txt`, favicon, trackback, oEmbed / embed, prévisualisation, page de connexion, plans de site du cœur, pages AMP (via `amp_is_request()`). Aucune intégration spécifique à un plugin SEO.

Appareils : si `show_on_desktop` et `show_on_mobile` sont tous deux faux, aucun HTML, aucun CSS, aucun JS, aucune requête. Sinon le masquage est **uniquement CSS** (classes `hprnb-hide-mobile` / `hprnb-hide-desktop`, breakpoint fixe **768 px**) ; `wp_is_mobile()` n’est jamais utilisé.

## 9. Disposition `reserve` / `overlay`

- `reserve` (défaut) : la classe `hprnb-reserve` est ajoutée au `<body>` quand une barre est réellement visible ; le CSS réserve `padding-block-end: calc(var(--hprnb-height) + env(safe-area-inset-bottom))`, donc la barre ne recouvre jamais le contenu.
- `overlay` : aucune réservation. **La barre peut recouvrir un élément fixe d’un thème ou d’une autre extension** (barre de cookies, bouton de retour en haut…).

## 10. Ticker (optionnel)

Désactivé par défaut. Trois modes :

- `marquee` : duplication visuelle unique (clone `aria-hidden="true"`, liens `tabindex="-1"`), animation CSS par `transform`, durée calculée d’après la largeur et `ticker_speed`, aucun `setInterval`, pause quand l’onglet est caché, recalcul via `ResizeObserver`, aucune animation si le contenu tient dans la fenêtre, sens inversé automatiquement en RTL.
- `rotate` : un article à la fois (`hidden` sur les autres), intervalle configurable, pause quand l’onglet est caché et au focus.
- `manual` : aucune animation, boutons précédent / suivant, défilement natif tactile avec `scroll-snap`, boutons désactivés aux extrémités.

**Accessibilité obligatoire :** `marquee` et `rotate` affichent toujours un vrai bouton **Pause / Lecture** clavier-accessible avec `aria-label` mis à jour, même si `pause_on_hover` et `close_button` sont désactivés. Avec `prefers-reduced-motion: reduce`, aucune animation automatique ne démarre. `pause_on_hover` reste une option de confort supplémentaire.

## 11. Heure relative, bouton fermer, mémorisation

- **Heure relative** (`show_relative_time`) : `<time datetime="…" data-hprnb-ts="…">il y a 2 heures</time>` rendu par `human_time_diff()` ; au-delà de `relative_time_max_hours`, date absolue au format WordPress. Côté client, recalcul toutes les 60 s via `Intl.RelativeTimeFormat` (langue de la page), timer suspendu quand l’onglet est caché.
- **Bouton fermer** (`close_button`) : SVG inline, cible 44×44 px, `aria-label`. Au clic : la barre est masquée, `body.hprnb-reserve` retirée, le focus déplacé proprement vers le landmark principal (`tabindex="-1"` temporaire si nécessaire).
- **Mémorisation** (`remember_dismiss`) : clé `localStorage` `hprnb_dismissed_until`, aucun cookie. Un micro-script inline dans `<head>` (chargé seulement si l’option est active) ajoute `html.hprnb-dismissed` avant la peinture pour éviter tout flash.

## 12. Accessibilité

Cible WCAG 2.2 AA sur le composant : région nommée (`role="region"`, `aria-label`), `aria-live="off"`, liste sémantique, boutons natifs, focus visible, cibles 44×44, clone de marquee hors de l’ordre de tabulation, mouvement réduit respecté, Pause / Lecture pour toute animation automatique, mode `reserve` par défaut. Le contraste dépend des couleurs choisies : l’administration avertit sous 4,5:1.

## 13. Internationalisation et RTL

- Chaînes sources en anglais, traduction française fournie (`languages/horizon-press-news-bar-fr_FR.po|.mo`) et modèle `.pot`. Les traductions sont chargées sur `init`, jamais avant.
- Cache séparé par locale (`determine_locale()`), `suppress_filters` laissé à `false` pour ne pas neutraliser un plugin multilingue. La compatibilité WPML / Polylang n’est **pas garantie** sans test dans l’environnement concerné.
- RTL : propriétés logiques (`inset-inline`, `padding-inline`, `margin-inline`), `dir="auto"` sur le composant, `label_position = end` = gauche en RTL, sens du marquee inversé automatiquement, administration utilisable en RTL. Une feuille `hprnb-bar-rtl.css` est fournie pour `wp_style_add_data( 'rtl' )`.

## 14. Import / export / réinitialisation

- **Export** : JSON `{ "_meta": { "plugin", "schema_version", "plugin_version", "exported_at" }, "settings": { … } }` (nonce + `manage_options`).
- **Import** : fichier `.json` de 256 Ko maximum, jamais exécuté ni conservé. Contrôles : erreur de téléversement, taille, extension, `json_decode` à profondeur bornée, `_meta.plugin`, `schema_version` compatible, liste blanche pilotée par le schéma, sanitization complète. Le type MIME n’est **pas** un critère (un serveur peut renvoyer `text/plain`). Les clés inconnues sont ignorées ; un avertissement rappelle que les identifiants diffèrent entre sites.
- **Réinitialisation** : case de confirmation + confirmation JS si disponible + nonce + capacité ; restauration exacte des valeurs par défaut et invalidation du cache.

## 15. Hooks développeur

Filtres :

```php
// Réglages effectifs (déjà validés ; le résultat est revalidé).
add_filter( 'hprnb_settings', function ( array $settings ) { $settings['max_items'] = 5; return $settings; } );

// Arguments WP_Query.
add_filter( 'hprnb_query_args', function ( array $args, array $settings ) { $args['author__not_in'] = array( 2 ); return $args; }, 10, 2 );

// Items normalisés avant cache et rendu.
add_filter( 'hprnb_items', function ( array $items, array $settings ) { return array_slice( $items, 0, 3 ); }, 10, 2 );

// Décision finale d’affichage automatique.
add_filter( 'hprnb_should_display', function ( bool $display, array $settings ) { return $display && ! is_user_logged_in(); }, 10, 2 );

// TTL du cache (borné 30–600 s après filtrage).
add_filter( 'hprnb_cache_ttl', fn( int $ttl, array $settings ) => 300, 10, 2 );

// Seuil d’obsolescence client (minimum 30 s).
add_filter( 'hprnb_stale_threshold', fn( int $seconds, array $settings ) => 600, 10, 2 );

// Markup complet de la barre avant mise en cache.
add_filter( 'hprnb_bar_html', fn( string $html, array $items, array $settings ) => $html, 10, 3 );
```

Actions :

```php
add_action( 'hprnb_before_bar', function ( array $items, array $settings ) { /* dans .hprnb-bar__inner, avant le label */ }, 10, 2 );
add_action( 'hprnb_after_bar',  function ( array $items, array $settings ) { /* après les contrôles */ }, 10, 2 );
add_action( 'hprnb_cache_invalidated', function () { /* après rotation de l’epoch */ } );
```

Remarque : `hprnb_bar_html`, `hprnb_before_bar` et `hprnb_after_bar` agissent sur un HTML **mis en cache** ; leur résultat est partagé par tous les visiteurs jusqu’à l’invalidation suivante.

## 16. Surcharge de templates

Copiez `templates/bar.php`, `templates/list.php` ou `templates/item.php` dans `{votre-thème}/horizon-press-news-bar/` (thème enfant prioritaire). Chaque template reçoit un tableau `$context` (`items`, `settings`, ou `item` + `settings`) ; aucune variable n’est extraite dans la portée. Un changement de thème invalide le cache.

## 17. Désinstallation

- `uninstall_delete_data = false` (défaut) : rien n’est supprimé.
- `uninstall_delete_data = true` : suppression des trois options de l’extension (par site en multisite). Aucun article, média ou terme n’est touché ; les transients courts expirent d’eux-mêmes.

La désactivation conserve les réglages et fait tourner l’epoch de cache.

## 18. Limites connues

- Sans JavaScript, le mode hybride affiche le SSR tel quel (potentiellement vieilli derrière un cache de page) ; le texte d’heure relative n’est pas recalculé.
- La compatibilité WPML / Polylang n’est pas garantie sans test réel.
- Le mode `overlay` peut recouvrir un élément fixe tiers.
- Le micro-script anti-flash ne couvre pas une barre insérée uniquement par shortcode sur une page non éligible à l’affichage automatique (la barre reste masquée par le script interactif dès son exécution).
- Testé sur PHP 8.4 dans l’environnement de recette ; la compatibilité 8.1–8.3 est vérifiée statiquement (PHPCompatibilityWP).
