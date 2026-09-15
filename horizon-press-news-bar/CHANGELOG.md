# Journal des modifications

Ce projet suit les principes de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le versionnage sémantique.

## [2.4.1] — 2026-09-15

### Corrigé

- **La barre disparaissait après un enregistrement** (bug de la 2.4.0) : les listes « Types de pages » des deux profils écrivaient leurs cases sous le nom de la portée globale (`hprnb_settings[contexts][…]`) ; à l'enregistrement, `desktop_contexts` et `mobile_contexts` arrivaient vides, tout passait à faux, les deux profils étaient refusés sur chaque page et rien n'était rendu — l'aperçu, qui ne passe pas par cette vérification, restait normal. Le champ écrit désormais sous sa propre clé. **Schéma 4** : une carte de types entièrement à faux, qui ne pouvait venir que de ce bug, est remise à « tous les types » à la mise à niveau ; une sélection partielle est conservée.
- Un test PHPUnit rend la page de réglages et vérifie que chaque carte de cases poste sous son propre nom puis survit à `sanitize_form()` ; le scénario Playwright de l'administration vérifie, après « Enregistrer », que le front affiche toujours la barre pour les deux profils.

### Modifié — design « Découvrir »

- **Pastille au-dessus de l'image** : l'image passe au début de la ligne (à droite en RTL), sous la ligne de titre ; le titre se place à côté, vers la fin.
- **Titre plus grand** : deux tailles au-dessus de la police mobile (16 → 18 px), interligne 1,5, sur autant de lignes que l'image en tient (`Renderer::card_metrics()['lines']`, exposé en `--hprnb-m-card-lines`) et jamais moins que le réglage.
- **Bouton Fermer seul, hors de la barre** : un onglet carré de la couleur de la barre (52 × 46 px, coin arrondi, liseré d'accent) au-dessus du coin de fin de la carte — à gauche en RTL comme sur la maquette. Le bouton Pause, s'il est activé, reste dans la ligne de titre. Le design place ses boutons lui-même : `mobile_controls_place` et `mobile_controls_layout` ne s'y appliquent pas et `Renderer::mobile_controls()` y renvoie 0.
- Le style de label « bandeau » devient un titre en gras sans fond de 16 px ; « pastille » garde la pastille rouge.
- `--hprnb-m-line` et `--hprnb-m-pad` suivent la carte en usage (`Renderer::mobile_metrics()`).

## [2.4.0] — 2026-09-15

### Ajouté

- **Types de pages par profil** (`desktop_contexts`, `mobile_contexts`) : chaque profil restreint la portée globale de l'onglet Avancé. Un profil refusé sur la page courante reçoit sa classe `hprnb-hide-*` (et l'espace réservé disparaît) ; les deux refusés, rien n'est rendu du tout.
- **Placement dans l'article** (`{desktop|mobile}_placement`, `_inline_anchor`, `_inline_paragraph`) : la barre peut quitter le bas de l'écran pour devenir un bloc de l'article, **avant** un paragraphe, **après** un paragraphe, ou **avant les N derniers paragraphes** — chaque profil ayant son propre numéro. Dans ce mode elle est en pleine largeur (`alignfull` plus un décalage mesuré par le script, pour sortir d'un gabarit contraint sans déborder), en flux (`position: static`), sans espace réservé (`--hprnb-offset: 0`) et sans repli. Un article sans paragraphe la laisse en bas de l'écran.
- Quand les deux profils demandent deux paragraphes différents, le serveur place la barre au paragraphe de l'ordinateur et laisse une ancre vide (`.hprnb-slot`) à celui du mobile ; le script déplace la racine sous 768 px et la ramène au-dessus.
- **Repli à partir de 768 px** (`desktop_hide_on_scroll`, `desktop_collapse_mode`, `desktop_collapse_after`) : mêmes trois moments que sur mobile (en descendant, dès le seuil, toujours repliée). La barre glisse entièrement hors de vue et laisse un petit onglet arrondi contre le bord inférieur ; rien n'est réservé tant qu'elle est repliée.
- **Second design mobile « Découvrir »** (`mobile_layout = card`, `mobile_card_thumb`) : une ligne de titre, puis le titre sur plusieurs lignes à côté d'une grande image paysage 16:10 (80 à 220 px de large), boutons dans le coin supérieur. Le design est construit autour de son image et l'ajoute toujours au markup ; un article sans image rend toute la largeur au titre, gouttière comprise. Replié, seule la ligne de titre reste visible.
- La page de réglages sait griser une dépendance sur la **valeur** d'un bouton radio (`depends` en `clé:valeur`) et plus seulement sur une case à cocher.

### Modifié

- `Renderer::profile()` expose `placement`, et `profile_data()` ajoute `place` aux deux profils ainsi que `collapse` / `trigger` / `after` au profil ordinateur.
- `Settings::wants_thumbnails()` compte le design « Découvrir » : l'image entre dans le markup mis en cache dès qu'il est choisi.
- Le contrat expose un offset nul pour un profil en flux comme pour une barre repliée sur ordinateur.
- Budget CSS relevé à 32 Ko.

## [2.3.0] — 2026-09-15

### Ajouté

- **Moment d'apparition de la barre** (`reveal_mode`, `reveal_value` ; les deux profils) : immédiatement (défaut, comportement inchangé), après une distance de défilement (400 px par défaut — recommandé dans un article), après une proportion de la page lue, ou vers la fin de la page (90 %). Tant que le seuil n'est pas franchi, aucun espace n'est réservé (`body.hprnb-pending`, `--hprnb-offset: 0px`) et la barre reste hors champ ; une fois apparue, elle reste.
- **Moment du repli sur mobile** (`mobile_collapse_mode`, `mobile_collapse_after`) : en descendant au-delà du seuil et rouverte en remontant (défaut), dès le seuil franchi et repliée pour de bon, ou toujours repliée — le lecteur ouvre la carte d'une pression. Le seuil va de 0 à 800 px (120 px par défaut). Le repli lui-même reste gouverné par `mobile_hide_on_scroll`.
- **Boutons Pause et Fermer sortis de la barre** (`mobile_controls_place`) : un petit groupe flottant arrondi juste au-dessus du coin supérieur de la barre, en verre dépoli ; le titre récupère alors toute la largeur.
- **Affichage de chaque bouton sur mobile** (`mobile_show_pause`, `mobile_show_close`) : les deux masqués, la carte déployée prend toute la largeur et seul le bandeau replié réserve la colonne de son chevron.
- **Liseré d'accent** (`accent_edge`, activé par défaut) : un trait de 2 px de la couleur d'accent sur le bord supérieur de la barre, que la progression de la rotation vient remplir — la barre se détache nettement du contenu du site sans toucher au contraste du texte.

### Corrigé

- **Bouton Pause bloqué au toucher** : sur mobile, un tap laissait un survol émulé permanent et « Pause sur survol » maintenait la rotation en pause après la reprise (icône Lecture figée). Les écouteurs de survol ne sont plus posés que sur un pointeur fin qui sait survoler (`(hover: hover) and (pointer: fine)`) et tout `pointerdown` non-souris efface l'état de survol.

### Modifié

- `Renderer::mobile_controls()` peut renvoyer 0 : boutons sortis de la barre, ou les deux masqués. La gouttière du chevron du bandeau replié est désormais réservée par la feuille de style (`max(var(--hprnb-m-ctrls), 1)`), plus par le compte des boutons.
- L'aperçu de l'administration reflète les deux nouveaux interrupteurs de boutons et le seuil de repli sans enregistrement.
- Le groupe de boutons sorti de la barre force `overflow-x: clip; overflow-y: visible` sur `.hprnb-bar` et `.hprnb-bar__inner` (sans quoi il était rogné et invisible), n'hérite plus de la classe d'empilement et passe à 96 % d'opacité pour ne pas dépendre de `backdrop-filter`.
- Budget CSS relevé à 28 Ko, budget du script de la barre à 15 Ko.

## [2.2.0] — 2026-09-15

### Ajouté

- **Boutons empilés sur la carte mobile** (`mobile_controls_layout`, défaut « empilés ») : Fermer au-dessus de Pause, sur une seule colonne au lieu de deux — le titre gagne 40 px. L'option « côte à côte » rétablit l'ancienne rangée.
- **Image conservée dans le bandeau replié** (`mobile_peek_thumbnail`, défaut activé) : elle se place entre le titre et le chevron, redimensionnée pour ne jamais dépasser la hauteur d'une ligne (22 px pour un interligne de 26 px).
- **Pastille clignotante paramétrable** (`mobile_label_pulse` : toujours par défaut, seulement repliée, jamais) : la carte déployée garde la pastille rouge d'origine **avec son texte** et respire comme un bouton ; le mouvement réduit désactive toujours l'animation.
- `mobile_label_compact` (désactivé par défaut) pour retrouver la pastille réduite au point rouge quand une image est affichée.

### Modifié

- Avec une image, la carte mobile conserve désormais la pastille complète (la réduction au point rouge de la 2.1.0 devient optionnelle).
- `Renderer::mobile_controls()` renvoie le nombre de **colonnes** de boutons (1 quand ils sont empilés) : la réservation de largeur suit.
- Budget CSS relevé à 26 Ko.

## [2.1.0] — 2026-09-15

### Ajouté

- **Image par profil** : `desktop_show_thumbnail` / `mobile_show_thumbnail` (case à cocher), `desktop_thumb_position` / `mobile_thumb_position` (avant ou après le titre) et `desktop_thumb_size` / `mobile_thumb_size` (16 à 80 px). Les deux profils partagent le même `<img>` dans le markup ; chacun l'affiche, le positionne et le dimensionne par CSS. Le réglage `show_thumbnail` unique disparaît (schéma 3 : une installation qui l'avait activé reçoit les deux cases cochées).
- Ordinateur : l'image se place avant ou après le titre (ordre flex) ; la barre grandit si l'image dépasse la hauteur des lignes.
- Carte mobile : l'image occupe **sa propre colonne** hors du flux du texte (avant ou après le titre, devant les boutons), centrée sur le bloc de titre et plafonnée à sa hauteur — la carte garde sa hauteur quelle que soit la taille choisie. Avec une image, la pastille se réduit au point rouge pour laisser toute la largeur au titre ; elle disparaît dans le bandeau replié, colonne comprise.
- La taille source WordPress (`thumbnail_size`) passe dans l'onglet Avancé, partagée par les deux profils.

### Modifié

- **Bandeau replié** : la pastille devient un point rouge pulsant de 24 px collé à la gouttière (au lieu de la pastille complète), le fondu des points de suspension est resserré — le titre gagne près de 90 px. L'option « pastille seule » conserve la pastille complète.
- Budget CSS relevé à 24 Ko.

## [2.0.1] — 2026-09-15

### Ajouté

- Carte mobile : un titre plus long que ses lignes se termine par des **points de suspension** sur un léger fondu (classe `is-clipped` posée par le script, aussi sur la ligne unique du bandeau replié).
- Bandeau replié : la pastille « En continu » devient un **bouton rouge pulsant** (halo dans la couleur du label, animation `hprnb-beacon`, point accéléré) ; un tap n’importe où déploie la carte.
- Point « en direct » : un vrai clignotement (opacité + échelle + halo), sans dépendre de `color-mix()`.

### Corrigé

- Le jeton `padding-inline` de la pastille recevait quatre valeurs et tombait à 0 : la pastille a désormais 12 px / 14 px de marge intérieure.
- Vignette (`show_thumbnail`) dans la carte mobile : elle ne pousse plus le titre à la ligne ; elle devient un carré arrondi de 24 px dans la colonne des boutons, sous Pause / Fermer, masqué dans le bandeau replié. Sur ordinateur, coins arrondis à 4 px.
- L’animation d’apparition du titre en rotation est rétablie dans la carte mobile.
- Budget CSS relevé à 22 Ko.

## [2.0.0] — 2026-09-14

### Ajouté

- **En continu v2** (cahier des charges client v1.1, §3, §4, §6, §7, §8) : fond sombre `#1b1c20` sur les deux appareils, pastille rouge du thème `#ce3029` (24 px, capitales 11,5 px, point pulsé) alignée sur le conteneur du site (1230 px, gouttière 15 px), barre ordinateur de 40 px à 30 px/s avec fondu de 28 px aux bords, séparateurs à 45 %, boutons de 40 px, bouton fermer actif par défaut (mémoire 24 h).
- **Carte mobile « flow »** (76 px) : la pastille flotte en tête du titre qui coule sur deux lignes (16 px / 26 px) et repasse dessous ; la première ligne est le bandeau replié de 40 px avec un chevron « déployer » ; option « pastille seule » ; arrivée en milieu de page repliée d'emblée ; effacement quand un champ de formulaire est actif ; paysage (hauteur < 480 px) : une ligne de 44 px sans repli.
- **Contrat avec le thème et les autres plugins** : `--hprnb-offset` sur `body` (hauteur visible, 0 si effacée), `body.hprnb-is-collapsed`, `body.hprnb-kbd`, évènement `hprnb:state` (`{ mobile, collapsed, height, offset }`), `window.hprnbBar.state()` ; compatibilité Jannah (`#go-to-top`, `#check-also-box`, `#reading-position-indicator` décalés, option `theme_offset`).
- Nouveaux réglages : `accent_color`, `align_container`, `max_width`, `gutter`, `mobile_bar_height` (64–96), `mobile_peek`, `mobile_deep_collapse`, `mobile_kbd_hide`, `theme_offset`, disposition mobile `flow`.
- **Page de réglages par onglets** (Contenu, Affichage, Couleurs, Fermeture, Thème, Avancé) : cartes par module avec interrupteur « Activer » (options grisées, jamais masquées), préréglages de couleurs « Sombre + pastille rouge » / « Rouge plein » avec contraste calculé, bouton « Réinitialiser l'onglet », en-tête avec version et bouton Enregistrer, aperçu en direct ; entièrement utilisable sans JavaScript.

### Modifié

- Nouveaux défauts : label « EN CONTINU » au début, ticker marquee actif à 30 px/s, pause au survol, séparateurs actifs, 15 px ordinateur, hauteur 40 px (32–56), `max_items` 30 au plus, intervalle 3–12 s, palette mobile dédiée désactivée (une seule palette), point « en direct » actif, compteur mobile masqué.
- Schéma des réglages 2 : une installation 1.x reçoit une fois le préréglage de présentation v2 (`Settings::maybe_upgrade()`), le reste est conservé.
- Bandeau replié : un tap n'ouvre jamais de lien ; le chevron et le tap déploient et maintiennent 4 s.
- Budgets : CSS 20 Ko, JS interactif 13 Ko.

## [1.3.0] — 2026-09-14

### Ajouté

- Deux **profils de présentation** aux réglages identiques, Ordinateur (`desktop_*`) et Mobile (`mobile_*`) : position du label (devant le titre sur la même ligne, ou sur sa propre ligne au-dessus), style du label (bandeau, pastille, masqué), point « en direct » optionnel, compteur, nombre de lignes de titre (1 à 4) dont découle la hauteur de la barre, ligne de progression. Le design empilé mobile est donc disponible sur ordinateur.
- Titres sur plusieurs lignes hors rotation : cartes à défilement horizontal (statique, manuel).
- `mobile_show_separator` : sur mobile le séparateur n’est affiché que sur demande.
- Hauteur calculée affichée en regard des sélecteurs « Lignes de titre » de l’administration, recalculée en direct.

### Modifié

- `bar_height` devient une **hauteur minimale** ; `mobile_bar_height` est remplacé par `mobile_lines` (hauteur mobile par défaut : 80 px pour deux lignes de 16 px).
- Le point pulsant de la pastille est désactivé par défaut (`*_label_dot`).
- La ligne de progression devient une piste de 2 px sur le bord supérieur de la barre (fond discret + remplissage accent).
- Sur mobile, le label en ligne précède toujours le titre (`label_position` ne s’applique qu’à partir de 768 px).
- Feuille de style réécrite autour de jetons effectifs `--hprnb-e-*` : le profil ordinateur les fixe sur le root, le profil mobile sur `.hprnb-bar` dans la container query, le mode de défilement sur `.hprnb-bar__inner` ; un test statique vérifie que les deux sections restent le miroir l’une de l’autre. Budget CSS relevé à 14 Ko.

## [1.2.0] — 2026-09-14

### Ajouté

- Présentation mobile empilée (sous 768 px) : pastille de label avec point « en direct », compteur, titre pleine largeur sur deux lignes, rotation par défaut avec ligne de progression et balayage tactile, repli au défilement, palette mobile dédiée, police 16 px — quinze réglages `mobile_*` dans une nouvelle section « Mobile ».
- Aperçu d’administration à deux onglets (Ordinateur / Mobile) animé par le script interactif de la barre.

### Modifié

- Le seuil mobile de la présentation est mesuré par une container query sur `#hprnb-root` (même 768 px) ; les classes d’affichage par appareil restent des media queries.
- Le script interactif se détruit et se réinitialise proprement au franchissement du seuil.
- Plus aucune troncature « … » des titres en marquee sur mobile.

## [1.1.0] — 2026-09-12

### Ajouté

- Réglage `separator_after_last` (Apparence, sous le caractère de séparation, actif par défaut, grisé si le séparateur est désactivé) : le séparateur apparaît aussi après le dernier article, pour une jonction dernier → premier identique aux autres (boucle du marquee).

### Modifié

- Le séparateur est désormais un pseudo-élément CSS `::after` piloté par les classes `hprnb-bar--sep` / `hprnb-bar--sep-loop` et la variable `--hprnb-sep` sur `#hprnb-root` : aucun élément DOM, réglages du séparateur hors clé de cache, aucun séparateur en mode rotation, marges de liste neutralisées pendant le défilement pour que la jonction de boucle ait le même espacement que les autres.

## [1.0.0] — 2026-09-12

### Modifié

- Prérequis PHP abaissé de 8.1 à **8.0** à la demande du client (serveur de production en PHP 8.0.30) ; aucune syntaxe propre à PHP 8.1+ n’est utilisée, compatibilité vérifiée avec PHPCompatibilityWP 8.0–8.4.

### Ajouté

- Barre d’actualités fixe en bas de page alimentée par une fenêtre temporelle glissante (minutes / heures / jours) calculée en UTC sur `post_date_gmt`.
- Filtres de contenu : catégories incluses et exclues, étiquettes, articles exclus, nombre maximum, tri chronologique.
- Réglages d’apparence (couleurs, taille de police, hauteur, z-index, label, position, disposition `reserve` / `overlay`) transmis par variables CSS.
- Cache serveur par transients, invalidation par rotation d’epoch, TTL borné 30–600 s.
- Mode de rendu hybride (SSR + contrôle de fraîcheur + un seul appel REST au besoin) et mode PHP.
- Endpoint public `GET /wp-json/hprnb/v1/items` avec en-têtes de cache, ETag et réponse 304.
- Shortcode `[hprnb_news_bar]` avec garantie d’un seul rendu par page.
- Règles de visibilité par contexte, exclusions par identifiant, exclusions absolues (admin, AJAX, cron, REST, XML-RPC, WP-CLI, flux, robots, trackback, embed, prévisualisation, connexion, plans de site, AMP).
- Options facultatives : heure relative, miniatures, séparateur, ticker (marquee, rotation, manuel), pause au survol, bouton fermer, mémorisation de la fermeture.
- Bouton Pause / Lecture obligatoire pour les animations automatiques, respect de `prefers-reduced-motion`, cibles 44×44 px, région nommée, `aria-live="off"`.
- Page de réglages unique (sept sections) avec aperçu en direct, endpoint d’aperçu privé, import / export JSON et réinitialisation.
- Internationalisation (fichiers `.pot`, `fr_FR.po`, `fr_FR.mo`) et prise en charge RTL.
- Hooks développeur : `hprnb_settings`, `hprnb_query_args`, `hprnb_items`, `hprnb_should_display`, `hprnb_cache_ttl`, `hprnb_stale_threshold`, `hprnb_bar_html`, `hprnb_before_bar`, `hprnb_after_bar`, `hprnb_cache_invalidated`.
- Désinstallation conditionnelle (`uninstall_delete_data`).
