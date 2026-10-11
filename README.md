# RegistrArc

**Le module de greffe pour I@nseo.** Il suit qui a payé son inscription, calcule ce que chaque archer doit, imprime les factures et prépare la remise des chèques à la banque. On peut aussi encaisser depuis un téléphone.

![Liste des engagements](docs/images/liste-engagements.png)

**En bref**

- Tous les archers inscrits au concours, avec le montant à payer pour chacun de leurs départs.
- Encaissement à l'ordinateur ou sur téléphone, en espèces, chèque, virement…
- Un seul chèque pour plusieurs archers (une famille, un club) : c'est un « chèque groupé ».
- Factures prêtes à imprimer et bordereau de remise de chèques.
- RegistrArc ne modifie jamais les inscriptions dans I@nseo : il les lit et garde ses propres informations à part.

## Le greffe en 4 étapes

1. **Une fois pour toutes** : [installer le module](#installer-le-module).
2. **Avant le concours** : [régler les tarifs et les modes de paiement](#régler-les-tarifs-et-les-paiements).
3. **Pendant le concours** : encaisser [à l'ordinateur](#encaisser-à-lordinateur) ou [sur un téléphone](#encaisser-sur-un-téléphone).
4. **Après** : [faire les factures](#faire-une-facture) et [préparer la remise de chèques](#préparer-la-remise-de-chèques).

Cliquez sur un titre ci-dessous pour ouvrir la partie correspondante.

## Installer le module

<details>
<summary><strong>Installer RegistrArc et donner les bons accès</strong></summary>

<br>

1. Copiez le dossier **RegistrArc** dans le dossier **Modules/Custom** de votre I@nseo.
2. Ouvrez un concours dans I@nseo : RegistrArc apparaît dans le menu **Participants → Greffe**.
3. Si un message indique que le module ne peut pas enregistrer ses informations, voir la [question correspondante](#questions-fréquentes).

**Qui peut faire quoi ?**

| Action | Qui peut la faire |
|---|---|
| Voir le menu Greffe et consulter les pages | les personnes qui ont accès aux participants dans I@nseo |
| Encaisser, modifier les tarifs | les personnes qui peuvent modifier les participants |
| Installer une mise à jour du module | l'administrateur d'I@nseo |

Une personne qui a seulement le droit de consulter voit toutes les pages, mais les boutons pour encaisser ou modifier n'apparaissent pas.

</details>

## Régler les tarifs et les paiements

À faire avant chaque concours : dans la liste des engagements, cliquez sur **⚙ Paramétrer tarifs & paiements**. Les réglages sont propres à chaque concours. N'oubliez pas **Enregistrer les paramètres** en bas de la page.

<details>
<summary><strong>Jeunes et adultes</strong></summary>

<br>

![Classes d'âge](docs/images/parametres-classes-age.png)

Les catégories du concours sont rangées en deux colonnes : **Jeunes** à gauche, **Adultes** à droite. Pour en changer une de colonne, faites-la glisser ou cliquez sur sa flèche.

Au départ, toutes les catégories en dessous de U21 (U11, U13, U15, U18) sont rangées dans les Jeunes ; U21 et les seniors dans les Adultes.

</details>

<details>
<summary><strong>Prix des inscriptions</strong></summary>

<br>

![Tarifs et règles](docs/images/parametres-tarifs-regles.png)

Pour les archers du club organisateur et pour ceux des autres clubs, jeunes et adultes, vous indiquez :

- le prix du **1er départ** ;
- le **supplément** à payer pour **chaque** départ en plus.

Exemple avec les prix proposés au départ :

| Archer | 1 départ | 2 départs | 3 départs |
|---|---|---|---|
| Jeune d'un autre club (8 € puis 6 €) | 8 € | 14 € | 20 € |
| Adulte d'un autre club (10 € puis 8 €) | 10 € | 18 € | 26 € |
| Adulte du club organisateur (5 € puis 5 €) | 5 € | 10 € | 15 € |

Le club organisateur est celui indiqué dans les informations du concours, dans I@nseo. Les départs d'un même archer sont reconnus grâce à son numéro de licence.

</details>

<details>
<summary><strong>Cas particuliers : tarif découverte, finales, gratuité…</strong></summary>

<br>

Les **règles** donnent un prix spécial à certains archers. Pour chaque règle, vous indiquez :

- un **nom**, qui s'affiche dans la liste et sur la facture (par exemple « Tarif découverte U11 ») ;
- **ce que la règle regarde** et la **valeur** à reconnaître ;
- le **prix** à appliquer ;
- si elle est **active** (décochez pour la mettre de côté sans la supprimer).

| La règle regarde… | Elle s'applique quand… | Exemple |
|---|---|---|
| Catégorie | la catégorie de l'archer contient la valeur | `U11` |
| Région | le numéro du club commence par ces 2 chiffres | `10` |
| Département | le numéro du club contient ces chiffres en 3e et 4e position | `69` |
| Qualification individuelle ou par équipe | l'archer y participe (`OUI`) ou non (`NON`) | `OUI` |
| Finale individuelle, par équipe, double mixte | pareil ; proposé seulement si le concours a ces finales | `OUI` |

À savoir :

- le prix d'une règle **remplace** le prix normal ;
- si plusieurs règles concernent le même archer, leurs prix **s'ajoutent** ;
- une règle à **0 €** rend l'inscription gratuite.

</details>

<details>
<summary><strong>Modes de paiement et inscriptions gratuites</strong></summary>

<br>

![Modes de paiement](docs/images/parametres-modes-paiement.png)

Espèces, Chèque, Virement et Gratuit sont proposés au départ. Vous pouvez en ajouter (par exemple Carte bancaire) ou les renommer. Seuls les paiements par **chèque** apparaissent sur la remise de chèques.

**Inscriptions gratuites** : quand le prix d'une inscription est de 0 €, l'archer n'a rien à payer. RegistrArc l'affiche « Gratuit » et ne le compte pas parmi les archers à encaisser : il n'y a rien à valider.

</details>

<details>
<summary><strong>Reprendre les réglages d'un autre concours</strong></summary>

<br>

En haut de la page des réglages :

- **Exporter** enregistre tous vos réglages dans un fichier ;
- **Importer** les recharge, par exemple pour reprendre les tarifs de l'an dernier ou les mettre sur un autre ordinateur.

Avant de remplacer vos réglages, RegistrArc vous prévient si le fichier vient d'un autre concours ou s'il est plus ancien que vos réglages actuels.

</details>

## Pendant le concours

### Encaisser à l'ordinateur

<details>
<summary><strong>La liste des engagements</strong></summary>

<br>

C'est l'écran principal (image en haut de cette page). Chaque ligne est un départ d'un archer : licence, nom, club, catégorie, départ, cible, montant et état du paiement.

- **Chercher un archer** : par club, catégorie, départ, état du paiement (payés, non payés, gratuits) ou en tapant un nom, une licence, un club. **Réinitialiser** remet tout à zéro.
- **Trier** : cliquez sur le titre d'une colonne ; cliquez à nouveau pour inverser l'ordre.
- **Repères** : « Archer non affecté » signale un archer sans cible ; une étiquette violette indique un prix spécial.

</details>

<details>
<summary><strong>Encaisser un ou plusieurs archers</strong></summary>

<br>

- **Un archer** : sur sa ligne, choisissez le mode de paiement, puis **Valider**. En cas d'erreur, **Annuler** le remet en « Non payé ».
- **Plusieurs archers** : cochez leurs lignes, choisissez le mode de paiement en haut de la liste, puis **Valider paiement**.
- **Un chèque pour plusieurs archers** : si vous validez au moins 2 archers par chèque, RegistrArc propose d'en faire un **chèque groupé**. Ils apparaîtront ensemble sur la remise de chèques.

</details>

<details>
<summary><strong>Les autres boutons de la liste</strong></summary>

<br>

- **Facture** et **Facture groupée** : voir [Faire une facture](#faire-une-facture).
- **Remise de chèque** : voir [Préparer la remise de chèques](#préparer-la-remise-de-chèques).
- **Liste Greffe** : imprime la liste telle qu'elle est affichée à l'écran.
- **Exporter / Importer inscriptions** : recopie les paiements d'un ordinateur RegistrArc à un autre, par exemple de l'ordinateur des pré-inscriptions vers celui du greffe. Seuls les paiements sont recopiés : les inscriptions dans I@nseo ne changent pas.

</details>

### Encaisser sur un téléphone

<details>
<summary><strong>Le mode Kiosk</strong></summary>

<br>

Le bouton **Mode Kiosk** ouvre un écran fait pour encaisser debout, avec un téléphone ou une tablette connecté au même réseau que l'ordinateur I@nseo.

| L'écran | Pendant l'encaissement |
|---|---|
| ![Mode Kiosk](docs/images/kiosk.png) | ![Encaissement](docs/images/kiosk-encaissement.png) |

1. **Trouvez l'archer** en tapant son nom, sa licence ou son club, ou en choisissant le départ. Seuls les archers qui doivent encore payer sont affichés ; cochez **Afficher payés et gratuits** pour voir tout le monde.
2. **Touchez sa ligne**, ou plusieurs lignes. **Tout sélectionner** coche toutes les lignes affichées. Le total apparaît en bas.
3. **Choisissez le mode de paiement**, cochez **Reçu demandé** si l'archer veut un reçu, puis **Encaisser**.

En haut de l'écran : le nombre d'archers à encaisser, la somme qui reste à encaisser, le nombre de payés et le nombre de reçus demandés.

Le « reçu demandé » se coche uniquement dans le mode Kiosk ; la liste des engagements l'affiche ensuite.

</details>

## Après le concours

### Faire une facture

<details>
<summary><strong>Facture d'un archer ou de plusieurs</strong></summary>

<br>

![Facture](docs/images/facture.png)

- **Pour un archer** : bouton **Facture** sur sa ligne.
- **Pour plusieurs** (une famille, un club) : cochez leurs lignes, puis **Facture groupée**.

La facture s'ouvre dans un nouvel onglet. Elle indique le concours, les archers regroupés par club, le détail de chaque départ et le total. **Imprimer / Enregistrer en PDF** permet de l'imprimer, ou de l'enregistrer en PDF pour l'envoyer ensuite par e-mail.

Le numéro de facture n'est pas enregistré : si vous refaites la facture, elle aura un nouveau numéro.

</details>

### Préparer la remise de chèques

<details>
<summary><strong>Le bordereau pour la banque</strong></summary>

<br>

![Remise de chèques](docs/images/remise-cheques.png)

Le bouton **Remise de chèque** prépare la feuille à déposer avec les chèques :

- **sans rien cocher**, elle reprend tous les chèques du concours ;
- **en cochant des lignes**, elle ne reprend que les chèques de ces archers.

Elle indique le nombre de chèques, le nombre d'inscriptions et le total. Pour chaque chèque, il reste à écrire à la main le numéro du chèque et la banque. En bas : « Préparé par » et « Déposé le ».

Les chèques groupés se tiennent à jour tout seuls : si vous annulez le paiement d'un archer, il quitte son chèque groupé.

</details>

## Mettre à jour le module

<details>
<summary><strong>Le bouton vert « Mise à jour »</strong></summary>

<br>

Quand une nouvelle version de RegistrArc existe, un bouton vert **⬆ Mise à jour** apparaît en haut de la liste. Seul l'administrateur d'I@nseo le voit.

![Bouton de mise à jour](docs/images/bouton-mise-a-jour.png)

Il ouvre une page qui montre la version installée, la nouvelle version et ses nouveautés. Cliquez sur **Installer la version…**.

![Page de mise à jour](docs/images/mise-a-jour.png)

- Vos paiements et vos réglages ne sont pas touchés.
- Si l'installation échoue en route, rien n'est changé.
- Sans internet, RegistrArc fonctionne normalement : le bouton n'apparaît simplement pas.

</details>

## Vos informations sont-elles en sécurité ?

<details>
<summary><strong>Plusieurs concours, plusieurs postes, sauvegarde</strong></summary>

<br>

- **Chaque concours a ses propres paiements et réglages**, sans mélange.
- **Plusieurs personnes peuvent encaisser en même temps** (l'ordinateur du greffe et des téléphones) sans s'effacer mutuellement.
- **Attention aux onglets** : dans un même navigateur, ouvrir un autre concours dans I@nseo change le concours de tous les onglets. Si vous encaissez depuis un onglet resté sur l'ancien concours, RegistrArc refuse et vous demande de recharger la page. Rien n'est enregistré au mauvais endroit.
- **Pour faire une sauvegarde**, copiez le dossier **data** qui se trouve dans le dossier RegistrArc.

</details>

## Questions fréquentes

<details>
<summary>Un message dit que le module ne peut pas enregistrer ses informations</summary>

<br>

L'ordinateur n'autorise pas RegistrArc à écrire dans son dossier. Demandez à la personne qui gère l'ordinateur I@nseo d'autoriser l'écriture dans le dossier **RegistrArc** (et son dossier **data**). Le message indique l'emplacement exact.

</details>

<details>
<summary>Un archer n'a pas le bon prix</summary>

<br>

Dans les réglages, vérifiez :

1. que sa catégorie est dans la bonne colonne (Jeunes ou Adultes) ;
2. que son club est bien le club organisateur, s'il doit avoir ce tarif ;
3. qu'aucune règle active ne le concerne par erreur.

</details>

<details>
<summary>« Formulaire expiré ou invalide »</summary>

<br>

La page était ouverte depuis longtemps. Rechargez-la et recommencez : rien n'a été enregistré.

</details>

<details>
<summary>« Le tournoi ouvert a changé depuis l'affichage de cette page »</summary>

<br>

Un autre concours a été ouvert dans I@nseo entre-temps. Rechargez la page : elle affichera le concours actuellement ouvert.

</details>

<details>
<summary>Le bouton « Mise à jour » n'apparaît jamais</summary>

<br>

Il n'apparaît que pour l'administrateur d'I@nseo, quand l'ordinateur a accès à internet et qu'une nouvelle version existe.

</details>

---

Informations techniques (fichiers, stockage, publication d'une nouvelle version) : [docs/MAINTENANCE.md](docs/MAINTENANCE.md).

*Module développé pour la gestion des compétitions de tir à l'arc avec I@nseo.*

Made with <3 avec Open WebUI
