# Journal des modifications

Ce projet suit les principes de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le versionnage sémantique.

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
