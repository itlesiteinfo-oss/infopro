# Cahier des charges FINAL AUDITÉ — Plugin WordPress « Horizon Press News Bar »

> **Version 3.0 — 14 septembre 2026** (2.0 du 11 septembre ; 2.1 du 12 septembre : `separator_after_last` ; 2.2 : présentation mobile empilée paramétrable, §15.5, §22.4 bis, §23 ; 2.3 : profils de présentation ; 3.0 : « En continu » v2, §15.8)  
> **Statut : FINAL / prêt à remettre à Claude Code**  
> **Objectif : obtenir en une seule exécution un plugin WordPress installable, testé, documenté et prêt pour la production.**

---

# 0. MODE D’EXÉCUTION IMPOSÉ À CLAUDE CODE

Ce document est la **source de vérité unique** du projet.

Claude Code doit travailler en **mode autonome de bout en bout** :

1. Lire l’intégralité de ce document avant de modifier ou créer un fichier.
2. Ne pas demander de validation intermédiaire.
3. Ne pas s’arrêter après avoir proposé une architecture.
4. Implémenter l’intégralité du périmètre V1.
5. Exécuter les contrôles statiques et les tests disponibles.
6. Corriger les problèmes détectés.
7. Rejouer les tests après correction.
8. Générer le ZIP final installable.
9. Générer un rapport de recette indiquant précisément ce qui a été testé.
10. Ne jamais déclarer un test « réussi » s’il n’a pas réellement été exécuté.

Si un détail d’implémentation mineur n’est pas explicitement défini, choisir l’approche :

**WordPress Core native → sécurité → simplicité → performance → compatibilité → minimum de code.**

Ne poser une question que si une information externe indispensable empêche matériellement de produire un plugin fonctionnel. Une préférence esthétique mineure ou une décision d’implémentation n’est **pas** un motif pour interrompre le travail.

## 0.1 Interdictions

- aucune dépendance SaaS ;
- aucun appel réseau sortant ;
- aucune télémétrie ;
- aucun framework JS ;
- aucun jQuery ajouté par le plugin ;
- aucun CDN ;
- aucune police externe ;
- aucune table SQL personnalisée ;
- aucun cron ;
- aucun `eval()` ;
- aucun `extract()` ;
- aucun `$_REQUEST` ;
- aucun code généré dynamiquement ;
- aucun `ORDER BY RAND()` ;
- aucun détecteur spécifique de WP Rocket, LiteSpeed, Cloudflare, Varnish, etc. ;
- aucun champ de CSS arbitraire dans la V1 ;
- aucun Custom Post Type créé par le plugin ;
- aucun bloc Gutenberg spécifique en V1.

---

# 1. OBJECTIF PRODUIT

Créer un plugin WordPress nommé **Horizon Press News Bar** affichant une barre d’actualités fixe en bas du front-office.

La barre doit afficher des articles WordPress récents selon une **fenêtre temporelle glissante**.

Exemple :

- heure actuelle : 16:20 ;
- durée configurée : 24 heures ;
- borne basse : la veille à 16:20 ;
- seuls les articles publiés depuis cette borne sont éligibles.

La barre doit ensuite appliquer les filtres configurés : catégories, tags et exclusions.

## 1.1 Règle métier fondamentale

La sélection est :

**articles publiés ET dans la fenêtre temporelle ET correspondant aux filtres de contenu.**

Ce n’est jamais :

- « les articles du jour » ;
- « depuis minuit » ;
- « les X derniers articles sans contrainte de date » ;
- « les articles récemment modifiés » ;
- « les plus populaires ».

## 1.2 Règle absolue de barre vide

Si aucun article ne correspond :

**aucune barre visible ne doit apparaître.**

En mode hybride, un conteneur technique invisible peut exister afin de détecter ultérieurement l’arrivée d’un nouvel article derrière un cache page ancien, mais :

- il ne doit occuper aucun espace ;
- il ne doit générer aucun flash visuel ;
- il ne doit être annoncé par aucun lecteur d’écran.

---

# 2. DÉCISIONS ISSUES DU BENCHMARK 2026

Le marché WordPress propose déjà des solutions riches de ticker, sliders, annonces, plusieurs profils, flux externes, blocs, builders et templates. La V1 Horizon Press doit volontairement éviter cette inflation fonctionnelle.

Décisions :

- garder les réglages éditoriaux les plus utiles : catégorie, tag, nombre, tri, vitesse, couleurs, visibilité ;
- garder l’aperçu admin ;
- garder import/export ;
- garder un shortcode simple ;
- proposer plusieurs comportements de ticker, mais tous optionnels ;
- ne pas créer de CPT, table ou système multi-barres ;
- ne pas intégrer RSS/JSON externes ;
- ne pas intégrer Gutenberg/Elementor ;
- ne pas autoriser de CSS arbitraire dans l’admin ;
- rendre toute animation automatique contrôlable par un vrai bouton Pause/Lecture ;
- privilégier un rendu SSR + rafraîchissement REST conditionnel plutôt qu’un appel AJAX à chaque page ;
- ne jamais essayer de détecter le système de cache page ;
- utiliser uniquement les APIs WordPress Core.

La V1 doit être **plus petite et plus prédictible** qu’un plugin généraliste de ticker.

---

# 3. IDENTITÉ TECHNIQUE

| Élément | Valeur |
|---|---|
| Nom | Horizon Press News Bar |
| Slug | `horizon-press-news-bar` |
| Dossier | `horizon-press-news-bar/` |
| Fichier principal | `horizon-press-news-bar.php` |
| Version V1 | `1.0.0` (livrée) — `1.1.0` avec `separator_after_last` — `1.2.0` avec la présentation mobile |
| Namespace | `HorizonPress\NewsBar` |
| Préfixe PHP/options | `hprnb_` |
| Préfixe CSS | `hprnb-` |
| Text domain | `horizon-press-news-bar` |
| REST namespace | `hprnb/v1` |
| Licence | GPL-2.0-or-later |

## 3.1 Compatibilité

Cible de production :

- WordPress **6.6+** ;
- test principal sur WordPress **7.1** ;
- PHP **8.1 à 8.4** ;
- PHP recommandé : **8.3+** ;
- Chrome, Edge, Firefox et Safari : deux dernières versions majeures ;
- Safari iOS moderne ;
- thèmes classiques et block themes ;
- multisite : ne doit pas casser, réglages par site ; aucune interface réseau spécifique en V1.

Ne pas utiliser une API apparue après le minimum WordPress déclaré sans repli explicite.

## 3.2 En-tête plugin

```php
/**
 * Plugin Name:       Horizon Press News Bar
 * Description:       Barre d'actualités récentes, fixe en bas de page, filtrée par fenêtre temporelle et catégories.
 * Version:           1.2.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Horizon Press
 * License:           GPL-2.0-or-later
 * Text Domain:       horizon-press-news-bar
 * Domain Path:       /languages
 */
```

---

# 4. PÉRIMÈTRE V1

## 4.1 Noyau

Le noyau comprend :

- activation/désactivation globale ;
- barre en bas de page ;
- label personnalisable ;
- position logique du label : début/fin ;
- fenêtre glissante : valeur + unité ;
- sélection d’une ou plusieurs catégories ;
- toutes catégories si aucune catégorie n’est sélectionnée ;
- catégories à exclure ;
- tags à inclure ;
- articles à exclure ;
- nombre maximum d’articles ;
- tri récent → ancien ou ancien → récent ;
- couleurs ;
- taille de police ;
- hauteur ;
- choix `reserve` / `overlay` ;
- règles de visibilité ;
- cache serveur ;
- rafraîchissement hybride pour résister au cache page ;
- interface admin ;
- aperçu ;
- import/export/reset ;
- shortcode ;
- RTL/i18n ;
- sécurité et accessibilité.

## 4.2 Fonctions optionnelles

Désactivées par défaut :

- heure relative ;
- miniature ;
- séparateur ;
- séparateur après le dernier article (`separator_after_last`, actif par défaut mais sans effet tant que le séparateur est désactivé) ;
- ticker ;
- pause au survol ;
- bouton fermer ;
- mémorisation de la fermeture.

Activées par défaut mais configurables :

- affichage desktop ;
- affichage mobile ;
- présentation mobile empilée (`mobile_layout = stacked`) avec pastille, compteur, barre de progression, balayage tactile, repli au défilement et palette mobile dédiée ;
- affichage automatique ;
- shortcode disponible.

## 4.3 Hors périmètre V1

Ne pas développer :

- plusieurs barres/profils ;
- Custom Post Types ;
- taxonomies personnalisées ;
- filtre auteur ;
- contenu manuel ;
- RSS entrant ;
- JSON externe ;
- statistiques de clics ;
- A/B testing ;
- notifications push ;
- son ;
- badges clignotants ;
- programmation par dates fixes ;
- mode « depuis minuit » ;
- position en haut de page ;
- Gutenberg block ;
- Elementor/Divi widgets ;
- CSS arbitraire saisi dans l’administration ;
- tracking.

---

# 5. CONFIGURATION PAR DÉFAUT

Après activation :

```json
{
  "enabled": true,
  "label_text": "EN CONTINU",
  "label_position": "start",
  "window_value": 24,
  "window_unit": "hours",
  "categories_include": [],
  "categories_exclude": [],
  "tags_include": [],
  "content_exclude_post_ids": [],
  "max_items": 10,
  "orderby": "date_desc",
  "bg_color": "#1B1C20",
  "text_color": "#F5F5F5",
  "label_bg_color": "#CE3029",
  "label_text_color": "#FFFFFF",
  "link_hover_color": "#FFFFFF",
  "accent_color": "#CE3029",
  "font_size": 15,
  "bar_height": 40,
  "align_container": true,
  "max_width": 1230,
  "gutter": 15,
  "z_index": 99990,
  "layout_mode": "reserve",
  "show_relative_time": false,
  "relative_time_max_hours": 48,
  "show_thumbnail": false,
  "thumbnail_size": "thumbnail",
  "show_separator": true,
  "separator_char": "•",
  "separator_after_last": true,
  "ticker_enabled": true,
  "ticker_mode": "marquee",
  "ticker_speed": 30,
  "rotate_interval": 5000,
  "pause_on_hover": true,
  "close_button": true,
  "remember_dismiss": true,
  "dismiss_duration_hours": 24,
  "theme_offset": true,
  "show_on_desktop": true,
  "show_on_mobile": true,
  "desktop_layout": "inline",
  "desktop_label_style": "pill",
  "desktop_label_dot": true,
  "desktop_show_counter": false,
  "desktop_lines": 1,
  "desktop_show_progress": true,
  "mobile_layout": "flow",
  "mobile_label_style": "pill",
  "mobile_label_dot": true,
  "mobile_show_counter": false,
  "mobile_lines": 2,
  "mobile_bar_height": 76,
  "mobile_font_size": 16,
  "mobile_ticker_mode": "rotate",
  "mobile_show_progress": true,
  "mobile_swipe": true,
  "mobile_hide_on_scroll": true,
  "mobile_peek": "headline",
  "mobile_deep_collapse": true,
  "mobile_kbd_hide": true,
  "mobile_show_separator": false,
  "mobile_custom_colors": false,
  "mobile_bg_color": "#1B1C20",
  "mobile_text_color": "#F5F5F5",
  "mobile_accent_color": "#CE3029",
  "mobile_label_text_color": "#FFFFFF",
  "display_scope": "everywhere",
  "contexts": {"front_page": true, "blog_home": true, "single_post": true, "page": true, "category": true, "tag": true, "archive": true, "search": true, "not_found": true},
  "display_exclude_ids": [],
  "render_mode": "hybrid",
  "cache_ttl": 120,
  "stale_threshold": 180,
  "auto_display": true,
  "shortcode_enabled": true,
  "uninstall_delete_data": false
}
```

Aucune animation ni image n’est donc imposée après installation.

---

# 6. STOCKAGE DES RÉGLAGES

Une seule option :

`hprnb_settings`

Toutes les lectures passent par la classe `Settings`.

Interdit :

```php
get_option( 'hprnb_settings' )
```

en dehors de `Settings`.

Utiliser :

```php
add_option( 'hprnb_settings', $defaults, '', true );
```

Le paramètre autoload doit être un **booléen**, jamais les chaînes historiques `'yes'` / `'no'`.

Options techniques :

- `hprnb_cache_epoch` : string courte/UUID, autoload `true` ;
- `hprnb_schema_version` : string, autoload `true`.

Le payload cache n’est **jamais** autoloadé.

## 6.1 Schéma

`Settings::schema()` est l’unique définition des :

- types ;
- valeurs par défaut ;
- bornes ;
- enums ;
- sanitizers ;
- dépendances.

L’enregistrement admin, l’import JSON et l’aperçu passent tous par **le même validateur**.

Les clés inconnues sont ignorées.

---

# 7. FENÊTRE TEMPORELLE

## 7.1 Unités

`window_unit` :

- `minutes` : 1 à 1440 ;
- `hours` : 1 à 720 ;
- `days` : 1 à 30.

Valeur par défaut : `24 hours`.

## 7.2 Référence

Le filtrage utilise UTC afin d’éviter les anomalies DST.

```php
$now_utc = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
```

Construire `$cutoff` avec `DateInterval`.

Ne jamais utiliser la date de modification.

Colonne SQL :

`post_date_gmt`

## 7.3 `date_query`

La borne basse et la borne haute sont transmises à `WP_Date_Query` sous forme de tableaux date/heure explicites.

Borne basse :

`now - duration`

Borne haute :

`now`

`inclusive = true`.

La borne haute explicite protège également contre un contenu importé avec une date future incohérente.

---

# 8. SÉLECTION DES ARTICLES

V1 :

`post_type = post`

Requête de base :

```php
$args = array(
    'post_type'              => array( 'post' ),
    'post_status'            => 'publish',
    'has_password'           => false,
    'ignore_sticky_posts'    => true,
    'posts_per_page'         => $settings['max_items'],
    'no_found_rows'          => true,
    'update_post_term_cache' => false,
    'update_post_meta_cache' => false,
    'date_query'             => $date_query,
    'orderby'                => 'date',
    'order'                  => $order,
);
```

Ajouter uniquement si non vide :

```php
category__in
category__not_in
tag__in
post__not_in
```

Ne jamais passer un tableau vide à ces arguments.

## 8.1 Tri

Seulement :

- `date_desc` → date DESC ;
- `date_asc` → date ASC.

**Aucun tri aléatoire V1.**

`ORDER BY RAND()` est interdit sur un site média à fort trafic.

## 8.2 Sticky

`ignore_sticky_posts = true` obligatoire.

Un sticky récent est traité comme un article normal.

Un sticky ancien ne contourne jamais la fenêtre temporelle.

## 8.3 Items normalisés

Le cache ne stocke aucun objet `WP_Post`.

Structure :

```php
array(
    'id'         => 123,
    'title'      => 'Titre',
    'url'        => 'https://...',
    'timestamp'  => 1757600000,
    'datetime'   => '2026-09-11T15:04:00+01:00',
    'date_label' => '11 septembre 2026 15:04',
    'thumb'      => null,
);
```

Titre :

```php
wp_strip_all_tags( get_the_title( $post ) )
```

Titre vide → item écarté.

URL :

```php
get_permalink( $post )
```

Timestamp :

utiliser l’API WordPress moderne adaptée aux dates de publication (`get_post_timestamp()` / `get_post_datetime()`), sans recalcul manuel de fuseau.

## 8.4 Miniatures

Si l’option est active :

- réutiliser une taille WordPress existante ;
- aucune nouvelle `add_image_size()` ;
- appeler `update_post_thumbnail_cache( $query )` une seule fois ;
- pas de placeholder ;
- `width` + `height` ;
- `loading="lazy"` ;
- `decoding="async"` ;
- `alt=""`.

Aucune requête N+1 n’est tolérée.

---

# 9. CACHE SERVEUR

## 9.1 Primitive

Utiliser l’API Transients WordPress.

Aucune table personnalisée.

Avec un object cache externe, WordPress peut servir les transients via cet object cache.

Sans object cache externe, WordPress peut utiliser `wp_options`. Par conséquent les critères de performance doivent distinguer :

- **requête métier WP_Query** ;
- **accès technique au cache WordPress**.

Il est interdit de promettre « 0 requête SQL absolue » sur un site sans persistent object cache.

## 9.2 Clé

Format :

`hprnb_bar_{epoch}_{hash}`

`epoch` = valeur de `hprnb_cache_epoch`.

`hash` dépend de tous les réglages influant sur :

- sélection des contenus ;
- ordre ;
- HTML ;
- label ;
- options de markup ;
- locale ;
- version plugin.

Les couleurs, tailles et z-index ne doivent pas forcer un nouveau payload : elles passent par CSS variables.

Le séparateur est également un pur sujet CSS : `show_separator`, `separator_char` et `separator_after_last` se traduisent par des classes (`hprnb-bar--sep`, `hprnb-bar--sep-loop`) et une variable (`--hprnb-sep`) portées par `#hprnb-root`, assemblé hors cache. Ces trois clés **n’entrent pas** dans le hash ; les y ajouter fragmenterait le cache sans raison.

Profils de présentation (`desktop_*`, `mobile_*`) : seul `mobile_ticker_mode` entre dans le hash (il détermine la présence des boutons Pause/Lecture et Précédent/Suivant dans le markup et l’attribut `data-hprnb-ticker-mobile`). Tous les autres réglages des deux profils (disposition, style du label, point, compteur, lignes, progression, séparateur mobile, palette) sont des classes, variables CSS ou données JSON portées par `#hprnb-root`, assemblé hors cache.

## 9.3 Payload

```php
array(
    'version'      => HPRNB_VERSION,
    'generated_at' => 1757600000,
    'count'        => 7,
    'items'        => array(),
    'html'         => '<aside class="hprnb-bar">...</aside>',
);
```

`html` contient **l’aside complet**, rendu par le même `Renderer` utilisé pour SSR et REST.

Ainsi :

- aucun squelette HTML n’est dupliqué en JavaScript ;
- SSR et hydratation utilisent exactement le même markup ;
- le `Renderer` reste la source unique du HTML.

Si `count = 0` :

```php
'html' => ''
```

Le résultat vide est mis en cache.

## 9.4 TTL

Défaut :

`120 s`

Bornes :

30 à 600 s.

Raison du TTL court : un article peut sortir naturellement de la fenêtre sans événement WordPress.

## 9.5 Invalidation

Plutôt qu’un compteur entier soumis à une course d’incrémentation, `hprnb_cache_epoch` reçoit une **nouvelle valeur unique** lors d’une invalidation.

Exemple :

```php
update_option( 'hprnb_cache_epoch', wp_generate_uuid4(), true );
```

Les anciens transients deviennent inaccessibles et expirent naturellement au plus tard après 600 s.

Déclencheurs :

- passage vers `publish` ;
- sortie de `publish` ;
- modification d’un article publié ;
- suppression/corbeille/restauration ;
- changement de catégorie/tag ;
- sauvegarde des réglages ;
- import/réinitialisation ;
- changement de thème si surcharge de template utilisée.

Une garde statique empêche plusieurs invalidations dans la même requête PHP.

Ne pas tenter de scanner `wp_options` pour supprimer tous les anciens transients.

---

# 10. PERFORMANCE — CRITÈRES RÉALISTES

Sur cache hit :

- **0 `WP_Query` métier** ;
- aucun parcours de posts ;
- aucun N+1 ;
- avec persistent object cache : objectif 0 accès SQL propre au cache du plugin ;
- sans persistent object cache : un accès technique aux options/transients peut exister.

Sur cache miss :

- 1 requête principale `WP_Query` ;
- requêtes supplémentaires bornées uniquement si une fonctionnalité comme les miniatures nécessite un priming de cache ;
- jamais une requête par item.

Budgets front :

- CSS principal minifié : objectif ≤ 26 Ko (24 Ko en 2.1 : la 2.2 ajoute les boutons empilés, l'image du bandeau et les modes de clignotement)
- bootstrap hybride minifié : objectif ≤ 3 Ko ;
- JS interactif minifié : objectif ≤ 10 Ko ;
- 0 dépendance tierce ;
- 0 appel externe.

HTML frais en mode hybride :

**0 requête REST supplémentaire.**

---

# 11. MODES DE RENDU

V1 ne possède que deux modes.

## 11.1 `hybrid` — défaut

SSR + contrôle de fraîcheur client + REST uniquement si nécessaire.

C’est le mode production recommandé.

## 11.2 `php`

SSR uniquement.

Aucun bootstrap de fraîcheur.

À utiliser pour diagnostic ou site sans cache page long.

Ne pas développer de mode REST-only en V1.

---

# 12. MODE HYBRIDE — ALGORITHME NORMATIF

## 12.1 Côté serveur

Après test de visibilité :

1. récupérer le payload ;
2. si cache miss, exécuter la requête et construire le payload ;
3. mémoriser le payload pour la requête PHP courante ;
4. charger le bootstrap hybride ;
5. si `count > 0` :
   - charger CSS ;
   - rendre le root + l’aside ;
   - charger JS interactif uniquement si nécessaire ;
6. si `count = 0` :
   - rendre uniquement `#hprnb-root` invisible ;
   - ne pas charger le CSS principal ;
   - ne pas charger le JS interactif.

Le root porte :

- `generated_at` ;
- `stale_threshold` ;
- endpoint REST ;
- URL CSS ;
- URL JS interactif ;
- `count/empty` ;
- classes d’affichage desktop/mobile ;
- variables CSS visuelles dans son attribut `style` (dont `--hprnb-sep`) ;
- classes de séparateur `hprnb-bar--sep` et `hprnb-bar--sep-loop` ;
- classes et variables des deux profils de présentation (`hprnb-root--d-*`, `hprnb-root--m-*`, `--hprnb-height`, `--hprnb-d-lines`, `--hprnb-m-*`) et les attributs `data-hprnb-desktop` / `data-hprnb-mobile` (JSON : disposition, lignes, compteur, progression ; balayage et repli sur mobile).

Les variables CSS présentes sur le root doivent permettre à une barre injectée plus tard d’hériter immédiatement :

- couleurs ;
- taille ;
- hauteur ;
- z-index.

Le breakpoint mobile V1 est **fixe à 768 px** et vit dans la feuille CSS statique. Il n’est pas configurable.

Cela évite le bug où une barre injectée après un état vide chargerait la feuille CSS mais perdrait les couleurs et tailles configurées.

## 12.2 Algorithme client

Ordre impératif :

```text
root absent -> STOP

lire payload SSR : generated_at + présence éventuelle de l'aside

si payload SSR est frais (age <= stale_threshold):
    conserver le SSR
    initialiser les options interactives si nécessaire
    mémoriser en sessionStorage uniquement si utile
    STOP

SSR obsolète:
    lire sessionStorage dans try/catch

    si session payload:
        - est valide
        - est encore frais
        - ET generated_at >= generated_at SSR
    alors:
        appliquer session payload
        STOP

sinon:
    effectuer UN SEUL fetch REST

si fetch échoue:
    conserver le SSR existant
    aucune nouvelle tentative sur cette page
    aucune erreur utilisateur
    STOP

si réponse plus ancienne que le SSR:
    ignorer la réponse
    STOP

si count = 0:
    vider root
    retirer classe de réservation
    STOP

si count > 0:
    charger CSS si nécessaire
    injecter payload.html
    charger JS interactif uniquement si nécessaire
    appliquer layout reserve si configuré
```

## 12.3 Règle sessionStorage critique

Une valeur sessionStorage ne doit **jamais écraser un SSR plus récent**.

L’entrée doit contenir :

- `generated_at` ;
- `count` ;
- `html`.

La validité se juge sur `generated_at`, pas seulement sur l’heure à laquelle la session a enregistré la valeur.

---

# 13. ENDPOINT REST PUBLIC

Route :

`GET /wp-json/hprnb/v1/items`

Aucun paramètre métier.

`permission_callback` :

```php
__return_true
```

Le endpoint :

- ne retourne que des articles déjà publics ;
- utilise exactement le même cache ;
- utilise exactement le même Renderer ;
- ne lit aucun cookie ;
- ne démarre aucune session ;
- ne dépend pas de l’utilisateur connecté.

Réponse :

```json
{
  "version": "1.0.0",
  "generated_at": 1757600000,
  "count": 7,
  "html": "<aside class=\"hprnb-bar\">...</aside>"
}
```

`count = 0` :

```json
{
  "count": 0,
  "html": ""
}
```

## 13.1 Cache HTTP

Le contrôleur ajoute ses en-têtes **sur cette réponse uniquement**.

Exemple :

```text
Cache-Control: public, max-age=60, s-maxage=60, stale-while-revalidate=120
ETag: "..."
Last-Modified: ...
```

Ne pas modifier globalement le comportement REST WordPress.

Ne pas ajouter un filtre global permanent sur `rest_send_nocache_headers`.

Si `If-None-Match` correspond exactement à l’ETag généré, le contrôleur peut renvoyer `304` avec corps vide.

L’implémentation 304 doit être locale au contrôleur et testée.

## 13.2 Langues

La V1 garantit :

- i18n WordPress ;
- RTL ;
- cache séparé par `determine_locale()`.

La compatibilité avec WPML/Polylang ne doit pas être annoncée comme garantie sans test réel dans l’environnement concerné.

Laisser `suppress_filters = false` afin de ne pas neutraliser les filtres d’un plugin multilingue.

---

# 14. RENDU HTML

Structure de référence :

```html
<div
  id="hprnb-root"
  class="hprnb-root hprnb-device-all hprnb-bar--sep hprnb-bar--sep-loop"
  data-hprnb-generated="1757600000"
  data-hprnb-stale="180"
  data-hprnb-endpoint="..."
  data-hprnb-empty="0"
  style="
    --hprnb-bg:#B00000;
    --hprnb-fg:#FFFFFF;
    --hprnb-label-bg:#8F0000;
    --hprnb-label-fg:#FFFFFF;
    --hprnb-hover:#FFFFFF;
    --hprnb-font-size:14px;
    --hprnb-height:44px;
    --hprnb-z:99990;
    --hprnb-sep:'•';
  "
>
  <aside
    class="hprnb-bar hprnb-bar--label-end hprnb-bar--reserve"
    role="region"
    aria-label="Dernières actualités"
    aria-live="off"
    dir="auto"
  >
    <div class="hprnb-bar__inner">

      <p class="hprnb-bar__label">
        <span class="hprnb-bar__label-text">TOUTE L’ACTUALITÉ</span>
      </p>

      <div class="hprnb-bar__viewport">
        <ul class="hprnb-bar__list">
          <li class="hprnb-bar__item">
            <a class="hprnb-bar__link" href="https://...">
              <span class="hprnb-bar__title">Titre</span>
            </a>
          </li>
        </ul>
      </div>

      <!-- contrôles uniquement quand nécessaires -->

    </div>
  </aside>
</div>
```

## 14.1 Invariants

- `#hprnb-root` unique ;
- aucun `<aside>` si zéro item ;
- aucun `<li>` vide ;
- aucune miniature inactive ;
- aucune heure inactive ;
- aucun bouton inutile ;
- aucun HTML dupliqué entre PHP et JS ;
- aucun élément DOM pour le séparateur : pseudo-élément CSS `::after` uniquement, masqué des lecteurs d’écran ;
- aucun `aria-live` autre que `off`.

---

# 15. CSS

Toutes les classes commencent par `hprnb-`.

Aucun style global sur :

- `a` ;
- `img` ;
- `ul` ;
- `body` ;

sauf la classe explicitement ajoutée par le plugin :

```css
body.hprnb-reserve
```

## 15.1 Position

```css
.hprnb-bar {
    position: fixed;
    inset-inline: 0;
    bottom: 0;
    z-index: var(--hprnb-z);
}
```

Safe area iOS :

```css
padding-block-end: env(safe-area-inset-bottom, 0);
```

## 15.2 Layout `reserve`

Défaut : **reserve**.

Le plugin ajoute `hprnb-reserve` au `<body>` lorsque la barre est réellement visible.

CSS :

```css
body.hprnb-reserve {
    padding-block-end: calc(
        var(--hprnb-height) + env(safe-area-inset-bottom, 0px)
    );
}
```

En injection client, ajouter/retirer la classe sur `document.body`, pas sur `<html>`.

## 15.3 Layout `overlay`

Option disponible.

Aucun padding de body.

Documenter qu’elle peut recouvrir un élément fixe tiers.

## 15.4 Breakpoint

V1 :

`768px` fixe.

Classes root :

- `hprnb-hide-mobile`
- `hprnb-hide-desktop`

Masquage **uniquement CSS**, jamais `wp_is_mobile()`.

## 15.5 Profils de présentation (ordinateur / mobile)

La V1.3 généralise la présentation mobile de la V1.2 en **deux profils** aux réglages identiques : **ordinateur** (à partir de 768 px, clés `desktop_*`) et **mobile** (sous 768 px, clés `mobile_*`). Chaque profil choisit :

- la **position du label** (`*_layout` : `inline` = devant le titre, sur la même ligne ; `stacked` = sur sa propre ligne, au-dessus du titre) ;
- le **style du label** (`*_label_style` : `strip` = bandeau aux couleurs du label, `pill` = pastille arrondie en capitales, `hidden`) et le **point « en direct »** pulsant devant le texte (`*_label_dot`, désactivé par défaut) ;
- le **compteur** « 2/8 » (`*_show_counter`, mode rotation) ;
- le **nombre de lignes de titre** (`*_lines`, 1 à 4), dont découle la **hauteur de la barre** ;
- la **ligne de progression** (`*_show_progress`, mode rotation).

Défauts — ordinateur : `inline`, `strip`, sans point, sans compteur, 1 ligne, progression ; mobile : `stacked`, `pill`, sans point, compteur, 2 lignes, progression, police 16 px. Le design empilé « mobile » est donc disponible tel quel sur ordinateur (`desktop_layout = stacked`, `desktop_label_style = pill`, `desktop_show_counter`, `desktop_lines = 2`, ticker en rotation).

### 15.5.1 Mesure du contexte

- Le profil mobile est piloté par une **container query** sur `#hprnb-root` (`container: hprnb / inline-size`, `inline-size: 100%`) : `@container hprnb (max-width: 767.98px)`. Le même seuil de 768 px est conservé ; il reste fixe et vit dans la feuille CSS statique.
- Toutes les règles de la feuille consomment des **jetons effectifs** `--hprnb-e-*` (couleurs, police, lignes, hauteur, colonnes et zones de grille, style du label, point, retour à la ligne, séparateur). Le profil ordinateur les fixe sur le root (classes `hprnb-root--d-*`) ; sous 768 px le profil mobile les remet à zéro puis les fixe sur `.hprnb-bar` (une container query ne peut pas styler son propre conteneur) via les classes `hprnb-root--m-*` ; le mode de défilement (marquee, rotation) en surcharge quelques-uns sur `.hprnb-bar__inner`. La section mobile de la feuille est le miroir exact de la section ordinateur (test statique dédié).
- Le JavaScript interactif utilise la même mesure (largeur du root < 768 px, `ResizeObserver`) pour choisir le profil et le mode effectifs (`data-hprnb-desktop` / `data-hprnb-mobile`) et se réinitialise proprement au franchissement du seuil (rotation d’écran, redimensionnement).
- Les classes d’affichage par appareil (`hprnb-hide-mobile` / `hprnb-hide-desktop`, §15.4) restent des media queries : le masquage par appareil ne dépend pas de la largeur du conteneur.
- Dans l’aperçu d’administration, la même feuille rend fidèlement les deux profils (§23).

### 15.5.2 Disposition, label et hauteur

- `inline` : grille `label | compteur | titre | contrôles` ; sur ordinateur, `label_position = end` donne `titre | label | compteur | contrôles` ; **sur mobile le label en ligne précède toujours le titre**. La colonne du label est plafonnée à 50 % de la barre (ellipse au-delà).
- `stacked` : deux rangées — un **bandeau** de 22 px (`label | compteur | contrôles`, les contrôles occupant les deux rangées) puis la rangée de titre sur toute la largeur (graisse 600) ; la colonne du label est plafonnée à 60 %. Sur ordinateur le contenu est centré dans 1 200 px au maximum, le fond restant pleine largeur.
- **Hauteur** calculée côté PHP (`Renderer::profile_height()`) et reproduite par l’aperçu : `max(bar_height, lignes × ⌈police × 1,3⌉ + 12 [+ 22 + 4 en stacked])`. Exemples : ordinateur par défaut 44 px ; mobile par défaut 80 px ; ordinateur stacked 2 lignes 76 px ; mobile inline 1 ligne 44 px. `bar_height` devient une **hauteur minimale**. Le marquee reste toujours sur une ligne (`lines` effectif = 1).
- **Lignes multiples** : en rotation, le titre unique occupe toute la largeur et est tronqué à N lignes (`-webkit-line-clamp`) ; en statique et en manuel, chaque article devient une carte de `min(80cqi, 30em)` de large, tronquée à N lignes, dans la liste à défilement horizontal natif (snap en manuel).
- **Style du label** : `strip` = bloc pleine hauteur (police héritée) en `inline`, bloc de 22 px en capitales en `stacked` ; `pill` = pastille de 22 px, rayon 999 px, capitales, `max(11px, .8em)`, espacement .06em, marge de 12 px en `inline` ; `hidden`. Point « en direct » : pseudo-élément `::before` de 6 px en `currentColor`, pulsation 2 s (supprimée avec `prefers-reduced-motion`), jamais sur un label masqué.
- Miniature (`show_thumbnail`) : hauteur `max(lignes × 1,3em, 32px)` (0 px de minimum en `stacked`).

### 15.5.3 Mode de défilement, compteur, progression, balayage

`mobile_ticker_mode` : `rotate` (défaut, recommandé : un titre à la fois, lisible en entier), `inherit` (mode de l’ordinateur), `static`, `marquee`, `manual`. Sur ordinateur, le mode reste `ticker_enabled` + `ticker_mode`.

- Le mode effectif mobile détermine, avec le mode ordinateur, les boutons présents dans le markup (Pause/Lecture pour `marquee`/`rotate`, Précédent/Suivant pour `manual`) ; le JavaScript masque (`hidden`) les boutons sans objet pour le profil effectif.
- Rotation (les deux profils) : intervalle `rotate_interval`, **compteur** (`*_show_counter`, « 2/8 », `aria-hidden`, zone `meta` de la grille, créé par le script), **ligne de progression** (`*_show_progress`) : piste de 2 px sur le **bord supérieur** de la barre (fond `currentColor` à 18 %, remplissage couleur d’accent — couleur du texte sur ordinateur, `mobile_accent_color` sur mobile — animé par `transform` sur son `::after`, en pause avec la barre), transition d’apparition du titre (désactivée avec `prefers-reduced-motion`).
- **Balayage tactile** (mobile uniquement) gauche/droite (`mobile_swipe`, seuil 40 px, sens inversé en RTL ; le glissement du pointeur sur le lien ne déclenche ni glisser-déposer natif ni clic, et libère le focus donné au lien par le pointeur).
- Les règles d’accessibilité du §16.4 s’appliquent inchangées (Pause/Lecture obligatoire, `prefers-reduced-motion` = aucune animation automatique).

### 15.5.4 Repli au défilement (mobile, label sur sa ligne)

`mobile_hide_on_scroll` (défaut `true`, sans effet en `inline`) : en défilant vers le bas au-delà de 120 px, la barre se replie (`hprnb-bar--collapsed`, `transform: translateY(...)`) en laissant visible le bandeau de 36 px (`--hprnb-peek` : pastille + compteur) ; elle se redéploie au défilement vers le haut ou à l’appui sur le bandeau ; un focus clavier (`:focus-visible`) dans la barre la redéploie et la maintient ouverte 4 s. La transition est supprimée avec `prefers-reduced-motion`. Le padding de réservation du `<body>` ne change pas (`--hprnb-m-height` sous 768 px).

### 15.5.5 Palette mobile

`mobile_custom_colors` (défaut `true`) : la barre mobile utilise `mobile_bg_color` (défaut `#141414`, fond quasi opaque avec léger flou d’arrière-plan), `mobile_text_color` (`#F5F5F5`), `mobile_accent_color` (`#E11D2A` : pastille ou bandeau, point live, progression) et `mobile_label_text_color` (`#FFFFFF`). Désactivé : la barre mobile reprend les couleurs de l’ordinateur. L’administration calcule le contraste texte/fond mobile (avertissement sous 4,5:1, non bloquant).

### 15.5.6 Séparateur mobile

`mobile_show_separator` (défaut `false`) : sous 768 px, le séparateur du §15.7 n’est affiché qu’à la demande (classes `hprnb-root--m-sep` / `hprnb-root--m-sep-loop`), avec le même caractère et la même règle « après le dernier » ; sur ordinateur `show_separator` reste seul maître. En rotation, jamais de séparateur.

Toutes ces valeurs passent par des classes (`hprnb-root--{d|m}-{inline|stacked}`, `hprnb-root--d-end`, `hprnb-root--{d|m}-label-{pill|strip|hidden}`, `hprnb-root--{d|m}-dot`, `hprnb-root--{d|m}-wrap`, `hprnb-root--m-sep[-loop]`, `hprnb-root--m-colors`, `hprnb-root--m-collapse`), des variables (`--hprnb-height`, `--hprnb-d-lines`, `--hprnb-m-bg`, `--hprnb-m-fg`, `--hprnb-m-accent`, `--hprnb-m-label-fg`, `--hprnb-m-font-size`, `--hprnb-m-height`, `--hprnb-m-lines`) et les JSON `data-hprnb-desktop` / `data-hprnb-mobile` portés par `#hprnb-root`, hors cache (§9.2).

## 15.6 Mode statique

Ticker désactivé :

- aucune animation ;
- viewport horizontal scrollable de manière native si nécessaire ;
- scrollbar visuelle discrète/masquée selon navigateur ;
- liens restent accessibles ;
- aucun JS nécessaire.

## 15.7 Séparateur

Le séparateur entre articles est un **pseudo-élément CSS** `::after` sur `.hprnb-bar__item` ; aucun élément DOM, aucune modification du HTML des `<li>`. Le contenu vient du jeton effectif `--hprnb-e-sep` (entre les articles) / `--hprnb-e-sep-last` (après le dernier), alimenté par `--hprnb-sep` sur ordinateur via `hprnb-bar--sep` / `hprnb-bar--sep-loop` et sur mobile uniquement si `mobile_show_separator` (§15.5.6).

- caractère : variable `--hprnb-sep` (chaîne CSS) portée par `#hprnb-root`, valeur par défaut `'•'` ;
- activation : classe `hprnb-bar--sep` sur `#hprnb-root` (`show_separator = true`) ;
- séparateur après le dernier article : classe `hprnb-bar--sep-loop` sur `#hprnb-root` (`separator_after_last = true`, ignorée côté serveur si `show_separator = false`) ;
- sélecteurs :

```css
.hprnb-bar--sep .hprnb-bar__item:not(:last-child)::after,
.hprnb-bar--sep-loop .hprnb-bar__item:last-child::after {
    content: var(--hprnb-sep, '•');
    margin-inline-start: 1.25em;
    opacity: .7;
}
```

- même `--hprnb-sep`, même opacité, même `margin-inline-start` pour le séparateur final ; **aucun** `margin-inline-end` (ni espace superflu en fin de ligne, ni débordement horizontal) ;
- le contenu généré est masqué des lecteurs d’écran (`content: … / ""` lorsque la syntaxe est prise en charge) ;
- mode `marquee` : la liste est dupliquée ; la jonction clone → original affiche **exactement un** séparateur lorsque `hprnb-bar--sep-loop` est présente (zéro sinon), jamais deux ;
- mode `rotate` : aucun séparateur affiché, dernier compris (`.hprnb-bar--ticker-rotate .hprnb-bar__item::after { content: none }`) ;
- mode `manual` et liste statique : le séparateur final s’affiche si l’option est active, sans défilement horizontal parasite de la page.

---

## 15.8 En continu v2 (2.0) — cahier des charges client v1.1

La version 2.0 applique le cahier des charges client « barre En continu v2 » (§3, §4, §6, §7, §8, §9) en fusionnant les prototypes `hprnb-bar-v2.css` / `.js` dans le cœur du plugin.

### 15.8.1 Défauts v2

Fond `#1B1C20` (texte `#F5F5F5`, contraste 16:1) sur les deux appareils ; pastille `#CE3029` / `#FFFFFF` (24 px, rayon 999 px, capitales 11,5 px, espacement .08em, point de 6 px à halo pulsé 1,8 s, ombre `0 1px 0 rgba(0,0,0,.25)`) ; accent `#CE3029` (progression, soulignement au survol) ; label « EN CONTINU » au début ; ordinateur 40 px (32–56) à 15 px, marquee actif à 30 px/s (10–80), pause au survol, séparateurs « • » à 45 % ; mobile 16 px / 26 px, carte 76 px (64–96), rotation 5 s (3–12) ; bouton fermer actif, mémoire 24 h ; contenu aligné sur le conteneur du site (`align_container`, `max_width` 1230, `gutter` 15) ; `theme_offset` actif. Préréglages de couleurs « Sombre + pastille rouge » (défaut) et « Rouge plein ». Une installation 1.x reçoit une fois ce préréglage à la mise à niveau (schéma 2, `Settings::maybe_upgrade()`).

### 15.8.2 Ordinateur

Barre de 40 px (+ zone sûre), grille `label | compteur | titre | contrôles` dans un conteneur de `max_width` px avec une gouttière de `gutter` px ; fondu de 28 px aux bords du marquee (`mask-image`) ; boutons de 40 px, rayon 8 px, fond au survol ; focus 2 px dans la couleur du texte ; aucun bouton de partage ni emplacement étranger.

### 15.8.3 Mobile : carte « flow », bandeau replié, effacement

- `mobile_layout = flow` (défaut, rotation uniquement ; les autres modes retombent sur `stacked`) : `.hprnb-bar__inner` en `display: block`, pastille en `float: inline-start` centrée sur la ligne 1, viewport / liste / article / lien / titre en blocs à `white-space: normal` ; le titre coule sur `mobile_lines` lignes de `round(police × 1,625)` px (26 px pour 16 px) et repasse sous la pastille ; le viewport est rogné à N lignes (`overflow: clip`, qui conserve l’habillage du flottant) ; contrôles en `position: absolute` en haut à droite ; la carte réserve `--hprnb-m-ctrls × 40 px` à droite ; progression de 3 px sur le bord supérieur.
- Métriques (`Renderer::flow_metrics()`) : hauteur = `max(mobile_bar_height, lignes × ligne + 12)`, padding = `(hauteur − lignes × ligne) / 2`, bandeau replié `--hprnb-peek` = padding + ligne + 2 (40 px par défaut).
- Repli (`mobile_hide_on_scroll`) : `translateY(100% − peek)`, `cursor: pointer`, liens à `pointer-events: none` (un tap n’ouvre jamais de lien), boutons masqués sauf le chevron `.hprnb-bar__btn--expand` (créé par le script, `aria-label` traduit) ; `mobile_peek = label` efface le titre du bandeau (`hprnb-root--peek-label`) ; `mobile_deep_collapse` : replié d’emblée sans transition si `scrollY > 120` à l’initialisation.
- `mobile_kbd_hide` : un focus dans un champ de formulaire hors de la barre (sous 768 px) ajoute `body.hprnb-kbd` (barre translatée à 110 %, `--hprnb-offset: 0`) ; retour au blur.
- Paysage (`max-height: 480px` et `max-width: 1023.98px`) : 44 px, une ligne, jamais de repli (`!important` sur les variables de la racine et du body), chevron masqué.
- Points de suspension (2.0.1) : le script pose `is-clipped` sur le viewport quand le titre dépasse ses lignes ; un `::after` « … » sur un fondu de 28 px termine la dernière ligne (et la ligne unique du bandeau replié).
- Vignette (`show_thumbnail`, 2.0.1) : carré arrondi de `ligne − 2` px (24 px) en `position: absolute` dans la colonne des boutons, sous Pause / Fermer, masqué replié ; le viewport n’est rogné que verticalement (`overflow: visible clip`).
- Pastille (2.0.1) : marge intérieure 12 / 14 px ; point « en direct » clignotant (opacité, échelle, halo) ; en bandeau replié la pastille pulse comme un bouton (`hprnb-beacon`, halo `--hprnb-glow` dans la couleur du label).
- Bandeau replié (2.1) : la pastille se réduit à un point rouge de 24 px collé à la gouttière (`hprnb-beacon`), le texte du label est masqué et le fondu de l'ellipse resserré à 18 px ; l'option `mobile_peek = label` conserve la pastille complète.
- Image par profil (2.1) : `{desktop|mobile}_show_thumbnail`, `{desktop|mobile}_thumb_position` (`before`/`after`) et `{desktop|mobile}_thumb_size` (16–80 px). Le `<img>` entre dans le markup dès qu'un profil l'active (clé de cache) ; l'affichage, l'ordre (flex) et la taille sont des jetons `--hprnb-e-thumb-*` posés par les classes `hprnb-root--{d|m}-thumb[-after]`, hors cache. Sur ordinateur la hauteur de barre suit l'image (`taille + 12 − 4`) ; sur la carte mobile l'image sort du flux dans sa propre colonne (`padding-inline` de l'inner + position absolue centrée), plafonnée à `lignes × interligne − 2`, et la pastille se réduit au point rouge ; dans le bandeau replié l'image et sa colonne disparaissent.
- Boutons de la carte (2.2) : `mobile_controls_layout` (`column` par défaut : Fermer au-dessus de Pause, `column-reverse`, colonne d'une largeur de bouton dont la hauteur suit le bloc de titre ; `row` : rangée classique). `Renderer::mobile_controls()` renvoie le nombre de **colonnes** (1 si empilés) et `--hprnb-m-ctrls` réserve la largeur correspondante ; le bandeau replié ramène la colonne à une ligne.
- Image du bandeau replié (2.2) : `mobile_peek_thumbnail` (défaut activé) place l'image entre le titre et le chevron, dimensionnée à `min(taille, interligne − 4)` et centrée sur la ligne visible, la réservation de largeur suivant (`--hprnb-e-peek-thumb-col`).
- Pastille clignotante (2.2) : `mobile_label_pulse` (`always` par défaut, `collapsed`, `never`) anime `hprnb-beacon` sur la pastille ; la carte déployée conserve la pastille complète avec son texte, `mobile_label_compact` (désactivé par défaut) la réduisant au point rouge en présence d'une image. Le mouvement réduit supprime toute animation de la pastille.
- Moment du repli (2.3) : `mobile_collapse_mode` (`scroll` par défaut : repli en descendant au-delà de `mobile_collapse_after`, réouverture en remontant ; `threshold` : replié dès le seuil franchi et le reste ; `immediate` : replié d'emblée, seul un tap ouvre la carte) et `mobile_collapse_after` (0 à 800 px, 120 px par défaut). `mobile_hide_on_scroll` continue de gouverner l'existence même du repli.
- Boutons hors barre (2.3) : `mobile_controls_place = outside` sort `.hprnb-bar__controls` en `position: absolute` au-dessus du coin supérieur de la barre (groupe arrondi, fond à 96 % d'opacité et flou d'arrière-plan en bonus, boutons de 34 px) ; `.hprnb-bar` et `.hprnb-bar__inner` passent alors en `overflow-x: clip; overflow-y: visible` — le rognage horizontal qui protège la page reste, mais le groupe sort vers le haut au lieu d'être invisible. La classe d'empilement `hprnb-root--m-ctrl-col` n'est pas émise dans ce mode. `--hprnb-m-ctrls` passe à 0 et le titre prend toute la largeur, chevron du bandeau replié compris.
- Affichage des boutons (2.3) : `mobile_show_pause` et `mobile_show_close` masquent chacun le sien sous 768 px (le script suit les clés `pause` / `close` du profil mobile). Les deux masqués, `Renderer::mobile_controls()` renvoie 0 ; la gouttière du chevron n'est plus réservée que dans le bandeau replié, par `max(var(--hprnb-m-ctrls), 1)` dans la feuille de style.
- Motion réduite : aucune translation ni pulsation.

### 15.8.3 bis Moment d'apparition de la barre (2.3)

`reveal_mode` (les deux profils) choisit quand la barre entre en scène : `immediate` (défaut, comportement historique), `scroll` (après `reveal_value` pixels de défilement, 400 px par défaut — recommandé dans un article : le début de la page reste dégagé), `percent` (après `reveal_value` % de la hauteur défilable, borné à 1–100) et `end` (90 % de la page). Tant que le seuil n'est pas franchi, la racine porte `hprnb-root--pending` (`translateY(110%)`, `pointer-events: none`), le body porte `hprnb-pending` (`--hprnb-offset: 0px`, aucun `padding-block-end`) et `window.hprnbBar.state()` renvoie un offset nul : le thème et les autres plugins voient une barre absente. Le script (`setupReveal()`) retire les deux classes au franchissement, sur un `scroll` passif, et ne les repose jamais — une fois apparue, la barre reste. Un mode différent de `immediate` suffit à rendre `Renderer::needs_interactive_js()` vrai.

### 15.8.3 ter Liseré d'accent (2.3)

`accent_edge` (activé par défaut) pose `hprnb-root--edge` : `box-shadow: inset 0 2px 0 var(--hprnb-e-accent)` sur `.hprnb-bar`, que la ligne de progression de la rotation vient remplir. C'est la seule amélioration de visibilité retenue : elle détache la barre du contenu du site sans toucher aux contrastes du texte (fond #1B1C20 / texte #F5F5F5 = 16:1, pastille #CE3029 / blanc = 5,2:1, tous deux au-dessus du seuil AA).

### 15.8.3 quater Types de pages par profil, placement dans l'article et repli sur ordinateur (2.4)

- **Types de pages par profil** : `desktop_contexts` et `mobile_contexts` (mêmes clés que `contexts`) restreignent la portée globale. `Visibility::devices_for_context()` croise ces cartes avec `show_on_{desktop|mobile}` ; `Visibility::with_context_devices()` en fait une copie des réglages que `Frontend` et `Placement` passent au `Renderer`, si bien que la classe `hprnb-hide-{mobile|desktop}` sort du contexte courant sans que le `Renderer` n'appelle jamais de conditional tag. Les deux profils refusés, `should_display()` est faux et rien n'est rendu.
- **Placement dans l'article** : `{desktop|mobile}_placement` (`fixed` par défaut, `inline`), `_inline_anchor` (`before`, `after`, `before_end`) et `_inline_paragraph` (1 à 30). `Placement::paragraphs()` découpe le contenu après chaque `</p>` de premier niveau ; `Placement::offsets()` ramène les trois ancres à un nombre de paragraphes fermés (`before` → N−1, `after` → N, `before_end` → total−N), borné à l'article. Le filtre `the_content` (priorité 20, après `wpautop`) insère la racine à l'offset de l'ordinateur — ou à celui du mobile s'il est seul — et réclame le root pour que le pied de page ne le rende pas une seconde fois. Un contenu sans paragraphe laisse la barre au pied de page.
- **Deux paragraphes différents** : une ancre vide `<div class="hprnb-slot" data-hprnb-slot="m">` est insérée à l'offset du mobile ; `relocate()` déplace `#hprnb-root` dedans sous 768 px et le ramène à sa place au-dessus, en mémorisant son parent d'origine.
- **Pleine largeur** : la racine en flux porte `hprnb-root--{d|m}-inflow` et `alignfull`. Un gabarit de blocs contraint centre ses enfants avec `margin-inline: auto !important`, d'où des déclarations forcées ; la formule CSS centrée (`calc(50vw - 50%)`) ne sert que de repli, le script mesurant l'écart réel au bord de l'écran (`--hprnb-bleed`) et la largeur réelle du viewport (`--hprnb-bleed-w`) après avoir neutralisé le décalage. La container query de la section 14 continue donc de mesurer l'écran et non la colonne de l'article.
- **En flux** : `position: static`, aucun espace réservé (`body.hprnb-reserve:has(.hprnb-root--{d|m}-inflow)` remet `--hprnb-offset` à 0 et supprime le `padding-block-end`), aucun repli, chevron masqué.
- **Repli à partir de 768 px** : `desktop_hide_on_scroll`, `desktop_collapse_mode` et `desktop_collapse_after` réutilisent `setupCollapse()`. La barre passe à `translateY(100%)` et ses contrôles remontent en `inset-block-end: 100%` sous forme d'onglet arrondi de 30 px aligné sur la gouttière ; la section 14 ne montrant le chevron que sous 768 px, la section 13 bis fait la bascule équivalente au-dessus. `--hprnb-offset` vaut alors 0.
- **Design « Découvrir »** (`mobile_layout = card`, `mobile_card_thumb` 80–220 px) : `Renderer::card_metrics()` donne la police du titre (profil + 2 px), l'interligne (× 1,5), l'image (largeur × 0,625), le nombre de lignes `max(1, ⌊(hauteur image − interligne) / interligne⌋)` (exposé en `--hprnb-m-card-lines`), la hauteur `2 × 14 + max(hauteur image, interligne + lignes × interligne)` et le bandeau replié `14 + interligne + 2`. C'est donc la seule largeur d'image qui règle la taille de la carte (116 px par défaut, 82 px à 80, 166 px à 220) ; `mobile_lines` ne s'y applique pas.
- **Disposition** : l'image est en `position: absolute` contre `.hprnb-bar__inner` (`inset-inline-start: gouttière`, donc à droite en RTL) et l'inner lui réserve sa colonne par `padding-inline-start`. La pastille occupe la première ligne de la colonne de texte, centrée sur l'interligne, et la fenêtre de titre suit en dessous : le titre commence à la deuxième ligne. La fenêtre **n'est pas positionnée**, sans quoi son `overflow: visible clip` rognerait l'image, qui est plus haute que les lignes de titre. Un article sans image reprend la colonne par `:not(:has(… .hprnb-bar__thumb))`.
- **Animation d'entrée** : l'article apparaît en fondu (`hprnb-appear`) et c'est le titre qui monte (`hprnb-rise`). Un `transform` animé sur l'article en ferait le bloc conteneur de l'image positionnée, qui sauterait dans la colonne de texte à chaque rotation ; le mouvement réduit coupe les deux.
- **Boutons** : placés un à un (`.hprnb-bar__controls { display: contents }`). Le bouton Fermer devient un onglet carré de 52 × 46 px au-dessus du coin de fin (`inset-block: auto 100%`, `inset-inline-end: 0`, couleur de la barre, liseré d'accent), la barre et l'inner passant en `overflow-x: clip; overflow-y: visible` pour le laisser sortir ; Pause reste sur la première ligne et ne s'y réserve de largeur que s'il est réellement affiché (`:has(.hprnb-bar__btn--toggle:not([hidden]))`). Le design ignore `mobile_controls_place` / `mobile_controls_layout` (classes non émises) et `mobile_controls()` y renvoie 0.
- **Bandeau replié** : identique à celui de la carte « flow ». L'image et sa colonne s'effacent, la pastille repasse en `float: inline-start` et se réduit à son point rouge pulsant (mêmes règles que `--m-flow`, étendues à `--m-card`), la fenêtre est ramenée à un interligne et le titre à une ligne ; la règle générale `.hprnb-bar--collapsed .hprnb-bar__btn { display: none }` retire l'onglet Fermer.
- `Settings::wants_thumbnails()` inclut ce design pour que l'`<img>` entre dans le markup mis en cache ; le style de label `strip` y devient un titre en gras sans fond.
- **Types de pages : nom des champs (2.4.1)** : le type de champ `contexts` écrit ses cases sous la clé du réglage rendu (`hprnb_settings[{clé}][{contexte}]`) et non sous `contexts` en dur — la 2.4.0 vidait ainsi `desktop_contexts` et `mobile_contexts` à chaque enregistrement et masquait la barre partout. Schéma 4 : une carte entièrement à faux est remise à « tous les types » à la mise à niveau.

### 15.8.3 quinquies Séparateur d'un article unique, carte flottante et apparition intelligente (2.5)

- **Séparateur** : `separator_after_last` fait exister `--hprnb-e-sep-last`, que `.hprnb-bar__item:last-child::after` consomme ; avec un seul article celui-ci est aussi le dernier, d'où la puce orpheline. Deux règles de même spécificité (0,2,1) placées après les deux règles de base la neutralisent : `.hprnb-bar__item:only-child::after` et `[data-hprnb-count="1"] .hprnb-bar__item::after`. La première est réévaluée dans chaque `<ul>`, donc le clone du marquee est couvert ; la seconde couvre une liste à laquelle un tiers aurait ajouté un nœud. Le mode rotation supprimait déjà tout séparateur. Aucune valeur de réglage n'est modifiée, et à partir de deux articles le comportement est strictement celui de la 1.1.
- **`data-hprnb-count`** sur la racine, écrit par `Renderer::root()` depuis `$payload['count']`, mis à jour par le bootstrap au rafraîchissement REST **et** dans sa copie `sessionStorage` (qui enregistrait jusqu'ici un compte falsifié de 1).
- **Carte flottante** : `inset-inline: var(--hprnb-m-gap, 8px)`, `inset-block-end: calc(var(--hprnb-m-gap, 8px) + env(safe-area-inset-bottom, 0px))`, `padding-block-end: 0` — la zone sûre n'est appliquée qu'une fois. `Renderer::mobile_gap()` vaut 8 pour la carte et 0 ailleurs ; la valeur voyage dans le style en ligne de la racine, dans celui du body (`Frontend::enqueue_bar_assets()`) et par `ensureLayout()` du bootstrap, et s'ajoute à `padding-block-end` comme à `--hprnb-offset`, côté CSS comme côté contrat JS. Le glissement du repli ne soustrait pas la zone sûre sur cette disposition, puisqu'elle n'est pas dans sa hauteur.
- **Métriques** : `CARD_PAD` 12, `CARD_GAP` 10, `CARD_RATIO` 0,78, `CARD_LABEL` 20, `CARD_ROW` 6, `CARD_LINES` 2, `CARD_LINE` 1,24, `CARD_FONT_PLUS` 2. Hauteur `2 × 12 + max(hauteur image, 20 + 6 + 2 × ligne)`, soit 99 px par défaut (94 à 118 sur la plage 72–120 de `mobile_card_thumb`). Sous 360 px de large, une media query ramène l'image à 72 × 56 ; en paysage, la carte passe à une ligne avec une image de 44 × 34.
- **Bouton Fermer** : à l'intérieur, `inset-block-start` / `inset-inline-end` égaux au padding de la carte, 34 px visibles, rayon 9 px, fond `color-mix(in srgb, var(--hprnb-e-fg) 12%, transparent)`, cible tactile portée à 44 px par un `::after` en `inset: -5px` qui ne déborde que dans le padding. Sa colonne (38 px, 76 avec Pause) est réservée **par défaut** dans `padding-inline-end` de l'inner et rendue au titre par `:not(:has(…))` quand aucun bouton n'est affiché : perdre `:has()` coûte de la largeur, jamais la garantie de non-recouvrement. Le clic appelle `preventDefault()` et `stopPropagation()`.
- **Apparition intelligente** : `reveal_mode = smart` est une branche de `setupReveal()`, qui ne possède qu'un point de décision, `reveal( reason, extra )`, gardé par un booléen `done`. `setupSmart()` cherche le corps éditorial (`smart_selector` puis une chaîne de sélecteurs, un candidat n'étant retenu que s'il contient un `<p>` et mesure plus de 40 px), mémorise sa géométrie à l'initialisation, au redimensionnement et via un `ResizeObserver`, et n'appelle jamais `getBoundingClientRect()` dans le gestionnaire de défilement. `progress()` rapporte la part du corps passée sous le bas de l'écran. Trois signaux : un sentinel d'un pixel en fin de corps observé par `IntersectionObserver` (`article_end`), le cumul de remontée avec remise à zéro dès 40 px vers le bas et rejet du rebond (`scroll_up_intent`), et le repli d'engagement (`engaged_reader`), désactivé quand le corps mesure moins de 1,5 écran. Le temps de lecture actif est suspendu hors visibilité et après 60 s d'inactivité ; un battement d'une seconde, arrêté au déclenchement, permet aux conditions temporelles d'aboutir sans défilement.
- **Mesure** : `createAnalytics()` pousse `hprnb_impression`, `hprnb_click` et `hprnb_close` sur `window.dataLayer` quand il existe et les émet toujours sur `document`. Aucun appel réseau n'est fait : la règle « pas de télémétrie » du §2 reste tenue, rien ne quitte la page si le site ne le décide pas lui-même. L'aperçu de l'administration n'émet rien.

### 15.8.4 Contrat avec le thème et les autres plugins

`body.hprnb-reserve` porte `--hprnb-offset` (hauteur visible : `--hprnb-height` sur ordinateur, `--hprnb-m-height` ou `--hprnb-peek` sur mobile, 0 avec `hprnb-kbd` ou `hprnb-pending`), les classes `hprnb-is-collapsed`, `hprnb-kbd`, `hprnb-theme-offset`, `hprnb-pending` ; le script émet `hprnb:state` sur `document` (`{ mobile, collapsed, height, offset }`) à chaque changement (repli, déploiement, clavier, fermeture, redimensionnement) et expose `window.hprnbBar.state()`. Compatibilité Jannah (option `theme_offset`) : `#go-to-top` à `offset + safe-area + 12 px`, `#check-also-box` à `offset + 15 px`, `#reading-position-indicator` à `offset + safe-area` en `z-index` 99991, avec transition. Rien n’est modifié dans le thème ; rien n’est inséré dans la barre.

### 15.8.5 Administration

Page par onglets (Contenu, Affichage, Couleurs, Fermeture, Thème, Avancé), en-tête collant (nom, version, « Réinitialiser l’onglet », « Enregistrer »), cartes par module avec interrupteur « Activer » dans l’en-tête (`show_on_desktop`, `show_on_mobile`, `mobile_custom_colors`, `close_button`, `theme_offset`, `enabled`) qui grise les lignes sans les masquer ; préréglages de couleurs ; contraste calculé ; hauteur calculée affichée en regard des lignes de titre ; aperçu en direct ; onglet mémorisé (ancre + `localStorage`) ; sans JavaScript, tous les panneaux sont visibles et le formulaire reste entièrement utilisable.

# 16. TICKER OPTIONNEL

`ticker_enabled = false` par défaut.

Modes :

- `marquee` ;
- `rotate` ;
- `manual`.

## 16.1 Marquee

- jamais `<marquee>` HTML ;
- duplication visuelle unique ;
- clone `aria-hidden="true"` ;
- liens clone `tabindex="-1"` ;
- animation CSS par `transform` ;
- durée calculée en fonction de la largeur et de `ticker_speed` ;
- pas de `setInterval` pour déplacer les pixels ;
- pause quand `document.hidden = true` ;
- recalcul via `ResizeObserver` si disponible, avec debounce de secours ;
- pas d’animation si le contenu tient dans le viewport.

## 16.2 Rotate

- un item visible à la fois ;
- intervalle configurable ;
- pause quand document caché ;
- pause au focus systématique ;
- les items cachés utilisent `hidden` ;
- aucun séparateur (dernier compris) : il n’a aucun sens sur un item isolé.

## 16.3 Manual

- aucune animation auto ;
- boutons précédent/suivant ;
- scroll natif tactile ;
- `scroll-snap` ;
- boutons désactivés aux extrémités.

## 16.4 Accessibilité des animations — règle obligatoire

Pour `marquee` et `rotate`, le plugin doit automatiquement afficher un vrai bouton :

**Pause / Lecture**

Ce bouton existe même si :

- `pause_on_hover = false` ;
- `close_button = false`.

Il est clavier-accessible et possède un `aria-label` mis à jour.

Un simple hover ne suffit pas comme mécanisme d’accessibilité.

`prefers-reduced-motion: reduce` :

- aucune animation automatique ;
- passage au comportement statique/manual ;
- aucune transition décorative.

`pause_on_hover` reste une option de confort supplémentaire.

---

# 17. HEURE RELATIVE

Option désactivée par défaut.

Rendu :

```html
<time datetime="..." data-hprnb-ts="...">il y a 2 h</time>
```

Serveur :

- `human_time_diff()` ;
- au-delà du seuil : date absolue au format WordPress.

Client :

- recalcul toutes les 60 s uniquement si l’option est active ;
- suspendre le timer lorsque l’onglet est caché ;
- reprendre lorsque visible ;
- chaînes traduites.

Sans JS, le texte SSR reste acceptable mais peut vieillir derrière un cache page.

---

# 18. BOUTON FERMER

Option désactivée par défaut.

Bouton :

- SVG inline ;
- aucune librairie icône ;
- cible minimum 44×44 CSS px ;
- `aria-label`.

Au clic :

- masquer la barre ;
- retirer `body.hprnb-reserve` ;
- gérer le focus proprement.

Si le bouton avait le focus, ne jamais le supprimer brutalement sans déplacement du focus.

Approche recommandée :

1. masquer la barre ;
2. déplacer le focus vers un landmark principal focusable ;
3. si nécessaire ajouter temporairement `tabindex="-1"` au landmark ;
4. retirer ce tabindex temporaire après focus.

---

# 19. MÉMORISATION DE FERMETURE

Option distincte :

`remember_dismiss`

Stockage :

`localStorage`

Clé :

`hprnb_dismissed_until`

Aucun cookie.

Tout accès localStorage dans `try/catch`.

## 19.1 Anti-flash

Si la mémorisation est active, fournir un **micro-script head** conditionnel, exécuté avant peinture, uniquement pour vérifier la clé localStorage et ajouter une classe :

`hprnb-dismissed`

sur `<html>`.

Ce micro-script :

- n’effectue aucun appel réseau ;
- ne dépend d’aucune librairie ;
- doit être minuscule ;
- n’est chargé que si `remember_dismiss = true`.

CSS :

```css
html.hprnb-dismissed #hprnb-root {
    display: none;
}
```

Ainsi aucune barre ne clignote avant d’être masquée.

---

# 20. VISIBILITÉ

Défaut :

`everywhere`

Contextes configurables :

- front page ;
- blog home ;
- single post ;
- page ;
- category ;
- tag ;
- archive ;
- search ;
- 404.

Exclusions par ID :

`display_exclude_ids`

## 20.1 Exclusions absolues

Aucune barre sur :

- wp-admin ;
- AJAX ;
- cron ;
- REST ;
- XML-RPC ;
- WP-CLI ;
- feeds ;
- robots.txt ;
- trackback ;
- oEmbed ;
- preview ;
- login ;
- sitemap core ;
- AMP si détecté par API publique du plugin AMP.

Ne pas ajouter d’intégrations spécifiques Yoast/Rank Math uniquement pour leur sitemap.

## 20.2 Device

Si desktop et mobile sont tous deux désactivés :

- aucun HTML ;
- aucun CSS ;
- aucun JS ;
- aucune requête métier.

---

# 21. SHORTCODE

Disponible si `shortcode_enabled = true`.

```text
[hprnb_news_bar]
```

Aucun attribut V1.

Le shortcode utilise le Renderer commun.

Anti-double rendu :

- un seul root par page ;
- si shortcode rendu, `wp_footer` ne rend pas une seconde barre ;
- deuxième shortcode retourne vide.

Le shortcode respecte les exclusions absolues.

Il peut ignorer la portée contextuelle volontairement, puisque son insertion est explicite.

---

# 22. ADMINISTRATION

Menu :

**Réglages → Barre d’actualités**

Capacité :

`manage_options`

Un seul formulaire.

Sections :

1. Général
2. Contenu
3. Apparence
4. Comportement
5. Visibilité
6. Avancé
7. Outils

Sans JavaScript, le formulaire reste entièrement utilisable.

## 22.1 Général

- activer ;
- label ;
- position ;
- valeur de fenêtre ;
- unité ;
- catégories incluses ;
- nombre max ;
- ordre.

## 22.2 Filtres avancés

Repliés par défaut :

- catégories exclues ;
- tags inclus ;
- IDs articles exclus.

## 22.3 Catégories/tags

Récupération WordPress native.

Interface :

- cases à cocher ;
- champ de filtre local JavaScript ;
- « Tout sélectionner » ;
- « Tout désélectionner ».

Pas d’endpoint async supplémentaire en V1.

## 22.4 Apparence

- couleurs ;
- font size ;
- hauteur ;
- label start/end ;
- reserve/overlay ;
- miniature ;
- séparateur ;
- séparateur après le dernier article (`separator_after_last`, libellé « Afficher le séparateur après le dernier article (boucle continue) », placé juste sous le caractère de séparation, grisé tant que le séparateur est désactivé) ;
- heure relative.

Aucun champ CSS libre.

## 22.4 bis Présentation ordinateur

Section dédiée, entre Apparence et Présentation mobile :

- position du label (`desktop_layout` : devant le titre, sur la même ligne / sur sa propre ligne au-dessus du titre) ;
- style du label (bandeau, pastille, masqué) et point « en direct » ;
- compteur ;
- lignes de titre (1 à 4), avec la hauteur calculée affichée en regard et recalculée en direct ;
- ligne de progression.

Dans Apparence, « Hauteur de la barre » devient « Hauteur minimale de la barre ».

## 22.4 ter Présentation mobile

Mêmes réglages que 22.4 bis (préfixe `mobile_`), plus :

- taille de police mobile ;
- mode de défilement mobile (rotation recommandée, hérité, statique, marquee, manuel) ;
- balayage tactile, repli au défilement ;
- séparateur mobile (désactivé par défaut) ;
- palette mobile dédiée (case « Couleurs mobiles » ; les quatre couleurs sont grisées quand elle est décochée) avec avertissement de contraste.

Sans JavaScript, le formulaire reste entièrement utilisable.

## 22.5 Comportement

- ticker ;
- mode ;
- vitesse ;
- intervalle ;
- pause hover ;
- fermer ;
- mémorisation ;
- desktop/mobile.

## 22.6 Avancé

- mode hybride / PHP ;
- cache TTL ;
- stale threshold ;
- auto display ;
- shortcode ;
- suppression des données à la désinstallation.

---

# 23. APERÇU ADMIN

Aperçu visuel sticky sur desktop, sous le formulaire sur petit écran.

Deux onglets : **Ordinateur** (profil ordinateur ; `hprnb-root--flat` neutralise la container query) et **Mobile** (cadre de 375 px rendant le profil mobile avec la même feuille de style). L’aperçu charge le script interactif de la barre : rotation, progression, compteur, marquee et boutons se comportent comme sur le site ; le repli au défilement est désactivé dans l’aperçu. Les hauteurs affichées en regard des sélecteurs « Lignes de titre » sont recalculées en direct par le script d’administration (même formule que le PHP).

Mises à jour purement visuelles :

**aucun appel réseau**.

Exemples :

- couleurs ;
- taille ;
- label ;
- position ;
- séparateur, caractère de séparation et séparateur après le dernier article (bascule immédiate, purement visuelle, aucun appel serveur) ;
- tous les réglages mobiles sauf le mode de défilement mobile (qui modifie le markup et passe par l’actualisation) ;
- options d’apparence.

Pour les critères de contenu, fournir un bouton :

**Actualiser les articles de l’aperçu**

Optionnellement, déclencher automatiquement le même appel après un debounce de 800 ms.

Endpoint d’aperçu :

`POST /wp-json/hprnb/v1/preview`

Obligatoire :

- `manage_options` ;
- nonce REST ;
- réglages reçus passés dans le même validateur ;
- jamais de cache public ;
- jamais d’écriture des réglages ;
- jamais de publication de données privées.

Si résultat vide :

> Aucun article ne correspond à ces critères. La barre ne sera pas affichée sur le site.

---

# 24. IMPORT / EXPORT

## 24.1 Export

JSON :

```json
{
  "_meta": {
    "plugin": "horizon-press-news-bar",
    "schema_version": 1,
    "plugin_version": "1.0.0",
    "exported_at": "..."
  },
  "settings": {}
}
```

Nonce + `manage_options`.

## 24.2 Import

Taille max :

256 Ko.

Le fichier n’est jamais exécuté ni conservé.

Contrôles :

- erreur upload ;
- taille ;
- extension `.json` ;
- lecture du fichier temporaire ;
- `json_decode` avec profondeur bornée ;
- schéma valide ;
- `schema_version` compatible ;
- liste blanche pilotée par `Settings::schema()` ;
- sanitization complète.

**Ne pas rejeter un JSON valide uniquement parce qu’un serveur lui attribue un MIME générique comme `text/plain` ou `application/octet-stream`.**

Le contenu parsé et le schéma constituent la vraie validation de sécurité.

Clés inconnues : ignorées.

IDs importés : avertissement car les catégories/posts peuvent différer entre sites.

## 24.3 Reset

- case de confirmation ;
- confirmation JS supplémentaire si disponible ;
- nonce ;
- capability ;
- restauration exacte des defaults ;
- invalidation cache.

---

# 25. INTERNATIONALISATION

Toutes les chaînes visibles sont traduisibles.

Fournir :

- `.pot` ;
- `fr_FR.po` ;
- `fr_FR.mo`.

Ne pas déclencher de traduction avant `init`.

Pour ce plugin privé avec fichiers locaux, si `load_plugin_textdomain()` est nécessaire, l’appeler sur `init`, jamais sur `plugins_loaded`.

Aucune phrase construite par concaténation.

Pluriels via `_n()`.

Placeholders numérotés dans `sprintf`.

---

# 26. RTL

Support obligatoire.

Utiliser d’abord :

- `inset-inline` ;
- `padding-inline` ;
- `margin-inline` ;
- `start/end` ;
- flex order logique.

Le label :

- `start` = début de ligne ;
- `end` = fin de ligne.

Sur LTR, `end` = droite.

Sur RTL, `end` = gauche.

Le sens du marquee est inversé automatiquement.

`dir="auto"` sur le composant.

L’administration doit également être utilisable en RTL.

---

# 27. ACCESSIBILITÉ

Cible :

**WCAG 2.2 AA** sur le composant.

Obligatoire :

- région nommée ;
- `aria-live="off"` ;
- liste sémantique ;
- boutons natifs ;
- focus visible ;
- contraste texte ≥ 4.5:1 ;
- focus ≥ 3:1 ;
- cible tactile des contrôles 44×44 ;
- aucun clone de marquee dans l’ordre de tabulation ;
- mouvement réduit ;
- Pause/Lecture pour toute animation automatique ;
- zoom 200 % ;
- pas de contenu important inaccessible uniquement au hover ;
- mode `reserve` par défaut pour limiter l’obstruction du contenu.

L’admin calcule le contraste des couleurs et affiche un avertissement sans bloquer la sauvegarde.

---

# 28. SÉCURITÉ

Obligatoire :

- garde ABSPATH dans tous les fichiers PHP exécutables ;
- capabilities ;
- nonces ;
- validation puis sanitization ;
- escaping au dernier moment ;
- aucune requête SQL manuelle ;
- aucun HTML utilisateur arbitraire ;
- aucun fichier uploadé conservé ;
- REST public lecture seule ;
- REST preview privé ;
- aucun secret ;
- aucun tracking ;
- aucune donnée personnelle.

Échappement :

- texte → `esc_html()` ;
- attribut → `esc_attr()` ;
- URL → `esc_url()` ;
- couleur → `sanitize_hex_color()` à l’entrée ;
- IDs → `absint()`.

L’HTML REST provient exclusivement du Renderer PHP échappé.

Avant injection client :

```js
typeof payload.html === 'string'
```

obligatoire.

Aucune autre source ne peut alimenter `innerHTML`.

---

# 29. ARCHITECTURE PHP

Structure recommandée :

```text
horizon-press-news-bar/
├── horizon-press-news-bar.php
├── uninstall.php
├── readme.txt
├── README.md
├── CHANGELOG.md
├── LICENSE
│
├── includes/
│   ├── class-autoloader.php
│   ├── class-plugin.php
│   ├── class-activator.php
│   ├── class-settings.php
│   ├── class-time-window.php
│   ├── class-query.php
│   ├── class-cache.php
│   ├── class-invalidation.php
│   ├── class-renderer.php
│   ├── class-visibility.php
│   ├── class-assets.php
│   ├── class-frontend.php
│   ├── class-rest-controller.php
│   ├── class-shortcode.php
│   └── admin/
│       ├── class-admin.php
│       ├── class-settings-page.php
│       ├── class-preview.php
│       └── class-import-export.php
│
├── templates/
│   ├── bar.php
│   ├── list.php
│   └── item.php
│
├── assets/
│   ├── css/
│   │   ├── hprnb-bar.css
│   │   ├── hprnb-bar-rtl.css
│   │   └── hprnb-admin.css
│   └── js/
│       ├── hprnb-bootstrap.js
│       ├── hprnb-bar.js
│       └── hprnb-admin.js
│
└── languages/
```

Règles :

- aucune logique métier dans le bootstrap principal ;
- Query ne rend pas HTML ;
- Renderer n’interroge pas la base ;
- Cache ne construit pas WP_Query ;
- Frontend orchestre ;
- Settings est le seul accès aux options ;
- Renderer est l’unique source du markup.

Ne pas imposer `declare(strict_types=1)` partout.

Le code WordPress public/hook callbacks doit privilégier la robustesse face aux valeurs fournies par Core et d’autres plugins.

Le typage PHP est encouragé sur les méthodes internes lorsqu’il ne crée pas de risque de `TypeError` au niveau d’un hook WordPress.

---

# 30. JAVASCRIPT

Vanilla uniquement.

Aucun jQuery.

Utiliser le mécanisme WordPress moderne de stratégie `defer` lorsque pertinent.

Le JS interactif n’est chargé que si nécessaire.

Le bootstrap hybride peut être chargé sans les modules interactifs.

Tout accès :

- localStorage ;
- sessionStorage ;

est protégé par `try/catch`.

Page Visibility API :

- suspendre ticker/timers dans un onglet caché.

Aucune boucle rapide.

Aucun `setInterval` pour déplacer un marquee pixel par pixel.

Pas de `console.log` production.

---

# 31. ASSETS

CSS front chargé seulement si une barre est visible en SSR, ou dynamiquement si REST ajoute une barre.

Le root transporte les variables CSS dynamiques afin qu’un chargement tardif de la CSS conserve l’apparence configurée.

JS interactif seulement si :

- ticker ;
- close ;
- heure relative ;
- commandes manuelles.

Admin CSS/JS :

uniquement sur la page du plugin.

Versionner les assets avec `HPRNB_VERSION`.

---

# 32. LIFECYCLE

## Activation

- vérifier WP/PHP ;
- si incompatibilité pendant l’activation : désactiver proprement et afficher un message d’activation clair ;
- ne jamais provoquer de fatal sur une requête normale ;
- créer settings si absents ;
- créer cache epoch ;
- créer schema version ;
- aucun flush rewrite ;
- aucun cron ;
- aucune table ;
- aucune redirection marketing.

## Désactivation

- conserver réglages ;
- changer cache epoch ;
- ne pas scanner la base pour supprimer les transients ;
- les transients expirent au plus tard après le TTL maximal.

## Désinstallation

Si `uninstall_delete_data = false` :

ne rien supprimer.

Si `true` :

- supprimer les options du plugin ;
- ne supprimer aucun post/média/terme ;
- les transients courts déjà créés peuvent expirer naturellement.

Multisite :

aucune interface réseau V1 ; ne jamais casser un site parce qu’il est multisite.

---

# 33. HOOKS DÉVELOPPEUR

Filtres utiles :

```php
hprnb_settings
hprnb_query_args
hprnb_items
hprnb_should_display
hprnb_cache_ttl
hprnb_stale_threshold
hprnb_bar_html
```

Actions :

```php
hprnb_before_bar
hprnb_after_bar
hprnb_cache_invalidated
```

Ne pas multiplier les hooks sans besoin.

Les documenter.

---

# 34. PLAN DE TEST AUTOMATISÉ

Claude Code doit créer des tests de développement hors ZIP production.

Minimum PHPUnit / WordPress test suite pour :

- sanitization du schéma ;
- window minutes/heures/jours ;
- UTC ;
- passage DST ;
- query args ;
- category include/exclude ;
- tags ;
- exclusion ID ;
- sticky ;
- futur/brouillon/privé ;
- cache empty ;
- cache hit ;
- invalidation ;
- cache epoch ;
- Renderer zéro item ;
- Renderer avec items ;
- visibilité ;
- REST 200 ;
- REST count 0 ;
- ETag/304 si implémenté ;
- import liste blanche ;
- shortcode anti-double ;
- séparateur : classes `hprnb-bar--sep` / `hprnb-bar--sep-loop` sur le root, aucun élément DOM, `--hprnb-sep` échappée ;
- `separator_after_last` ignoré si `show_separator = false` ;
- `show_separator`, `separator_char` et `separator_after_last` absents de la clé de cache ;
- présentation mobile : classes/variables/JSON du root, boutons présents selon les modes ordinateur et mobile, `mobile_ticker_mode` seul réglage mobile dans la clé de cache.

Si l’environnement permet Playwright :

- Chromium ;
- Firefox ;
- WebKit.

Scénarios :

- desktop ;
- mobile ;
- RTL ;
- ticker ;
- pause/play ;
- close ;
- reduced motion ;
- injection hybride ;
- séparateur de boucle : 4 modes (statique, marquee, rotate, manual) × LTR/RTL × option activée/désactivée ; jonction clone → original du marquee avec exactement un séparateur ; aucun séparateur en mode rotate ; aucun débordement horizontal ;
- mobile empilé : pastille, compteur, progression, rotation automatique, balayage, repli au défilement et redéploiement, palette mobile, disposition en ligne (label devant le titre, 54 px puis 44 px sur une ligne), label masqué, mouvement réduit, franchissement du seuil 375 ↔ 1366 px, aperçu admin Mobile/Ordinateur, audit axe-core ;
- v2 : carte mobile 76 px (pastille 24 px à 15 px, titre 16/26 px sur deux lignes rognées à 52 px), bandeau 40 px avec chevron, liens inactifs repliés, `--hprnb-offset` 76 → 40 → 76, `body.hprnb-is-collapsed`, évènement `hprnb:state`, option « pastille seule », clavier (`body.hprnb-kbd`, offset 0, retour au blur), arrivée en milieu de page repliée (et non repliée si désactivé), paysage 44 px sans repli, ordinateur 40 px dans 1230 px avec pastille à 15 px, vitesse 30, fondu, boutons 40 px, `#go-to-top` à 52 px et indicateur de lecture à 40 px / z 99991, option `theme_offset` et `align_container` désactivées ; page admin par onglets (préréglages, hauteur calculée, réinitialisation de l’onglet) ;
- profils de présentation : design empilé sur ordinateur (pastille, point pulsant, compteur, 2 lignes = 76 px, progression sur le bord supérieur, contenu centré ≤ 1 200 px), cartes sur 3 lignes en statique (69 px, label bandeau pleine hauteur, label au début), marquee toujours sur une ligne (44 px), label bandeau et point sur mobile, séparateur mobile désactivé puis activé ; miroir des sections CSS ordinateur/mobile (test statique).

Les tests de développement peuvent vivre hors du dossier inclus dans le ZIP final.

---

# 35. RECETTE MANUELLE OBLIGATOIRE

Avant ZIP final, vérifier au minimum :

### Fenêtre

- 23h59 dans fenêtre 24h → oui ;
- 24h01 → non ;
- 30 min → correct ;
- 3 jours → correct ;
- date modifiée aujourd’hui mais publication ancienne → non ;
- fuseau différent du serveur → correct.

### Contenu

- toutes catégories ;
- une catégorie ;
- plusieurs catégories ;
- catégorie exclue ;
- tag ;
- post ID exclu ;
- sticky ancien ;
- sticky récent ;
- password protected ;
- draft/private/future.

### Vide

- PHP mode + 0 item → aucun HTML ;
- hybrid + 0 item → root invisible uniquement ;
- hybrid page ancienne + nouvel article → apparition après refresh conditionnel ;
- dernier article sort de fenêtre → disparition.

### Cache

- hit : pas de WP_Query contenu ;
- miss : une seule requête principale ;
- aucun N+1 ;
- publication → invalidation ;
- changement catégorie → invalidation ;
- settings → invalidation ;
- cache vide également mis en cache.

### Hybrid

- HTML frais → aucun fetch ;
- HTML stale → un fetch maximum ;
- sessionStorage plus ancien que SSR → jamais appliqué ;
- sessionStorage plus récent → peut être appliqué ;
- endpoint 500 → SSR conservé ;
- horloge client incohérente → pas de boucle ;
- nouvelle réponse plus ancienne que SSR → ignorée.

### Ticker

- disabled → aucun JS ticker ;
- marquee boucle propre ;
- rotate ;
- manual ;
- contenu trop court → pas d’animation ;
- Pause/Lecture ;
- hover ;
- focus ;
- document hidden ;
- reduced motion ;
- séparateur après le dernier article : jonction marquee identique aux autres jonctions.

### Profils de présentation

- 320/375/390/430 px : pastille + titre sur toute la largeur, deux lignes max, aucune troncature « … » en marquee ;
- rotation avec progression (bord supérieur) et compteur ; balayage ; repli/redéploiement au défilement ;
- palette mobile et police 16 px ; label devant le titre (`mobile_layout = inline`) sur 1 puis 2 lignes ;
- ordinateur : design empilé (pastille, point, compteur, 2 lignes), cartes multi-lignes en statique/manuel, label bandeau ou masqué, hauteur affichée dans l’administration conforme à la barre ;
- rotation d’écran : réinitialisation propre.

### Responsive

- 320 ;
- 375 ;
- 390 ;
- 430 ;
- 768 ;
- 1366 ;
- 1920.

Aucun overflow horizontal de page.

### RTL

- label end ;
- ordre ;
- ticker ;
- contrôles ;
- admin.

### A11y

- clavier ;
- VoiceOver ou NVDA si disponible ;
- axe DevTools si disponible ;
- contraste ;
- zoom 200 % ;
- focus jamais masqué.

### Sécurité

- XSS label ;
- XSS titre ;
- import clé inconnue ;
- import HTML/script ;
- nonce absent ;
- capability absente ;
- REST preview anonyme refusé.

---

# 36. OUTILS DE QUALITÉ

Exécuter lorsque disponibles :

```text
php -l
PHPCS WordPress Coding Standards
Plugin Check
PHPUnit
```

Le code doit produire :

- zéro fatal ;
- zéro warning ;
- zéro notice ;
- zéro deprecated provenant du plugin ;

avec :

`WP_DEBUG = true`

sur la matrice PHP réellement disponible.

Les traductions ne doivent pas être chargées trop tôt.

---

# 37. RAPPORT DE PERFORMANCE

Le rapport final doit indiquer :

- taille CSS ;
- taille bootstrap JS ;
- taille JS interactif ;
- nombre de WP_Query contenu sur hit/miss ;
- comportement avec/sans persistent object cache si testé ;
- nombre de requêtes REST sur HTML frais/stale ;
- existence ou non de N+1 ;
- CLS observé ;
- erreurs console.

Ne jamais prétendre « 0 SQL total » sans distinguer les transients WordPress lorsque l’object cache persistant est absent.

---

# 38. LIVRABLES FINAUX

Claude Code doit fournir sans demander d’étape supplémentaire :

1. `horizon-press-news-bar.zip`
2. dossier source final ;
3. `README.md`
4. `readme.txt`
5. `CHANGELOG.md`
6. `LICENSE`
7. `uninstall.php`
8. `.pot`
9. `fr_FR.po`
10. `fr_FR.mo`
11. rapport `QA-REPORT.md`
12. checklist de recette complétée ;
13. liste exacte des tests réellement exécutés ;
14. liste éventuelle des tests non exécutables dans l’environnement.

ZIP production :

ne doit pas contenir :

- `.git` ;
- `node_modules` ;
- `vendor` si inutile runtime ;
- tests ;
- captures ;
- backups ;
- fichiers temporaires ;
- secrets ;
- IDE metadata.

---

# 39. CRITÈRES BLOQUANTS DE RÉCEPTION

La livraison est refusée si au moins un de ces points est vrai :

- barre visible sans article ;
- article hors fenêtre ;
- article d’une mauvaise catégorie ;
- double barre ;
- requête WP_Query à chaque page malgré cache hit ;
- N+1 ;
- fetch REST à chaque page fraîche ;
- sessionStorage ancien écrasant un SSR récent ;
- ticker auto sans Pause/Lecture ;
- animation malgré `prefers-reduced-motion` ;
- overflow horizontal ;
- erreur console ;
- warning PHP ;
- notice PHP ;
- traduction déclenchée trop tôt ;
- CSS/JS admin chargé partout ;
- XSS possible ;
- endpoint preview accessible anonymement ;
- custom CSS arbitraire ;
- dépendance à un plugin de cache ;
- dépendance externe ;
- ZIP non installable.

---

# 40. DÉFINITION DE « TERMINÉ »

Le travail n’est terminé que lorsque Claude Code a :

1. créé le plugin ;
2. validé la syntaxe de tous les PHP ;
3. exécuté les tests disponibles ;
4. corrigé les erreurs ;
5. réexécuté les tests ;
6. construit le ZIP ;
7. inspecté le contenu du ZIP ;
8. vérifié que le plugin s’active ;
9. vérifié le scénario par défaut ;
10. produit `QA-REPORT.md`.

Claude Code ne doit pas répondre seulement avec du code ou une architecture.

La réponse finale doit être structurée ainsi :

```text
PLUGIN TERMINÉ

ZIP :
<chemin>

TESTS EXÉCUTÉS :
...

RÉSULTATS :
...

TESTS NON EXÉCUTÉS :
...

LIMITES CONNUES :
...

AUCUN TODO BLOQUANT :
oui/non
```

---

# ANNEXE A — ARBRE DE DÉCISION

```text
WP hook "wp"

si disabled -> STOP
si contexte absolu interdit -> STOP
si desktop=false ET mobile=false -> STOP
si scope custom non autorisé -> STOP
si current ID exclu -> STOP
si hprnb_should_display=false -> STOP

payload = mémoire requête ?
sinon cache transient ?
sinon WP_Query + Renderer + cache

mémoriser payload


wp_enqueue_scripts

si inéligible -> rien

mode php:
    count=0 -> rien
    count>0 -> CSS
    interactif -> bar.js

mode hybrid:
    bootstrap.js toujours
    count>0 -> CSS
    interactif -> bar.js
    count=0 -> pas de CSS principal


wp_footer

si inéligible -> rien
si déjà rendu -> rien

mode php:
    count=0 -> rien
    count>0 -> root + payload.html

mode hybrid:
    root toujours
    count>0 -> payload.html
    count=0 -> root vide
```

---

# ANNEXE B — INVARIANTS

- au plus un root ;
- au plus un WP_Query contenu par requête PHP ;
- zéro WP_Query contenu sur hit ;
- zéro aside vide ;
- zéro animation sans contrôle Pause/Lecture ;
- zéro asset quand contexte inéligible ;
- zéro JS ticker si ticker désactivé et aucune autre fonction interactive ;
- zéro fetch REST si SSR frais ;
- au plus un fetch REST par chargement ;
- session payload jamais plus ancien que le SSR qu’il remplace ;
- Renderer unique pour SSR, REST et shortcode ;
- aucun accès direct à l’option settings hors classe Settings ;
- aucun `wp_is_mobile()` ;
- aucune détection de cache page ;
- aucune donnée privée REST ;
- aucune table SQL ;
- aucun cron ;
- aucune dépendance externe ;
- `show_separator`, `separator_char` et `separator_after_last` n’entrent jamais dans la clé de cache (classes et variable CSS sur le root).

### 15.8.3 sexies — Contrôle par article et page de réglages (2.6.0)

1. **Bloc « Barre d'actualités » sur l'écran d'édition** (articles et pages), deux cases indépendantes : *Ne jamais lister cet article dans la barre* (articles seuls) et *Ne jamais afficher la barre sur cette page* (tout type public). Métadonnées `_hprnb_exclude_item` et `_hprnb_hide_bar`, nonce dédié, `edit_post`, autosaves et révisions ignorées, sauvegarde sans le bloc sans effet, nettoyage à la désinstallation.
2. **L'exclusion est une clause SQL** posée avant le filtre `hprnb_query_args` : la barre se remplit à nouveau au lieu de rétrécir sous `max_items`. Les deux drapeaux font tourner l'époque, aucun n'entre dans la clé de cache.
3. **La carte « Découvrir » honore `mobile_lines`**, plafond propre de 3 lignes. 116 px sur trois lignes à 16 px, 118 px avec l'image la plus large — sous le plafond de 120 px. L'aperçu admin applique la même borne.
4. **Page de réglages en six onglets** nommés d'après la question posée : Contenu, Où, Apparition et repli, Ordinateur et mobile, Couleurs, Avancé. Un seul endroit décide des types de pages ; l'interrupteur de repli est un interrupteur ; chaque carte qui le mérite porte un dépliant « Cas d'usage courants ». Aucun réglage supprimé, aucun schéma modifié.
5. **Trois correctifs trouvés par inspection** : la taille de police d'ordinateur était déclarée mais sur aucun onglet (donc inaccessible, et réinitialisée à chaque enregistrement) ; l'animation d'entrée empruntait la transition du repli, désactivé par défaut sur ordinateur ; `prefers-reduced-motion` ne couvrait pas la barre d'ordinateur.

### 15.8.3 septies — Avant la fin de l'article et repli qui suit la lecture (2.7.0)

1. **Mode d'apparition `paragraph`** : la barre apparaît dès que le Nᵉ paragraphe compté depuis la fin du corps de l'article entre à l'écran ; `reveal_paragraph` (défaut 2, de 1 à 30). Corps de l'article via `smart_selector` puis les sélecteurs usuels ; paragraphes vides et texte de la barre exclus ; repli honnête sur « vers la fin de la page » sans corps ni paragraphe. Une position au-delà du paragraphe (restauration, lien profond) affiche la barre immédiatement — position mesurée et recalculée au reflux, comparée dans un défilement passif, jamais un `IntersectionObserver` (qui ne voit pas un saut).
2. **Repli `article`** sur chaque profil, B = point d'apparition, C = fin du corps : première apparition entière ; repliée entre le haut et B quel que soit le sens ; entre B et C ouverte en descendant, repliée à toute remontée ; au-delà de C toujours ouverte. `collapse_after` ignoré ; tap et retenue de quatre secondes inchangés.
3. **Aucun second moteur** : une branche dans `setupReveal()`, une dans `setupCollapse()`, la position d'apparition et le corps d'article partagés sur l'état de la barre. Aucun schéma modifié.

---

# ANNEXE C — CONSIGNE FINALE À CLAUDE CODE

**Exécute ce cahier des charges entièrement. Ne me demande pas de confirmer chaque lot. Implémente, teste, corrige et livre directement le ZIP final et le QA report. En cas de choix interne non spécifié, privilégie l’API WordPress Core la plus simple et la plus sûre. Ne revendique jamais un test qui n’a pas été réellement exécuté.**
