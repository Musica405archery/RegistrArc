<p align="center">
  <img src="docs/images/banner.svg" alt="RegistrArc, le module de greffe pour I@nseo" width="100%">
</p>

<p align="center">
  <img src="https://img.shields.io/badge/module-I%40nseo-14336b" alt="Module I@nseo">
  <img src="https://img.shields.io/badge/langue-fran%C3%A7ais-14336b" alt="En français">
  <img src="https://img.shields.io/badge/tir%20%C3%A0%20l'arc-greffe-e3242b" alt="Tir à l'arc, greffe">
</p>

<p align="center">
  <strong>Qui a payé ? Combien reste-t-il à encaisser ? Où en est la remise de chèques ?</strong><br>
  RegistrArc répond à ces questions pendant toute la compétition, à l'ordinateur comme sur téléphone.
</p>

<p align="center">
  <a href="#installer-le-module">Installer</a> ·
  <a href="#régler-les-tarifs-et-les-paiements">Régler les tarifs</a> ·
  <a href="#pendant-le-concours">Encaisser</a> ·
  <a href="#faire-une-facture">Factures</a> ·
  <a href="#préparer-la-remise-de-chèques">Chèques</a> ·
  <a href="#questions-fréquentes">Questions</a>
</p>

<br>

## Aperçu

<p align="center">
  <img src="docs/images/liste-engagements.png" alt="Liste des engagements" width="100%">
</p>

<table>
  <tr>
    <td align="center" valign="top" width="36%">
      <img src="docs/images/kiosk-encaissement.png" alt="Encaissement sur téléphone" width="100%"><br>
      <strong>Encaisser sur téléphone</strong>
    </td>
    <td align="center" valign="top" width="64%">
      <img src="docs/images/facture.png" alt="Facture" width="100%"><br>
      <strong>Imprimer une facture</strong>
      <br><br>
      <img src="docs/images/remise-cheques.png" alt="Remise de chèques" width="100%"><br>
      <strong>Déposer les chèques</strong>
    </td>
  </tr>
</table>

## Ce que fait RegistrArc

<table>
  <tr>
    <td width="50%" valign="top">
      <strong>La liste des inscrits</strong><br>
      Tous les archers du concours, avec ce que chacun doit payer pour chacun de ses départs.
    </td>
    <td width="50%" valign="top">
      <strong>L'encaissement</strong><br>
      À l'ordinateur ou sur téléphone, en espèces, chèque, virement… en un geste.
    </td>
  </tr>
  <tr>
    <td valign="top">
      <strong>Les chèques groupés</strong><br>
      Un seul chèque pour plusieurs archers : une famille, un club.
    </td>
    <td valign="top">
      <strong>Les tarifs du club</strong><br>
      Jeunes ou adultes, club organisateur ou non, départs supplémentaires, tarifs spéciaux.
    </td>
  </tr>
  <tr>
    <td valign="top">
      <strong>Les factures</strong><br>
      Pour un archer ou pour plusieurs, prêtes à imprimer ou à envoyer en PDF.
    </td>
    <td valign="top">
      <strong>La remise de chèques</strong><br>
      Le bordereau à déposer à la banque, avec le total.
    </td>
  </tr>
</table>

RegistrArc ne modifie jamais les inscriptions dans I@nseo : il les lit et garde ses propres informations à part.

## Le greffe en 4 étapes

| | Quand | Quoi faire |
|:-:|---|---|
| **1** | Une fois pour toutes | [Installer le module](#installer-le-module) |
| **2** | Avant le concours | [Régler les tarifs et les modes de paiement](#régler-les-tarifs-et-les-paiements) |
| **3** | Pendant le concours | Encaisser [à l'ordinateur](#encaisser-à-lordinateur) ou [sur un téléphone](#encaisser-sur-un-téléphone) |
| **4** | Après le concours | [Faire les factures](#faire-une-facture) et [préparer la remise de chèques](#préparer-la-remise-de-chèques) |

> [!TIP]
> Dans la suite, cliquez sur un titre pour ouvrir la partie correspondante.

---

## Installer le module

<details>
<summary><strong>Installer RegistrArc et donner les bons accès</strong></summary>

<br>

1. Copiez le dossier **RegistrArc** dans le dossier **Modules/Custom** de votre I@nseo.
2. Ouvrez un concours dans I@nseo : RegistrArc apparaît dans le menu **Participants → Greffe**.
3. Si un message indique que le module ne peut pas enregistrer ses informations, voir les [questions fréquentes](#questions-fréquentes).

**Qui peut faire quoi ?**

| Action | Qui peut la faire |
|---|---|
| Voir le menu Greffe et consulter les pages | les personnes qui ont accès aux participants dans I@nseo |
| Encaisser, modifier les tarifs | les personnes qui peuvent modifier les participants |
| Installer une mise à jour du module | l'administrateur d'I@nseo |

Une personne qui a seulement le droit de consulter voit toutes les pages, mais les boutons pour encaisser ou modifier n'apparaissent pas.

</details>

## Régler les tarifs et les paiements

À faire avant chaque concours : dans la liste des engagements, cliquez sur **⚙ Paramétrer tarifs & paiements**. Les réglages sont propres à chaque concours.

> [!IMPORTANT]
> Pensez à cliquer sur **Enregistrer les paramètres** en bas de la page.

<details>
<summary><strong>Jeunes et adultes</strong></summary>

<br>

<img src="docs/images/parametres-classes-age.png" alt="Classes d'âge" width="100%">

Les catégories du concours sont rangées en deux colonnes : **Jeunes** à gauche, **Adultes** à droite. Pour en changer une de colonne, faites-la glisser ou cliquez sur sa flèche.

Au départ, toutes les catégories en dessous de U21 (U11, U13, U15, U18) sont rangées dans les Jeunes ; U21 et les seniors dans les Adultes.

</details>

<details>
<summary><strong>Prix des inscriptions</strong></summary>

<br>

<img src="docs/images/parametres-tarifs-regles.png" alt="Tarifs et règles" width="100%">

Pour les archers du club organisateur et pour ceux des autres clubs, jeunes et adultes, vous indiquez :

- le prix du **1er départ** ;
- le **supplément** à payer pour **chaque** départ en plus.

Exemple avec les prix proposés au départ :

| Archer | 1 départ | 2 départs | 3 départs |
|---|:-:|:-:|:-:|
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
|---|---|:-:|
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

<img src="docs/images/parametres-modes-paiement.png" alt="Modes de paiement" width="100%">

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

C'est l'écran principal (voir l'aperçu en haut de cette page). Chaque ligne est un départ d'un archer : licence, nom, club, catégorie, départ, cible, montant et état du paiement.

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

<table>
  <tr>
    <td align="center"><img src="docs/images/kiosk.png" alt="Mode Kiosk" width="260"></td>
    <td align="center"><img src="docs/images/kiosk-encaissement.png" alt="Encaissement" width="260"></td>
  </tr>
  <tr>
    <td align="center">L'écran</td>
    <td align="center">Pendant l'encaissement</td>
  </tr>
</table>

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

<img src="docs/images/facture.png" alt="Facture" width="100%">

- **Pour un archer** : bouton **Facture** sur sa ligne.
- **Pour plusieurs** (une famille, un club) : cochez leurs lignes, puis **Facture groupée**.

La facture s'ouvre dans un nouvel onglet. Elle indique le concours, les archers regroupés par club, le détail de chaque départ et le total. **Imprimer / Enregistrer en PDF** permet de l'imprimer, ou de l'enregistrer en PDF pour l'envoyer ensuite par e-mail.

Le numéro de facture n'est pas enregistré : si vous refaites la facture, elle aura un nouveau numéro.

</details>

### Préparer la remise de chèques

<details>
<summary><strong>Le bordereau pour la banque</strong></summary>

<br>

<img src="docs/images/remise-cheques.png" alt="Remise de chèques" width="100%">

Le bouton **Remise de chèque** prépare la feuille à déposer avec les chèques :

- **sans rien cocher**, elle reprend tous les chèques du concours ;
- **en cochant des lignes**, elle ne reprend que les chèques de ces archers.

Elle indique le nombre de chèques, le nombre d'inscriptions et le total. Pour chaque chèque, il reste à écrire à la main le numéro du chèque et la banque. En bas : « Préparé par » et « Déposé le ».

Les chèques groupés se tiennent à jour tout seuls : si vous annulez le paiement d'un archer, il quitte son chèque groupé.

</details>

---

## Mettre à jour le module

Quand une nouvelle version de RegistrArc existe, un bouton vert **⬆ Mise à jour** apparaît en haut de la liste. Seul l'administrateur d'I@nseo le voit.

<details>
<summary><strong>Comment se passe la mise à jour</strong></summary>

<br>

<img src="docs/images/bouton-mise-a-jour.png" alt="Bouton de mise à jour" width="100%">

Le bouton ouvre une page qui montre la version installée, la nouvelle version et ses nouveautés. Cliquez sur **Installer la version…**.

<img src="docs/images/mise-a-jour.png" alt="Page de mise à jour" width="100%">

- Vos paiements et vos réglages ne sont pas touchés.
- Si l'installation échoue en route, rien n'est changé.
- Sans internet, RegistrArc fonctionne normalement : le bouton n'apparaît simplement pas.

</details>

## Vos informations sont-elles en sécurité ?

- **Chaque concours a ses propres paiements et réglages**, sans mélange.
- **Plusieurs personnes peuvent encaisser en même temps** (l'ordinateur du greffe et des téléphones) sans s'effacer mutuellement.
- **Pour faire une sauvegarde**, copiez le dossier **data** qui se trouve dans le dossier RegistrArc.

> [!WARNING]
> **Attention aux onglets.** Dans un même navigateur, ouvrir un autre concours dans I@nseo change le concours de tous les onglets. Si vous encaissez depuis un onglet resté sur l'ancien concours, RegistrArc refuse et vous demande de recharger la page : rien n'est enregistré au mauvais endroit.

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

<p align="center">
  <sub>
    Informations techniques (fichiers, stockage, publication d'une version) : <a href="docs/MAINTENANCE.md">docs/MAINTENANCE.md</a><br>
    Module développé pour la gestion des compétitions de tir à l'arc avec I@nseo · Made with &lt;3 avec Open WebUI
  </sub>
</p>
