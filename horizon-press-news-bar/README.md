# Horizon Press News Bar

Extension WordPress affichant une **barre d’actualités fixe en bas du site**, alimentée par les articles publiés dans une **fenêtre temporelle glissante** (par défaut : les dernières 24 heures), filtrés par catégories, étiquettes et exclusions.

- Version : **1.2.0**
- WordPress : **6.6 minimum** (testé sur 7.1)
- PHP : **8.0 à 8.4** (8.3+ recommandé ; PHP 8.0 n’est plus maintenu par PHP.net, une mise à niveau est conseillée)
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

Si PHP (< 8.0) ou WordPress (< 6.6) est trop ancien, l’extension refuse de s’activer avec un message clair et ne provoque jamais d’erreur fatale.

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
| `separator_after_last` | booléen | `true` | sans effet si `show_separator` est faux |
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
- Le hash dépend des réglages qui influent sur la sélection, l’ordre, le label et le markup, de la locale (`determine_locale()`) et de la version de l’extension. Couleurs, tailles, z-index et réglages du séparateur (`show_separator`, `separator_char`, `separator_after_last`) passent par des variables et classes CSS sur `#hprnb-root` et ne créent pas de nouveau payload.
- Le payload contient l’`<aside>` complet rendu par le `Renderer` (source unique du HTML pour le SSR, le REST, le shortcode et l’aperçu). Un résultat vide est mis en cache également.
- TTL : `cache_ttl` (30–600 s, défaut 120 s), filtrable via `hprnb_cache_ttl`. Le TTL est court car un article peut sortir de la fenêtre sans aucun événement WordPress.
- Invalidation par **rotation d’epoch** : `hprnb_cache_epoch` reçoit un nouvel UUID ; les anciens transients deviennent inaccessibles et expirent naturellement (au plus 600 s). Déclencheurs : passage vers / hors `publish`, modification d’un article publié, corbeille / restauration / suppression, changement de catégorie ou d’étiquette, suppression d’un terme, changement d’image mise en avant, enregistrement / import / réinitialisation des réglages, changement de thème, désactivation. Une garde statique limite l’opération à une fois par requête PHP. La table `wp_options` n’est jamais parcourue pour supprimer des transients.

### Performance

- **Cache hit** : 0 `WP_Query` de contenu, aucun parcours d’articles, aucun N+1. Avec un object cache persistant, aucun accès SQL propre à l’extension ; **sans** object cache persistant, WordPress lit le transient dans `wp_options` (un accès technique). Cette extension ne promet donc jamais « 0 SQL absolu ».
- **Cache miss** : une requête principale `WP_Query` ; avec les miniatures, deux à trois requêtes supplémentaires bornées (métadonnées, fichiers joints) via `update_post_thumbnail_cache()`, jamais une requête par article.
- Budgets front (minifiés) : CSS ≈ 24,7 Ko (profils, carte mobile v2, scénarios et compatibilité Jannah compris), bootstrap ≈ 2,4 Ko, JS interactif ≈ 12 Ko. Le JS interactif n’est chargé que si le ticker, le bouton fermer, l’heure relative, le mode de défilement mobile ou le repli au défilement en a besoin.

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

## 9 bis. Séparateur

Le séparateur entre articles est un **pseudo-élément CSS** (`.hprnb-bar__item::after`), jamais un élément du DOM : le caractère vient de la variable `--hprnb-sep` et l’activation des classes `hprnb-bar--sep` / `hprnb-bar--sep-loop` portées par `#hprnb-root`, assemblé hors cache. `separator_after_last` (actif par défaut, sans effet si `show_separator` est faux) répète le même séparateur après le dernier article, de sorte que la jonction dernier → premier (boucle du marquee) soit identique aux autres ; en mode `rotate` aucun séparateur n’est affiché. Ces trois réglages n’entrent pas dans la clé de cache.

## 9 ter. Profils de présentation (ordinateur / mobile, paramétrables)

Deux profils aux réglages identiques : **ordinateur** (à partir de 768 px, clés `desktop_*`) et **mobile** (sous 768 px via une container query sur `#hprnb-root`, clés `mobile_*`). Chaque profil choisit la **position du label** (devant le titre sur la même ligne, ou sur sa propre ligne au-dessus), le **style du label** (bandeau aux couleurs du label, pastille arrondie en capitales, masqué) et son **point « en direct »** pulsant, le **compteur** « 2/8 » (rotation), le **nombre de lignes de titre** (1 à 4, dont découle la hauteur de la barre : `max(bar_height, lignes × ⌈police × 1,3⌉ + 12 [+ 26 avec le label sur sa ligne])`) et la **ligne de progression** (rotation, piste de 2 px sur le bord supérieur). En rotation le titre unique est tronqué à N lignes ; en statique/manuel chaque article devient une carte multi-lignes à défilement horizontal ; le marquee reste sur une ligne. Le design empilé « mobile » est donc disponible tel quel sur ordinateur.

Par défaut, l’ordinateur garde la barre classique (label bandeau devant le titre, une ligne, 44 px) et le mobile adopte la disposition empilée : pastille, compteur, titre 16 px sur deux lignes (80 px), **rotation** avec progression, balayage tactile, **repli au défilement**, palette dédiée (`#141414` translucide flouté, texte `#F5F5F5`, accent `#E11D2A`, label `#FFFFFF`). Sur mobile, le label en ligne précède toujours le titre et le séparateur n’est affiché que sur demande.

| Clé | Type | Défaut | Valeurs |
|---|---|---|---|
| `desktop_layout` / `mobile_layout` | énumération | `inline` / `stacked` | `inline`, `stacked` |
| `desktop_label_style` / `mobile_label_style` | énumération | `strip` / `pill` | `strip`, `pill`, `hidden` |
| `desktop_label_dot` / `mobile_label_dot` | booléens | `false` | |
| `desktop_show_counter` / `mobile_show_counter` | booléens | `false` / `true` | rotation |
| `desktop_lines` / `mobile_lines` | entiers | `1` / `2` | 1–4 |
| `desktop_show_progress` / `mobile_show_progress` | booléens | `true` | rotation |
| `mobile_font_size` | entier (px) | `16` | 12–24 |
| `mobile_ticker_mode` | énumération | `rotate` | `rotate`, `inherit`, `static`, `marquee`, `manual` |
| `mobile_swipe` / `mobile_hide_on_scroll` | booléens | `true` | |
| `mobile_show_separator` | booléen | `false` | |
| `mobile_custom_colors` | booléen | `true` | |
| `mobile_bg_color` / `mobile_text_color` / `mobile_accent_color` / `mobile_label_text_color` | couleurs | `#141414` / `#F5F5F5` / `#E11D2A` / `#FFFFFF` | |

Seul `mobile_ticker_mode` entre dans la clé de cache (il détermine les boutons présents dans le markup) ; tout le reste est porté par `#hprnb-root` (classes `hprnb-root--{d|m}-*`, variables `--hprnb-height`, `--hprnb-d-lines`, `--hprnb-m-*`, JSON `data-hprnb-desktop` / `data-hprnb-mobile`). La feuille de style consomme des jetons effectifs `--hprnb-e-*` fixés par le profil ordinateur sur le root et, sous 768 px, par le profil mobile sur `.hprnb-bar`. Le script interactif choisit le profil effectif selon la largeur du root et se réinitialise au franchissement du seuil (rotation d’écran). L’aperçu d’administration propose deux onglets, Ordinateur et Mobile (cadre de 375 px), animés par le même script que le site, et affiche la hauteur calculée en regard des sélecteurs « Lignes de titre ».

## 9 quater. En continu v2 (2.0)

La 2.0 applique le cahier des charges client v1.1 : fond sombre `#1B1C20` sur les deux appareils, pastille rouge du thème `#CE3029` de 24 px alignée sur le conteneur du site (1230 px, gouttière 15 px), barre ordinateur de 40 px à 30 px/s avec fondu aux bords et boutons de 40 px, bouton fermer actif (24 h). Sur mobile, la disposition `flow` (défaut) fait flotter la pastille en tête du titre qui coule sur deux lignes (16 px / 26 px, carte de 76 px) ; en défilant, la première ligne devient le **bandeau replié de 40 px** avec un chevron ; arrivée en milieu de page repliée ; barre effacée quand un champ de formulaire est actif ; 44 px sur une ligne en paysage. La barre expose `--hprnb-offset` sur `body`, `body.hprnb-is-collapsed`, `body.hprnb-kbd`, l’évènement `hprnb:state` et `window.hprnbBar.state()` ; avec `theme_offset`, les éléments fixes de Jannah (`#go-to-top`, `#check-also-box`, `#reading-position-indicator`) se placent au-dessus de la barre. Nouveaux réglages : `accent_color`, `align_container`, `max_width`, `gutter`, `mobile_bar_height`, `mobile_peek`, `mobile_deep_collapse`, `mobile_kbd_hide`, `theme_offset`. La page de réglages est organisée en onglets (Contenu, Affichage, Couleurs, Fermeture, Thème, Avancé) avec des cartes par module, des préréglages de couleurs et un bouton « Réinitialiser l’onglet ». Une installation 1.x reçoit une fois le préréglage v2 à la mise à niveau.

## 9 quinquies. Image des articles (2.1)

Chaque profil a ses trois réglages dans l'onglet **Affichage** : **Image** (case à cocher), **Position de l'image** (avant ou après le titre) et **Taille de l'image** (16 à 80 px, carrée). Le markup mis en cache porte une seule fois l'`<img>` dès qu'un profil l'active ; les classes `hprnb-root--{d|m}-thumb` et `hprnb-root--{d|m}-thumb-after` et les variables `--hprnb-d-thumb` / `--hprnb-m-thumb` font le reste, hors cache. Sur ordinateur l'image suit ou précède le titre (ordre flex) et la barre grandit si elle dépasse les lignes ; sur la carte mobile elle occupe sa propre colonne hors du flux, centrée et plafonnée à la hauteur du bloc de titre (la carte ne se déforme jamais), la pastille se réduisant au point rouge pour laisser la largeur au titre. Dans le bandeau replié, l'image et sa colonne s'effacent. La taille source WordPress (`thumbnail_size`) est commune aux deux profils, dans l'onglet Avancé.

## 9 sexies. Bandeau replié et boutons (2.2)

Trois réglages supplémentaires dans la carte **Mobile** : **Boutons** (empilés, Fermer au-dessus de Pause, sur une colonne — défaut — ou côte à côte), **Pastille clignotante** (toujours, seulement dans le bandeau replié, jamais) et **Image dans le bandeau replié** (l'image reste entre le titre et le chevron, redimensionnée pour ne jamais dépasser une ligne). Une quatrième case, **Pastille compacte avec une image**, réduit la pastille à son point rouge quand une image est affichée ; désactivée par défaut, la carte garde donc la pastille rouge complète avec son texte. Empiler les boutons ramène la colonne de contrôles à une largeur de bouton : `Renderer::mobile_controls()` renvoie le nombre de colonnes et la réservation de largeur suit.

## 9 septies. Apparition, repli et boutons paramétrables (2.3)

**Moment d'apparition** (carte *Apparition de la barre*, onglet Affichage, les deux profils) : `reveal_mode` choisit entre *immédiatement* (défaut), *après une distance de défilement* (`reveal_value`, 400 px par défaut — le meilleur choix dans un article : l'ouverture de la page reste dégagée), *après une proportion de la page lue* (en %) et *vers la fin de la page* (90 %). Tant que le seuil n'est pas franchi, aucun espace n'est réservé (`body.hprnb-pending`, `--hprnb-offset: 0px`) et la barre reste hors champ ; une fois apparue, elle reste.

**Moment du repli sur mobile** (carte *Mobile*) : `mobile_hide_on_scroll` décide si la carte se replie ; `mobile_collapse_mode` décide quand — en descendant au-delà du seuil et rouverte en remontant (défaut), dès le seuil franchi et repliée pour de bon, ou toujours repliée (le lecteur ouvre la carte d'une pression). `mobile_collapse_after` fixe le seuil, de 0 à 800 px.

**Boutons Pause et Fermer** : `mobile_controls_place` les sort de la barre en un petit groupe flottant arrondi juste au-dessus d'elle (le titre prend alors toute la largeur), et `mobile_show_pause` / `mobile_show_close` masquent chacun le sien sur mobile. Les deux masqués, la carte déployée occupe toute la largeur ; seul le bandeau replié réserve la colonne de son chevron.

**Liseré d'accent** (`accent_edge`, activé, onglet Couleurs) : un trait de 2 px de la couleur d'accent sur le bord supérieur de la barre, que la progression de la rotation vient remplir.

## 9 octies. Types de pages, placement dans l'article et second design mobile (2.4)

**Types de pages par profil** : chaque carte de profil (onglet Affichage) a sa liste **Types de pages**, qui restreint la portée globale définie dans l'onglet Avancé. Un profil refusé sur la page courante est simplement masqué en CSS et n'y réserve aucun espace ; les deux refusés, la barre n'est pas rendue.

**Placement dans l'article** : `{desktop|mobile}_placement` choisit entre *fixée en bas de l'écran* (défaut) et *dans l'article*. Dans ce second mode, **Où dans l'article** (`before` / `after` / `before_end`) et **Numéro du paragraphe** (1 à 30) décident du point d'insertion — indépendamment pour l'ordinateur et pour le mobile. La barre devient alors un bloc du contenu : pleine largeur (elle sort du gabarit contraint via `alignfull` et un décalage mesuré par le script), en flux, sans espace réservé et sans repli. Quand les deux profils visent deux paragraphes différents, le serveur rend la barre à celui de l'ordinateur et laisse une ancre vide à celui du mobile ; le script déplace la racine au franchissement des 768 px. Un article sans paragraphe laisse la barre en bas de l'écran.

**Repli sur ordinateur** : `desktop_hide_on_scroll`, `desktop_collapse_mode` et `desktop_collapse_after` reprennent les trois moments du mobile. La barre glisse entièrement hors de vue et laisse un onglet arrondi contre le bord inférieur, aligné sur la gouttière du conteneur.

**Design « Découvrir » (mobile)** : `mobile_layout = card` est une **carte flottante compacte**, détachée des bords de 8 px, coins de 12 px, ombre discrète, zone sûre iOS appliquée une seule fois. Elle place **l'image 5:4 au début de la ligne** (à droite sur un site RTL) et, à côté, **la pastille sur la première ligne puis le titre à partir de la deuxième**, en 18 px gras sur **deux lignes par défaut, trois au maximum** (voir § 9 decies). La largeur de l'image se règle par `mobile_card_thumb` (72 à 120 px, 96 par défaut) et, sur deux lignes, **c'est elle qui règle la hauteur** : 99 px par défaut, 94 px au minimum, 118 px au maximum. Sous 360 px de large, l'image se réduit à 72 px. Le style de label « bandeau » y devient un titre en gras sans fond ; « pastille » garde la pastille rouge, plus compacte qu'ailleurs.

Le **bouton Fermer est à l'intérieur de la carte**, dans son coin supérieur de fin, sur un fond légèrement éclairci, avec une cible tactile de 44 × 44 px qui ne déborde jamais sur le titre ; le bouton Pause, s'il est activé, se place juste à côté. Leur colonne est réservée dans la colonne de texte, si bien que ni la pastille ni le titre ne passent derrière eux. Ce design place ses boutons lui-même : les réglages d'emplacement et d'empilement ne s'y appliquent pas.

**Replié**, la carte devient le même bandeau que la carte « flow » : l'image et sa colonne s'effacent, la pastille redevient le point rouge clignotant contre le bord, la première ligne du titre s'affiche à côté, et aucun bouton n'apparaît. Un article sans image mise en avant rend toute la largeur à son titre. En rotation, seuls l'image et le titre changent : la pastille et les boutons ne bougent pas et l'article apparaît en fondu, sans aucun décalage.

## 9 nonies. Apparition intelligente, séparateur et mesure (2.5)

**Séparateur** : avec **un seul article**, plus aucun séparateur, quels que soient les réglages et le mode. `separator_after_last` existe pour que la jonction dernier → premier du défilement continu ressemble aux autres ; sans jonction, la puce n'a pas lieu d'être. Deux garde-fous CSS indépendants : `:only-child` (réévalué dans chaque `<ul>`, donc valable pour le clone du marquee) et `[data-hprnb-count="1"]` sur la racine. À partir de deux articles, rien ne change.

**Mode `smart`** dans *Moment d'apparition de la barre*, à côté des quatre modes existants, tous inchangés. Il mesure **le corps de l'article** et non la page : `smart_selector` (facultatif) sinon une chaîne de sélecteurs usuels, un conteneur n'étant retenu que s'il contient de la prose. Trois signaux, **le premier venu l'emporte, une seule fois par page** :

1. **Fin de l'article** — un sentinel d'un pixel à la fin du corps éditorial, observé par `IntersectionObserver`. Signal prioritaire : le lecteur vient de finir ce qu'il était venu lire.
2. **Remontée intentionnelle** — part de l'article lue **et** temps de lecture actif **et** pixels remontés cumulés. Repartir vers le bas remet le cumul à zéro ; le rebond iOS et les redimensionnements ne comptent pas.
3. **Lecteur engagé** — part lue **et** temps de lecture actif, sans remontée. Désactivé sur un article de moins d'une fois et demie la hauteur d'écran, où seule la fin fait foi.

Le temps de lecture est **actif** : suspendu quand l'onglet passe en arrière-plan et après une minute sans activité. Dix réglages (cinq par profil), par défaut mobile 55 % / 15 s / 300 px / 75 % / 25 s et ordinateur 50 % / 12 s / 350 px / 65 % / 20 s. **Aucune installation existante ne bascule en `smart` d'elle-même.** Une fermeture n'est jamais contournée, et `smart` n'interfère pas avec le repli : il décide seulement du moment de la première apparition.

**Mesure** : trois évènements non bloquants poussés sur `window.dataLayer` et émis sur `document` — `hprnb_impression`, `hprnb_click`, `hprnb_close` — avec `trigger_reason` (`article_end`, `scroll_up_intent`, `engaged_reader`, `legacy_immediate`, `legacy_scroll`, `legacy_percent`, `legacy_end`), `device`, `current_article_id`, `recommended_article_id`, `items`, et pour le mode intelligent `article_progress`, `active_reading_time` et `article_found`. Aucun appel réseau : sans `dataLayer`, la barre s'affiche exactement pareil.

## 9 decies. Contrôle par article et page de réglages (2.6)

**Bloc « Barre d'actualités » sur l'écran d'édition** de chaque article et page, avec deux cases indépendantes :

- *Ne jamais lister cet article dans la barre* — le titre sort de la barre sur tout le site. Articles seuls : une page n'est jamais un titre. C'est une clause SQL (`meta_query` `NOT EXISTS`) dans `Query::args()`, donc la barre **se remplit à nouveau** au lieu de rétrécir sous `max_items`.
- *Ne jamais afficher la barre sur cette page* — aucune barre pour le lecteur de cette page, quels que soient les types de pages autorisés. Vaut aussi pour le shortcode et pour l'espace réservé.

Clés de métadonnée `_hprnb_exclude_item` et `_hprnb_hide_bar`, protégées par leur tiret bas. Les deux font tourner l'époque de cache, aucune n'entre dans la clé de cache : un seul payload sert tout le site. Nonce dédié, `edit_post`, sauvegardes automatiques et révisions ignorées ; une sauvegarde sans le bloc laisse les drapeaux intacts. `uninstall.php` les nettoie derrière l'option d'effacement.

**Lignes du titre sur mobile** : la carte « Découvrir » honore `mobile_lines` comme les autres designs, avec un plafond propre de **3 lignes**. À 16 px : 99 px sur deux lignes, **116 px sur trois** (118 px avec l'image la plus large). Le bandeau replié reste d'une ligne quel que soit le réglage.

**Page de réglages** : six onglets nommés d'après la question posée — Contenu, **Où**, **Apparition et repli**, Ordinateur et mobile, Couleurs, Avancé. Les types de pages ont **un seul endroit** (la portée globale ouvre l'onglet Où ; les deux listes par appareil sont un affinage facultatif présenté dessous). L'interrupteur de repli s'appelle *Replier la barre* et est l'interrupteur d'en-tête de sa carte — décoché, `collapse_mode` et `collapse_after` sont inertes, « Toujours repliée » comprise. Chaque carte qui le mérite porte un dépliant **« Cas d'usage courants »**. Aucun réglage supprimé, aucun schéma modifié.

**Nouvelle classe racine `hprnb-root--reveal`** : l'animation d'entrée porte sa propre transition au lieu d'emprunter celle du repli, qui est désactivé par défaut sur ordinateur.

## 9 undecies. Avant la fin de l'article et repli qui suit la lecture (2.7)

**Mode `paragraph`** dans *Moment d'apparition de la barre* : la barre apparaît **dès que le Nᵉ paragraphe compté depuis la fin de l'article entre à l'écran**. `reveal_paragraph` (défaut 2 = l'avant-dernier, de 1 à 30). Il mesure le corps de l'article (`smart_selector` puis les sélecteurs usuels) ; les paragraphes vides et le texte de la barre elle-même ne comptent pas ; sans corps ou sans paragraphe, il retombe sur « vers la fin de la page ». Une position restaurée ou un lien profond **au-delà** du paragraphe affiche la barre immédiatement. La position est mesurée puis recalculée quand la page reflue, et comparée dans un défilement passif — pas d'`IntersectionObserver` ici, qui ne verrait jamais un paragraphe que le navigateur saute d'un coup.

**Repli `article`** (« Suit la lecture »), sur chaque profil. Avec **B** le point où la barre est apparue et **C** la fin du corps de l'article :

| Zone | En descendant | En remontant |
|---|---|---|
| Haut → B | repliée | repliée |
| B → C | **ouverte** | **repliée** |
| Au-delà de C | ouverte | ouverte |

La première apparition est toujours la barre entière. `collapse_after` n'y joue aucun rôle. Le tap qui ouvre la barre repliée la retient quatre secondes, comme partout.

**Mesure** : `trigger_reason` gagne `paragraph_before_end`, `paragraph_passed` et `paragraph_fallback`, avec `paragraph_from_end` et `paragraph_found`.

## 9 duodecies. Apparition par appareil, onglets par appareil, la carte de la maquette (2.8)

**Apparition par appareil** : `mobile_reveal_mode` / `desktop_reveal_mode` (+ `_value`, `_paragraph`) remplacent le réglage unique ; schéma 5 recopie l'ancien réglage sur les deux appareils. `data-hprnb-reveal` porte un bloc `d` et un bloc `m` ; la racine porte `hprnb-root--d-pending` / `--m-pending` (requêtes de conteneur sur sa propre largeur) et le body `hprnb-d-pending` / `hprnb-m-pending`. `Settings::migrate()` est pure et l'import l'applique aux exports antérieurs.

**Onglets** : Contenu · Où · **Mobile** · **Ordinateur** · Couleurs · Avancé. Chaque appareil : design, titres, moment d'apparition, repli, boutons. L'aperçu de l'administration n'est **jamais** en attente (`root_classes( $settings, true )`).

**La carte mobile, par défaut** (`mobile_layout = card`, `mobile_lines = 3`, `mobile_card_thumb = 132`) :

| | |
|---|---|
| Ligne 1 | l'étiquette seule (pastille, ou titre blanc en gras avec le style « bandeau ») |
| Ligne 2 | image 16:9 de 132 × 74 au début, titre 18 px gras sur 3 lignes à côté |
| Bords | d'un bord à l'autre, coins droits (`mobile_card_float` : 8 px, coins 12 px, ombre) |
| Croix | onglet 44 × 44 de la couleur de la carte, au-dessus du coin de fin, hors de la carte |
| Hauteur | 12 + 20 + 8 + max(74, 3 × 22) + 12 = **126 px** ; repliée : 36 px |

## 9 terdecies. Un seul choix par appareil et l'article suivant (2.9)

**Comportement de la barre** (`mobile_behavior`, `desktop_behavior`) : premier bloc des onglets Mobile et Ordinateur. Chaque choix écrit ses réglages détaillés à l'enregistrement (`Settings::behavior_presets()`), le site ne lit que ces derniers.

| Choix | Apparition | Repli | Article suivant |
|---|---|---|---|
| **Lecture continue** (`reading`) | au Nᵉ paragraphe avant la fin (`*_reveal_paragraph`, défaut 2) | suit la lecture (`article`) | masquée |
| **Visible, se replie** (`fold`, défaut mobile) | avec la page | en descendant, retour en remontant (`scroll`) | — |
| **Toujours visible** (`always`, défaut ordinateur) | avec la page | aucun | — |
| **Personnalisé** (`custom`) | réglage détaillé | réglage détaillé | case à cocher |

Le nombre de paragraphes, le seuil de repli et l'aspect du bandeau replié restent réglables quel que soit le choix. Les deux blocs détaillés ne s'affichent qu'en « Personnalisé ». Le sélecteur du corps de l'article est dans **Avancé → Corps de l'article**.

**Article suivant** (`mobile_next_hide`, `desktop_next_hide`) : avec un thème qui charge l'article suivant sous l'article en cours, la barre sort de l'écran dès que le haut de l'article suivant atteint le milieu de l'écran, et libère son espace (`hprnb-root--m-away` / `--d-away`, body `hprnb-m-away` / `hprnb-d-away`, `--hprnb-offset: 0px`). Elle revient si le lecteur remonte dans le premier article. L'article suivant est un autre corps d'article bâti comme le premier et situé sous lui ; seulement sur un article seul et une barre fixe.

**Schéma 6** : une installation existante garde son comportement, nommé d'après le choix qu'il reproduit, sinon « Personnalisé ». La migration ne choisit jamais « Lecture continue ».

## 9 quattuordecies. Le bandeau fluide avec l'image de l'article (2.10)

*Mobile → Design → « Bandeau fluide avec l'image de l'article »* (`mobile_layout = flow_image`) : le bandeau fluide — pastille devant le titre, deux lignes, 76 px — avec l'image de l'article (48 px par défaut, carrée) en fin de ligne à la place des boutons, et la croix dans un onglet de 44 × 44 px au-dessus du coin de fin, comme sur la carte. La pause, si elle est active, se place à côté de la croix. Racine : `hprnb-root--m-flow`, `--m-thumb`, `--m-thumb-after`, `--m-ctrl-tab` ; `--hprnb-m-ctrls: 0`.

## 9 quindecies. Deux designs, les défauts du client, un repli soigné, une page simple (2.11)

**Designs mobiles** : `mobile_layout` = `flow_image` (défaut) ou `card`. Schéma 7 : `flow`, `stacked`, `inline` deviennent `flow_image`.

**Défauts mobiles** : 2 lignes, `mobile_show_pause` désactivé, `mobile_behavior = reading` (donc `mobile_reveal_mode = paragraph`, `mobile_collapse_mode = article`, `mobile_next_hide` activé), `mobile_peek_thumbnail` activé.

**Replié** : les deux designs gardent leur onglet au-dessus du coin, avec le bouton pour déplier ; la bande garde la première ligne et la petite image (carrée pour le bandeau, 16:9 pour la carte). Le body reçoit `--hprnb-tab` (hauteur de l'onglet, 0 sans onglet) ; `#go-to-top` et `#check-also-box` de Jannah en tiennent compte.

**Mouvement** : `--hprnb-fold: .5s cubic-bezier(.22, 1, .36, 1)` sur la barre repliable ; transitions sur la taille et la position de l'image, la marge du titre, la pastille et son texte ; animation `hprnb-tab-icon` sur l'icône de l'onglet. Tout est coupé sous `prefers-reduced-motion`.

**Réglages** : interrupteur « Réglages avancés » dans l'en-tête (mémorisé par le navigateur, `localStorage` `hprnb_admin_advanced`). `Settings_Page::advanced_fields()` liste les réglages avancés ; les cartes `advanced` et les onglets entièrement avancés sont masqués en mode simple.

## 9 sexdecies. Le design par l'image, et appliqué aux sites existants (2.12)

*Mobile → Design* : deux cases, chacune avec un dessin du bandeau ouvert et replié (`Settings_Page::design_choices()`, `design_mock()`, classes `hprnb-mock*` de la feuille d'administration). **Schéma 8** : un site existant reçoit une fois `Settings::image_bar_design()` — `mobile_layout = flow_image`, 2 lignes, 16 px, barre de 76 px, image de 48 px, pastille avec point, pause masquée, bande repliée avec la première ligne et l'image.

## 9 septdecies. Le repli au sens du défilement, le fondu, le ZIP versionné (2.13)

`*_collapse_mode = up` (défaut mobile, mode de « Lecture continue ») : après l'apparition, descente → dépliée, remontée → repliée, sans seuil ni zone. Le titre trop long s'efface par un fondu de 64 px (40 px replié) en fin de dernière ligne (`.hprnb-bar__viewport.is-clipped::after`, inversé sous `.hprnb-bar--rtl`). Le ZIP s'appelle `horizon-press-news-bar-<version>.zip`.

## 9 octodecies. Le bandeau URGENT (2.14)

Une case **Article urgent** dans le bloc Barre d'actualités de l'écran d'édition (articles). Cochée à la publication ou à la mise à jour, elle écrit `_hprnb_urgent_since` et `_hprnb_urgent_until` (horodatages UTC, `urgent_minutes` après l'enregistrement, 10 min par défaut, 1 à 1440) ; sur un article non publié, `_hprnb_urgent_armed` et le compte à rebours démarre à la publication (`transition_post_status`). Tant qu'elle court, la case reste cochée et le bloc affiche « Urgent jusqu'à 18 h 10 (encore 7 min) » ; une mise à jour ne relance rien sauf si « Repartir de zéro à cette mise à jour » est cochée ; décocher puis mettre à jour arrête tout de suite. `Urgent::items()` sélectionne les articles publiés dont l'échéance n'est pas passée, le plus récemment signalé d'abord (`max_items`, 10 au plus), dans la même charge utile que la barre (`urgent_count`, `urgent_items`, `urgent_html`), donc dans le même cache, le même corps REST et la même session du script d'amorçage ; chaque changement de `_hprnb_urgent_until` invalide le cache. Le rendu passe par `Renderer::urgent_bar()` (gabarit `bar.php` avec `urgent => true` : classe `hprnb-bar--urgent`, étiquette `urgent_label` + chevron, aucune image, bouton fermer toujours présent, `data-hprnb-since` / `data-hprnb-until` par titre). La racine porte `hprnb-root--urgent` et `data-hprnb-urgent` et n'attend pas le lecteur ; le script fait tourner le bandeau rouge (rotation sur téléphone, défilement réglé sur ordinateur), retire chaque titre à son échéance, puis rend la page à la barre normale en rétablissant son attente d'apparition ; fermer mémorise `hprnb_urgent_closed` (le signalement le plus récent) et une urgence plus récente rouvre. Réglages : onglet **Urgent** (`urgent_enabled`, `urgent_minutes`, `urgent_label`, `urgent_bg_color`, `urgent_text_color`), aperçu du bandeau rouge tant que l'onglet est ouvert (`POST /preview` avec `urgent: true`, deux titres inventés). Variables de la racine : `--hprnb-u-bg`, `--hprnb-u-fg`, `--hprnb-u-height`, `--hprnb-u-m-height`, `--hprnb-u-line`, `--hprnb-u-pad`, `--hprnb-u-lines` ; feuille de style section 17.

## 9 novodecies. Le bloc URGENT, la une et le design téléphone sur ordinateur (2.15)

La case « Article urgent » a son propre bloc, `hprnb-urgent-postbox` (« Bandeau URGENT »), enregistré pour les articles en `side` / `high` : premier de la colonne de droite, au-dessus de « Publier » et de tout ordre enregistré ; son jeton `hprnb_urgent_box` lui est propre. Le bandeau rouge a ses propres types de pages, `urgent_contexts` (tous cochés par défaut) : `Visibility::urgent_allowed()` ne regarde ni la portée de la barre d'actualités, ni ses listes par appareil, ni ses interrupteurs d'appareil, mais respecte l'interrupteur général, les exclusions absolues, le bloc « Ne jamais afficher la barre sur cette page » et les exceptions. `Frontend::shown_payload()` retire de la charge utile la barre qui n'a pas sa place sur la page et le dit à la racine (`data-hprnb-show`). Onglet Urgent → « Design sur ordinateur » : `urgent_desktop_layout` = `line` (défaut) ou `mobile` (classe racine `hprnb-root--u-d-flow`, hauteur 76 px). Mu-plugin de développement : `?hprnb_classic=1` force l'éditeur classique.

## 9 vicies. Un interrupteur par barre et par appareil, le design « chaîne d'information » (2.16)

Contenu → **Barres affichées** (première carte) : `urgent_enabled` puis `urgent_desktop` / `urgent_mobile`, `enabled` puis `show_on_desktop` / `show_on_mobile` (champs `switch`, lignes `data-hprnb-reveal` masquées par le script tant que le parent est coupé). `Urgent::devices()` → `{d, m}`, `Urgent::enabled()` = au moins un appareil (le bloc d'édition n'est enregistré qu'alors) ; `Visibility::news_enabled()` = `enabled` et au moins un appareil (sinon ni requête ni titres dans la charge utile et le corps REST). La racine porte `hprnb-root--u-no-d` / `hprnb-root--u-no-m` quand le bandeau rouge est coupé sur un seul appareil ; l'appareil se décide par requête média (768 px), comme `hprnb-hide-*` et l'espace réservé ; le corps REST porte `urgent_devices` que le script d'amorçage applique à une page en cache. Schéma 9 : `enabled` à faux avant la 2.16 coupe aussi `urgent_enabled`.

Design du bandeau URGENT : plaque aux couleurs inversées, titre gras un à la fois (rotation), `urgent_font_size` (ordinateur, 14–22, 17) et `urgent_mobile_font_size` (mobile et design téléphone, 14–20, 17) → `--hprnb-u-fs` / `--hprnb-u-m-fs` ; hauteurs `Renderer::urgent_height()` : ordinateur `max(48, bar_height, ceil(fs × 1,3) + 24)`, mobile `max(mobile_bar_height, lignes × round(fs × 1,4) + 24)`. `bar_font` = `news` (défaut, classe racine `hprnb-root--font-news`, pile système `--hprnb-font`, pour les deux barres) ou `theme`. L'aperçu « Ordinateur » (racine `hprnb-root--flat`, sans conteneur) reçoit une copie des blocs `@container hprnb (min-width: 768px)` de la section 17 dans `hprnb-admin.css`, tenue à jour par un test statique.

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

// Un réglage détaillé d'apparition ou de repli ne compte qu'avec le comportement « custom » (2.9).
add_filter( 'hprnb_settings', function ( array $settings ) {
	$settings['mobile_behavior']    = 'custom';
	$settings['mobile_reveal_mode'] = 'smart';
	return $settings;
} );

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
- Testé sur PHP 8.4 dans l’environnement de recette ; la compatibilité 8.0–8.3 est vérifiée statiquement (PHPCompatibilityWP).
