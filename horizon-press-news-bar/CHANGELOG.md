# Journal des modifications

Ce projet suit les principes de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le versionnage sémantique.

## [2.13.0] — 2026-09-23

### Modifié — le repli suit simplement le sens du défilement

- **Nouveau mode de repli `up`** sur chaque appareil, et c'est celui de « Lecture continue » (donc le défaut mobile) : une fois la barre apparue, **chaque défilement vers le bas la déplie et chaque défilement vers le haut la replie**, où que soit le lecteur — plus de zones ni de seuil. Le mode « Suit la lecture » (zones B et C) reste disponible en « Personnalisé ». Un site en Lecture continue passe au nouveau mode à la lecture des réglages, sans migration.

### Modifié — le titre s'efface au lieu des trois points

- Sur le bandeau avec l'image, un titre trop long **se fond dans la couleur de la barre en fin de dernière ligne** (ouvert comme replié) au lieu de finir par « … » : les polices des thèmes plaçaient les points n'importe où sous la ligne. Le fondu est dessiné dans le bon sens sur un site de droite à gauche (classe `hprnb-bar--rtl`).

### Modifié — le ZIP porte son numéro de version

- `bin/build-zip.sh` produit `dist/horizon-press-news-bar-<version>.zip`, la version étant lue dans l'en-tête de l'extension ; le dossier à l'intérieur reste `horizon-press-news-bar/`.

## [2.12.0] — 2026-09-23

### Modifié — le bandeau avec l'image de l'article, aussi sur les sites existants

- **Schéma 8**, à la demande du client : à la mise à jour, un site existant prend une fois le design mobile tel que livré — bandeau avec l'image de l'article, 2 lignes, police de 16 px, pastille « EN CONTINU » avec son point, croix seule dans l'onglet (pause masquée), image de 48 px gardée dans la bande repliée (`Settings::image_bar_design()`). Le comportement, les couleurs, le contenu et l'affichage de la croix restent ceux du site. Une seule fois : choisir la carte ensuite la garde.

### Ajouté — le choix du design par l'image

- *Mobile → Design* n'est plus une liste de phrases : **deux grandes cases, chacune avec un dessin du bandeau ouvert et replié** (la pastille, les lignes du titre, l'image, l'onglet avec la croix puis le chevron). Un clic sur la case ou sur le dessin choisit le design, et l'aperçu à droite le montre aussitôt avec les vrais titres. Dessins en éléments HTML colorés par la feuille d'administration, sans fichier image, et en miroir sur un site de droite à gauche.

## [2.11.0] — 2026-09-23

### Modifié — deux designs mobiles, et les réglages du client par défaut

- **Deux designs mobiles seulement**, dans cet ordre : **le bandeau avec l'image de l'article** (par défaut) puis **la carte « Explore More »**. La barre fluide sans image, l'étiquette sur sa propre ligne et la ligne unique quittent la liste. **Schéma 7** : un site sur l'un de ces trois designs passe au bandeau avec l'image, le plus proche ; la carte reste la carte. Le rendu garde la ligne d'étiquette en interne : elle remplace le design quand les titres ne tournent pas.
- **Nouveaux défauts mobiles** (nouvelle installation, ou « Réinitialiser l'onglet ») : bandeau avec l'image, **2 lignes**, **bouton pause masqué** (la croix seule dans l'onglet), **Lecture continue** (apparition à l'avant-dernier paragraphe, repli à toute remontée, masquage dans l'article suivant), image gardée dans la bande repliée. Une installation existante garde ses réglages ; la migration ne rallume jamais le masquage dans l'article suivant sur un site qui ne l'avait pas.

### Ajouté — repli « premium »

- Un seul mouvement, sur une courbe longue et décélérée (0,5 s, `cubic-bezier(.22, 1, .36, 1)`) : la barre glisse, **l'image se réduit et glisse jusqu'à sa place dans la bande** au lieu de disparaître (son bord extérieur ne bouge pas : la marge du titre suit la même courbe), **la pastille se referme sur son point**, le titre se pose avec un court fondu, et **le bouton de l'onglet se change en l'autre** (croix ↔ chevron) avec une rotation. Les éléments de Jannah suivent sur la même courbe. `prefers-reduced-motion` coupe tout — y compris le glissement de la barre elle-même, que la règle du repli ré-activait depuis la 2.3 (défaut trouvé en recette).

### Ajouté — le bouton pour déplier sort du bandeau

- Replié, **les deux designs gardent l'onglet au-dessus du coin**, et il porte le bouton pour déplier, exactement là où était la croix. La bande garde toute sa largeur pour la première ligne et la petite image ; la carte repliée garde désormais aussi sa petite image 16:9.
- Le contrat avec le thème expose la hauteur de l'onglet (`--hprnb-tab` sur le body, `tab` dans `hprnb:state` et `hprnbBar.state()`) : le bouton « haut de page » et l'encadré « Check also » de Jannah passent au-dessus de l'onglet.

### Ajouté — page de réglages simple, « Réglages avancés » pour tout le reste

- Par défaut, la page ne montre que les réglages de travail : Contenu (étiquette, fenêtre, nombre d'articles, catégories), Où (types de pages), Mobile et Ordinateur (comportement, design, lignes, image, rotation, boutons, fermeture), Couleurs (les quatre couleurs principales). L'interrupteur **Réglages avancés**, dans l'en-tête, montre tout le reste — y compris l'onglet Avancé — et s'en souvient dans ce navigateur. Les champs masqués restent dans le formulaire : enregistrer ne perd jamais une valeur. Sans JavaScript, tout est visible.

## [2.10.0] — 2026-09-23

### Ajouté — le bandeau fluide avec l'image de l'article

- **Nouveau design mobile `flow_image`** dans *Mobile → Design* : le bandeau fluide (la pastille « EN CONTINU » ouvre le titre, sur deux lignes par défaut, 76 px), avec **l'image de l'article en fin de ligne, à la place des boutons**, et **le bouton fermer sorti du bandeau, dans un onglet au-dessus du coin de fin** — l'onglet de la carte : 44 × 44 px, de la couleur du bandeau, collé à son bord supérieur, avec le liseré d'accent. La pause, si elle est active, se place à côté de la croix ; *Boutons (mobile) → Bouton Pause* la retire.
- L'image est toujours présente dans ce design, que la case « Image » soit cochée ou non ; sa taille (*Taille de l'image*, 48 px par défaut, carrée, jamais plus haute que le titre) et sa présence dans le bandeau replié restent réglables. Replié : la pastille, la première ligne, la petite image et le chevron.
- Implémentation : `Renderer::profile()` lit `flow_image` comme le bandeau fluide avec `thumb` forcé, `thumb_position = after` et un nouveau drapeau `tab` ; la racine reçoit `hprnb-root--m-ctrl-tab` au lieu de `--m-ctrl-col` / `--m-ctrl-out`, et `mobile_controls()` vaut 0. Aucune règle nouvelle pour l'image : celles du bandeau fluide servent telles quelles. Miroir RTL par les propriétés logiques. Aucune migration : la valeur s'ajoute à `mobile_layout`, la carte reste le défaut.
- Administration : une dépendance peut désormais énumérer des alternatives (`a,b:c`) ; la taille de l'image et l'image du bandeau replié restent actives pour ce design sans la case « Image ». L'aperçu en direct suit le design sans rechargement de la page.

## [2.9.0] — 2026-09-23

### Ajouté — un seul choix par appareil : « Comportement de la barre »

- **Nouveau réglage `mobile_behavior` / `desktop_behavior`**, premier bloc des onglets Mobile et Ordinateur, présenté en grandes cases avec une phrase simple chacune :
  - **Lecture continue — recommandé sur les articles** : masquée au départ, la barre apparaît en entier au paragraphe choisi avant la fin de l'article, se replie dès que le lecteur remonte, se rouvre quand il redescend, reste ouverte une fois l'article terminé et **disparaît complètement dans l'article suivant**. C'est la condition du client, en un clic : apparition `paragraph`, repli `article`, masquage dans l'article suivant. Seul le nombre de paragraphes reste à régler (défaut 2 = l'avant-dernier).
  - **Visible, se replie pendant le défilement** : le comportement mobile de la 2.8 (défaut mobile).
  - **Toujours visible** : le comportement ordinateur de la 2.8 (défaut ordinateur).
  - **Personnalisé** : les deux blocs détaillés (« quand la barre apparaît et disparaît », « repli ») n'apparaissent qu'avec ce choix.
- Enregistrer un comportement **écrit ses valeurs détaillées** (`Settings::behavior_presets()`, appliqué dans `Settings::sanitize()`) : le site ne lit jamais que les réglages détaillés, et « Personnalisé » part de ce que faisait le dernier choix. Le nombre de paragraphes, le seuil de repli et l'aspect du bandeau replié ne sont jamais écrasés.
- Le sélecteur du corps de l'article (`smart_selector`) passe dans l'onglet **Avancé**, bloc « Corps de l'article » : il sert à tous les comportements et aux deux appareils.

### Ajouté — la barre disparaît dans l'article suivant

- **Nouveau réglage `mobile_next_hide` / `desktop_next_hide`** (inclus dans « Lecture continue », case à cocher en « Personnalisé »). Pour les thèmes qui chargent l'article suivant sous l'article en cours (défilement continu) : dès que le haut de l'article suivant atteint le milieu de l'écran, la barre sort de l'écran et **libère son espace** (`hprnb-root--m-away` / `--d-away` sur la racine, `hprnb-m-away` / `hprnb-d-away` sur le body, `--hprnb-offset` à 0). Elle revient si le lecteur remonte dans le premier article, avec les règles de repli de celui-ci.
- L'article suivant est reconnu sans rien toucher au thème : un autre corps d'article bâti comme le premier (mêmes sélecteurs), situé sous lui, avec son bloc `<article>` quand il existe pour que son titre compte. Les articles ajoutés après le chargement sont vus grâce à un `MutationObserver` qui ne fait que marquer la mesure périmée.
- Uniquement sur un article seul (`is_singular()`) et une barre fixe : une liste d'articles complets n'a pas d'« article suivant », et une barre placée dans l'article défile déjà avec lui.

### Corrigé

- **L'onglet du bouton fermer de la carte dépassait de 31 px en bas de l'écran pendant l'attente** (2.8.0) : la barre en attente n'était décalée que de 110 % de sa hauteur, et l'onglet de 44 px posé au-dessus de la carte restait visible. La barre en attente (et désormais masquée) sort de `100 % + 60 px` et passe en `visibility: hidden` une fois le glissement fini, ce qui la retire aussi de l'ordre de tabulation.

### Migration

- **Schéma 6** : une installation existante garde exactement son comportement. Ses réglages détaillés sont nommés d'après le comportement qu'ils reproduisent déjà (« Visible, se replie… » ou « Toujours visible »), sinon **« Personnalisé »**. « Lecture continue » n'est jamais choisi par la migration, car il ajouterait le masquage dans l'article suivant. L'import d'un export antérieur passe par la même migration.
- Pour un développeur : le filtre `hprnb_settings` qui modifie un réglage détaillé d'apparition ou de repli doit aussi mettre le comportement de l'appareil sur `custom`, sinon le comportement choisi l'emporte.

## [2.8.0] — 2026-09-23

### Corrigé

- **L'aperçu de l'administration était vide ou coupé** dès que le moment d'apparition n'était pas « immédiat » : la racine de l'aperçu recevait la classe d'attente, et la règle qui pousse la barre hors de vue l'envoyait hors de la scène. `Renderer::root_classes()` gagne un paramètre `$preview` qui n'émet jamais les classes d'attente ; le script ne les cherche plus que sur la racine réelle.
- **Deux réglages étaient rendus deux fois** (les interrupteurs d'appareil, à la fois en en-tête de carte et en lignes de l'onglet Où) : le dernier champ du formulaire l'emportait en silence. Un test interdit désormais qu'un champ soit rendu deux fois.

### Modifié — le moment d'apparition se décide par appareil

- `reveal_mode`, `reveal_value` et `reveal_paragraph` deviennent **`mobile_reveal_*` et `desktop_reveal_*`**. Schéma 5 : une installation qui avait réglé le moment unique retrouve exactement ce réglage sur les deux appareils. `data-hprnb-reveal` porte un bloc par appareil (`d` / `m`) et le script lit celui du profil actif.
- **Une classe d'attente par appareil** — `hprnb-root--d-pending` / `--m-pending` sur la racine, portées par une requête de conteneur sur la largeur de la racine, et `hprnb-d-pending` / `hprnb-m-pending` sur le body — de sorte qu'un ordinateur qui apparaît tout de suite et un mobile qui attend partagent une seule racine.
- **Les migrations sont pures** (`Settings::migrate( $raw, $from )`) et **l'import les applique** : un export d'une version antérieure sautait toutes les migrations et aurait perdu son mode d'apparition.

### Modifié — page de réglages par appareil

- Six onglets : Contenu, Où, **Mobile**, **Ordinateur**, Couleurs, Avancé. Chaque appareil regroupe, dans l'ordre, son **design**, ses titres, **le moment où la barre apparaît**, **son repli** et ses boutons ; la fermeture (commune) ferme l'onglet Mobile. Le choix du design est le premier champ du premier bloc de l'onglet Mobile.

### Modifié — la carte mobile, sur la maquette du client, et par défaut

- **La carte est le design mobile par défaut** (`mobile_layout = card`), avec **3 lignes** et une **image de 132 px**. Elle suit la maquette : une ligne d'étiquette, puis l'image **16:9 (132 × 74)** au début de la ligne et le titre de 18 px en gras sur trois lignes à côté, **d'un bord à l'autre** avec des coins droits, **126 px** de haut ; la croix est un onglet de 44 × 44 px de la couleur de la carte, **au-dessus de son coin de fin**, hors de la carte (la pause, si elle est active, se place à côté). Le style d'étiquette « bandeau » donne le titre blanc en gras de la maquette (« Explore More »).
- **Flottante en option** (`mobile_card_float`, désactivé par défaut) : 8 px des bords, coins de 12 px, ombre — le rendu de la 2.5.
- Une installation existante **garde son design** : « Réinitialiser l'onglet » sur l'onglet Mobile applique la maquette en un clic.
- `mobile_card_thumb` : 72 à 160 px (défaut 132). Sur les écrans de moins de 360 px l'image passe à 96 × 54 ; en paysage à 48 × 27 sur une ligne.

## [2.7.0] — 2026-09-23

### Ajouté — apparition « avant la fin de l'article »

- **Nouveau mode `paragraph`** dans *Moment d'apparition de la barre*, à côté des cinq modes existants, tous inchangés : la barre apparaît **dès que le Nᵉ paragraphe compté depuis la fin de l'article entre à l'écran** en descendant. Nouveau réglage `reveal_paragraph` (défaut **2** = l'avant-dernier paragraphe, de 1 à 30) : un nombre plus grand affiche la barre plus tôt. Positionnel et déterministe — le même lecteur voit toujours la barre à la même ligne.
- Il mesure **le corps de l'article** (mêmes sélecteurs que le mode intelligent, `smart_selector` en tête), jamais la page ; les paragraphes vides et le texte de la barre elle-même (placement dans l'article) ne comptent pas. Sans corps d'article, ou sans paragraphe, il retombe honnêtement sur « vers la fin de la page » et le dit dans la mesure (`paragraph_fallback`).
- **Une position restaurée ou un lien profond au-delà du paragraphe affiche la barre immédiatement** (`paragraph_passed`). Ce cas a été trouvé en test : un `IntersectionObserver` ne voit jamais un paragraphe que le navigateur saute d'un coup, aussi le déclencheur compare une position mesurée — recalculée quand la page reflue — dans un défilement passif, sans lecture de mise en page dans le gestionnaire.
- Mesure : `trigger_reason` gagne `paragraph_before_end`, `paragraph_passed` et `paragraph_fallback`, avec `paragraph_from_end` et `paragraph_found`.

### Ajouté — repli « suit la lecture »

- **Nouvelle valeur `article`** pour `desktop_collapse_mode` et `mobile_collapse_mode`, à côté de `scroll`, `threshold` et `immediate`, inchangés. Trois zones sur l'article, avec **B le point où la barre est apparue** et **C la fin du corps de l'article** :
  - la première apparition est **toujours la barre entière** — c'est tout l'intérêt d'avoir attendu ;
  - **entre le haut et B**, la barre reste repliée, même en redescendant : le texte n'est jamais recouvert ;
  - **entre B et C**, elle s'ouvre quand on poursuit la lecture et **se replie à toute remontée** ;
  - **au-delà de C**, elle reste ouverte, et ne se replie plus tant qu'on ne revient pas dans le corps.
- Le seuil de repli (`collapse_after`) n'y joue aucun rôle, comme pour « toujours repliée » ; la description du champ le dit. Le tap qui ouvre la barre repliée et la retient quatre secondes vaut ici comme partout. Sans corps d'article, la fin n'est jamais atteinte et seules les deux premières zones s'appliquent.
- Aucun moteur nouveau : `setupCollapse()` gagne une branche, et partage avec `setupReveal()` la position d'apparition et le corps d'article localisé une seule fois.

### Modifié

- Le sélecteur du corps de l'article se règle aussi pour le mode « avant la fin » (il suivait seulement le mode intelligent) et voyage désormais au premier niveau de `data-hprnb-reveal` dès qu'il est renseigné.
- Nouveau cas d'usage expliqué dans l'onglet *Apparition et repli* : « Jamais sur le texte », qui combine les deux nouveautés.
- Budget du script de la barre relevé de 20 à 22 Ko (il pèse 19,9 Ko).
- Aucun schéma modifié : une installation existante garde ses modes et reçoit `reveal_paragraph` à sa valeur par défaut.

## [2.6.0] — 2026-09-16

### Ajouté — contrôle par article

- **Bloc « Barre d'actualités » sur l'écran d'édition** de chaque article et page, avec deux cases indépendantes : *Ne jamais lister cet article dans la barre* (le titre sort de la barre sur tout le site) et *Ne jamais afficher la barre sur cette page* (le lecteur de cette page ne voit aucune barre). La seconde s'applique à tout type public, la première aux articles seuls — une page n'est jamais un titre.
- L'exclusion est une clause SQL (`meta_query` `NOT EXISTS`) posée dans `Query::args()`, pas un filtre après coup : la barre **se remplit à nouveau** au lieu de rétrécir sous `max_items`.
- Les deux drapeaux font tourner l'époque de cache (`Invalidation::on_post_meta()` n'écoutait que `_thumbnail_id`), donc aucun transient périmé ne ressert un titre exclu. Ils n'entrent **pas** dans la clé de cache : un seul payload continue de servir tout le site.
- Le shortcode et la réservation de hauteur respectent la même exclusion : une barre posée à la main ne contourne pas le choix de l'auteur.
- Sécurité : nonce dédié, `current_user_can( 'edit_post' )`, sauvegardes automatiques et révisions ignorées, valeur assainie. Une sauvegarde sans le bloc (édition rapide, édition groupée, client REST) laisse les drapeaux intacts. `uninstall.php` nettoie les deux clés, derrière la même option d'effacement.

### Corrigé

- **La carte « Découvrir » ignorait `mobile_lines`.** Elle était figée à deux lignes par une constante PHP, alors que tous les autres designs mobiles honoraient le réglage : choisir 3 lignes ne changeait rien. La carte suit désormais le profil, avec un plafond propre à elle de **3 lignes** (une quatrième n'est plus une carte). Hauteurs réelles à 16 px : 99 px sur deux lignes, **116 px sur trois** (118 px avec l'image la plus large) — toujours sous le plafond de 120 px. L'aperçu admin, qui recopiait la constante en JavaScript, suit la même règle.
- **Le réglage « Taille de police » de l'ordinateur était inaccessible.** Déclaré comme champ mais absent de toute carte, il tombait dans le panneau de secours « Autres » — que le script d'onglets masque en permanence. Il vivait donc hors de l'interface, et comme un champ absent du formulaire est réinitialisé à sa valeur par défaut à l'enregistrement suivant, il ne pouvait pas être modifié du tout. Il est maintenant sur l'onglet Ordinateur et mobile, et un test interdit désormais qu'un réglage quitte la page.
- **L'animation d'entrée était empruntée à l'interrupteur de repli.** Avec `reveal_mode` différent de `immediate` et le repli désactivé — qui est le défaut sur ordinateur — la barre surgissait d'un coup au lieu de glisser. L'entrée porte sa propre classe `hprnb-root--reveal` et sa propre transition, sur les deux profils.
- **`prefers-reduced-motion` ne couvrait pas la barre d'ordinateur** : `.hprnb-root--d-collapse .hprnb-bar` (0,2,0) l'emportait sur la remise à zéro `.hprnb-bar` (0,1,0), si bien qu'un visiteur ayant demandé moins d'animation en avait quand même. Toutes les variantes sont désormais nommées au même poids.

### Modifié — page de réglages réorganisée

- **Six onglets nommés d'après la question posée** au lieu de cinq nommés d'après l'implémentation : Contenu, **Où**, **Apparition et repli**, Ordinateur et mobile, Couleurs, Avancé. Les deux cartes géantes de l'onglet Affichage (25 et 33 réglages mêlant présentation, défilement, repli, types de pages et emplacement) sont découpées en cartes qui ne traitent qu'un sujet.
- **Les types de pages ont un seul endroit.** Le même choix était exprimable à trois endroits — la portée globale dans Avancé, plus deux listes « Types de pages » enfouies aux positions 19/25 et 30/33 des cartes géantes. La portée globale est maintenant la carte d'ouverture de l'onglet Où, les deux listes par appareil sont présentées dessous comme un affinage facultatif, et les neuf cases de la portée se grisent tant que la portée ne les utilise pas (elles restaient actives et pleinement cochées alors qu'elles ne servaient à rien).
- **L'interrupteur de repli est un interrupteur.** `desktop_hide_on_scroll` / `mobile_hide_on_scroll` s'appelaient « Replier au défilement » — un mode parmi trois, alors que c'est le maître : décoché, `collapse_mode` et `collapse_after` sont totalement inertes, « Toujours repliée » comprise. Ils s'appellent désormais **« Replier la barre »** et sont l'interrupteur d'en-tête de leur propre carte.
- Les deux libellés **identiques** « Quand elle se replie » portent leur appareil ; « Seuil » devient « Seuil d'apparition » et ne s'active plus que pour les modes qui l'emploient (nouvelle dépendance `clé:a|b`) ; « Identifiants exclus » devient *Ne jamais lister ces articles dans la barre* et *Ne jamais afficher la barre sur ces pages*, qui faisaient l'inverse l'un de l'autre à un mot près.
- **Cas d'usage expliqués** : chaque carte qui le mérite porte un dépliant « Cas d'usage courants » — barre dernière minute, une seule rubrique, articles seuls, partout sauf l'accueil, apparition immédiate ou intelligente, trois lignes de titre. Ce sont des explications, jamais des champs.
- Le code mort part avec : `Settings_Page::sections()` n'était jamais appelée.
- Aucun réglage n'est supprimé, aucun schéma n'est modifié : les 117 clés sont intactes et une installation existante retrouve exactement ses valeurs, simplement à des endroits qui portent enfin leur nom.

## [2.5.0] — 2026-09-16

### Corrigé

- **Aucun séparateur derrière un titre unique.** `separator_after_last` existe pour que la jonction dernier → premier du défilement continu ressemble aux autres ; avec un seul article il n'y a pas de jonction, et la puce restait. Deux garde-fous indépendants, tous deux purement CSS : `:only-child` (réévalué dans chaque `<ul>`, donc valable aussi pour le clone du marquee) et `[data-hprnb-count="1"]` sur la racine. Statique, marquee, rotation, manuel, LTR et RTL. À partir de deux articles, le comportement des séparateurs est strictement inchangé.
- **Le rafraîchissement hybride falsifiait le nombre d'articles** : la copie en `sessionStorage` enregistrait `1` au lieu du compte réel, si bien qu'une barre rejouée depuis cette copie aurait perdu tous ses séparateurs. Elle enregistre désormais le vrai nombre.
- `mobile_layout` entre dans la clé de cache : le design « Découvrir » décide de la présence de l'image dans le markup mis en cache.

### Modifié — carte mobile « Découvrir »

- **Bouton Fermer à l'intérieur de la carte**, dans son coin supérieur de fin (`inset-inline-end`, donc à gauche en RTL) : l'onglet extérieur au-dessus du bandeau disparaît. Fond très légèrement éclairci, rayon de 9 px, cible tactile de 44 × 44 px obtenue par un pseudo-élément qui ne déborde que vers l'extérieur, jamais sur le titre. Le clic ne suit jamais le lien de l'article.
- **Carte flottante** : 8 px de marge latérale et basse, coins de 12 px, ombre discrète, zone sûre iOS appliquée **une seule fois** (`inset-block-end` sur la carte, `padding-block-end: 0`). Le nouveau jeton `--hprnb-m-gap` ajoute cet écart à l'espace réservé et à `--hprnb-offset`.
- **Nettement plus compacte** : 99 px avec les valeurs par défaut (94 à 118 px sur toute la plage), contre 152 px en 2.4.1. Image de 96 × 75 px dans un cadre 5:4 (`mobile_card_thumb` : défaut 96, plage 72–120), réduite à 72 px sous 360 px de large. Titre de 18 px en graisse 700, interligne 1,24, **deux lignes au maximum**.
- La colonne des boutons est réservée par défaut dans la colonne de texte et rendue au titre quand il n'y a aucun bouton : perdre `:has()` coûte un peu de largeur, jamais la garantie.
- Nouvelle valeur `appear` pour `mobile_label_pulse`, **désormais la valeur par défaut** : trois battements à l'arrivée puis plus rien, au lieu d'une pulsation permanente. `always`, `collapsed` et `never` restent. Une installation existante conserve son réglage.
- Le liseré d'accent est composé avec l'ombre de la carte au lieu d'être écrasé par elle ; en paysage la carte passe à une ligne avec une image à la hauteur de cette ligne.

### Ajouté — apparition intelligente

- **Nouveau mode `smart`** dans « Moment d'apparition de la barre », à côté de `immediate`, `scroll`, `percent` et `end`, tous inchangés. C'est une **branche de `setupReveal()`**, pas un second moteur : un seul point de décision, un seul déclenchement par page.
- Il mesure **le corps de l'article**, pas la page : chaîne de sélecteurs (`.entry-content`, `.post-content`, `.article-content`, `.wp-block-post-content`, `[itemprop="articleBody"]`…) avec un réglage `smart_selector` facultatif, et un conteneur n'est retenu que s'il contient de la prose.
- Trois signaux, le premier venu l'emporte : **fin d'article** (sentinel de 1 px observé par `IntersectionObserver`), **remontée intentionnelle** (part lue + temps de lecture actif + pixels remontés cumulés, remis à zéro dès qu'on repart vers le bas, insensible au rebond iOS), **lecteur engagé** (part lue + temps de lecture actif, désactivé sur un article de moins d'une fois et demie la hauteur d'écran, où seule la fin compte).
- Le temps de lecture est **actif** : suspendu quand l'onglet n'est pas visible et après une minute sans la moindre activité.
- Dix réglages numériques (cinq par profil) avec les valeurs par défaut demandées, plus le sélecteur. **Aucune installation existante ne passe en `smart` toute seule.**
- Performance : un seul écouteur de défilement passif fondu dans un `requestAnimationFrame`, la géométrie mesurée à l'initialisation, au redimensionnement et par un `ResizeObserver`, jamais dans le gestionnaire de défilement ; un battement d'une seconde, arrêté dès le déclenchement.

### Ajouté — mesure

- Trois évènements non bloquants poussés sur `window.dataLayer` **et** émis sur `document` (`hprnb:hprnb_impression`…) : `hprnb_impression`, `hprnb_click`, `hprnb_close`, avec `trigger_reason` (`article_end`, `scroll_up_intent`, `engaged_reader`, `legacy_immediate`, `legacy_scroll`, `legacy_percent`, `legacy_end`), `device`, `current_article_id`, `recommended_article_id`, `items`, et pour le mode intelligent `article_progress`, `active_reading_time` et `article_found`. Aucun appel réseau, aucune dépendance : sans `dataLayer` la barre s'affiche exactement pareil.
- La racine porte `data-hprnb-post` et chaque article `data-hprnb-id`.

### Modifié

- Budgets minifiés relevés : CSS 36 Ko, script de la barre 20 Ko.

## [2.4.2] — 2026-09-15

### Modifié — design « Découvrir »

- **Carte plus courte** : la pastille n'a plus de ligne à elle au-dessus de tout ; elle prend la **première ligne de la colonne de texte**, à côté de l'image, et **le titre commence à la deuxième ligne**. La hauteur suit la seule image : `2 × 14 + max(hauteur image, ligne + lignes × ligne)` — **116 px au lieu de 152** avec les réglages par défaut (82 px pour une image de 80, 166 px pour une de 220).
- Le nombre de lignes du titre se déduit de l'image (`⌊(hauteur image − ligne) / ligne⌋`, au moins 1) pour que le texte finisse au niveau de la photo : c'est la largeur d'image qui règle la taille du bloc, plus `mobile_lines`.
- **Bandeau replié identique à celui du premier bandeau** : l'image et sa colonne s'effacent, la pastille redevient le **point rouge clignotant** collé à la gouttière et la **première ligne du titre** s'affiche à côté. Le bouton Fermer ne s'affiche pas dans le bandeau replié. Hauteur du bandeau : 43 px.
- Un article **sans image mise en avant** récupère la colonne pour son titre (`:has()`), au lieu de laisser un vide.
- La largeur réservée au bouton Pause sur la première ligne n'est prise que lorsqu'un bouton Pause y figure réellement.

### Corrigé

- **L'image de la carte se décalait à chaque rotation** : l'animation d'entrée posait un `transform` sur l'article, qui devenait alors le bloc conteneur de l'image positionnée — celle-ci sautait dans la colonne de texte pendant l'animation. L'article apparaît désormais en fondu (`hprnb-appear`) et c'est le titre qui monte ; le mouvement réduit coupe aussi cette animation de titre.
- L'image était rognée par la fenêtre de texte lorsque celle-ci était positionnée : la fenêtre ne l'est plus, l'image se raccroche à la carte.

### Modifié

- Budget CSS relevé à 36 Ko.

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
