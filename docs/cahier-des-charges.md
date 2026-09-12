# Cahier des charges FINAL AUDITÉ — Plugin WordPress « Horizon Press News Bar »

> **Version 2.0 — 11 septembre 2026**  
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
| Version V1 | `1.0.0` |
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
 * Version:           1.0.0
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
- ticker ;
- pause au survol ;
- bouton fermer ;
- mémorisation de la fermeture.

Activées par défaut mais configurables :

- affichage desktop ;
- affichage mobile ;
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
  "label_text": "TOUTE L’ACTUALITÉ",
  "label_position": "end",

  "window_value": 24,
  "window_unit": "hours",

  "categories_include": [],
  "categories_exclude": [],
  "tags_include": [],
  "content_exclude_post_ids": [],

  "max_items": 10,
  "orderby": "date_desc",

  "bg_color": "#B00000",
  "text_color": "#FFFFFF",
  "label_bg_color": "#8F0000",
  "label_text_color": "#FFFFFF",
  "link_hover_color": "#FFFFFF",
  "font_size": 14,
  "bar_height": 44,
  "z_index": 99990,
  "layout_mode": "reserve",

  "show_relative_time": false,
  "relative_time_max_hours": 48,

  "show_thumbnail": false,
  "thumbnail_size": "thumbnail",

  "show_separator": false,
  "separator_char": "•",

  "ticker_enabled": false,
  "ticker_mode": "marquee",
  "ticker_speed": 60,
  "rotate_interval": 5000,
  "pause_on_hover": false,

  "close_button": false,
  "remember_dismiss": false,
  "dismiss_duration_hours": 24,

  "show_on_desktop": true,
  "show_on_mobile": true,

  "display_scope": "everywhere",
  "contexts": {
    "front_page": true,
    "blog_home": true,
    "single_post": true,
    "page": true,
    "category": true,
    "tag": true,
    "archive": true,
    "search": true,
    "not_found": true
  },
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

- CSS principal minifié : objectif ≤ 8 Ko ;
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
- variables CSS visuelles dans son attribut `style`.

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
  class="hprnb-root hprnb-device-all"
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

## 15.5 Mobile

Comportement déterministe :

- label reste présent ;
- une seule ligne ;
- `text-overflow: ellipsis` ;
- largeur max du label : `min(38vw, 220px)` ;
- aucune transformation automatique en initiale ;
- pas de `position: sticky` spéciale ;
- viewport `min-width: 0` ;
- pas de scrollbar horizontale de la page.

## 15.6 Mode statique

Ticker désactivé :

- aucune animation ;
- viewport horizontal scrollable de manière native si nécessaire ;
- scrollbar visuelle discrète/masquée selon navigateur ;
- liens restent accessibles ;
- aucun JS nécessaire.

---

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
- les items cachés utilisent `hidden`.

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
- heure relative.

Aucun champ CSS libre.

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

Mises à jour purement visuelles :

**aucun appel réseau**.

Exemples :

- couleurs ;
- taille ;
- label ;
- position ;
- séparateur ;
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
- shortcode anti-double.

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
- injection hybride.

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
- reduced motion.

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
- aucune dépendance externe.

---

# ANNEXE C — CONSIGNE FINALE À CLAUDE CODE

**Exécute ce cahier des charges entièrement. Ne me demande pas de confirmer chaque lot. Implémente, teste, corrige et livre directement le ZIP final et le QA report. En cas de choix interne non spécifié, privilégie l’API WordPress Core la plus simple et la plus sûre. Ne revendique jamais un test qui n’a pas été réellement exécuté.**
