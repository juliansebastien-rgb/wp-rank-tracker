=== WP Rank Tracker ===
Contributors: lelabodazertaf
Tags: seo, rank tracker, search console, google
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.45
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audit local des pages WordPress, puis connexion Google Search Console pour voir pages, requetes, clics, impressions et position moyenne.

== Description ==

WP Rank Tracker pose maintenant un parcours en 2 temps :

* bilan local des pages et des mots-cles probables
* recommandations d amelioration par page
* connexion OAuth Google Search Console
* import des requetes et pages reelles vues par Google
* comparaison entre lecture locale et lecture Google
* import SERP externe via DataForSEO pour Google et Bing
* preparation d un comparatif concurrentiel par mots-cles et domaines
* mise a jour directe dans WordPress via les releases GitHub
* mode service central pour gerer OAuth Google et les imports cote serveur

Cette premiere version prepare ensuite les phases Bing Webmaster et SERP API tierce.

== Installation ==

1. Uploadez le dossier `wp-rank-tracker` dans `/wp-content/plugins/`
2. Activez le plugin dans WordPress
3. Ouvrez `WP Rank Tracker` dans l'administration

== Central service ==

Le plugin peut fonctionner en mode service central :

* OAuth Google gere par une API distante
* tokens Google stockes cote serveur
* imports Search Console et SERP lances cote backend

Dans ce mode, l'utilisateur renseigne surtout :

* propriete Search Console
* mots-cles surveilles
* concurrents

Le site peut s'enregistrer automatiquement sur le service central sans demander de secret serveur a l'utilisateur final.

== Changelog ==

= 0.1.33 =
* Revient maintenant sur le bon sous-menu admin apres Enregistrer et analyser maintenant grace a un retour explicite de page
* Rend le message DataForSEO plus clair quand le probleme vient des credits ou de la facturation

= 0.1.32 =
* Affiche maintenant la derniere erreur DataForSEO directement dans le bloc SERP externe
* Permet de distinguer un vrai souci de credits ou de facturation d un simple decalage de snapshot

= 0.1.31 =
* Conserve maintenant le bon sous-menu admin apres Enregistrer, import Google Search Console et import DataForSEO
* Evite le retour systematique vers le Tableau de bord apres une action lancee depuis DataForSEO

= 0.1.30 =
* Elargit l analyse SERP DataForSEO au top 100 par defaut
* Adapte automatiquement le marche France/French pour les sites WordPress en francais quand aucun reglage explicite n est defini
* Clarifie les messages quand un site n apparait pas dans l analyse neutre DataForSEO

= 0.1.29 =
* Le sous-menu DataForSEO lit maintenant le report complet du serveur central quand il est configure
* Evite les incoherences entre snapshot backend a jour et cache local WordPress obsolete
* Affiche la meme source de verite que le service central
= 0.1.28 =
* Renomme la colonne "Signal Google actuel" en "Ce que Google voit deja"
* Clarifie la lecture du comparatif concurrentiel prepare
= 0.1.27 =
* Ajout de 4 blocs Google Search Console actionnables
* Pages proches du top 10
* Pages avec CTR faible
* Pages en baisse
* Requetes emergentes
= 0.1.26 =
* Les imports DataForSEO partent maintenant mot-cle par mot-cle
* Evite les pertes de resultats quand plusieurs mots-cles sont envoyes en lot
* Le tableau ne confond plus un mot-cle demande sans resultat avec un mot-cle absent du dernier import
= 0.1.25 =
* La phase SERP analyse maintenant au minimum le top 20 au lieu du top 10
* Un mot-cle absent du top 20 est affiche comme "Non detecte dans le top 20"
* Evite de masquer des positions plus basses comme #15 sur DataForSEO
= 0.1.24 =
* Ajout d un traceur "WordPress a tente d envoyer" dans DataForSEO
* Affiche la liste exacte des mots-cles que le plugin a essaye d envoyer au service central
* Facilite le diagnostic entre sauvegarde locale, envoi WordPress et reception serveur
= 0.1.23 =
* Rend la detection des nouvelles releases GitHub plus reactive dans WordPress
* Reduit le cache de mise a jour de 1 heure a 5 minutes
* Force un rafraichissement des transients de mise a jour sur les ecrans plugin et WP Rank Tracker
= 0.1.22 =
* Ajout d un bloc de diagnostic du serveur central dans DataForSEO
* Affiche la date, le nombre de lignes et les mots-cles reels du dernier snapshot recu cote serveur
* Permet de distinguer un probleme d import WordPress d un probleme DataForSEO
= 0.1.21 =
* Le tableau SERP distingue maintenant un vrai "Non detecte" d un mot-cle absent du dernier import
* Affiche "Pas dans le dernier import" quand le snapshot affiche est obsolete pour un mot-cle configure
* Evite les faux negatifs DataForSEO dans l interface
= 0.1.20 =
* Clarifie le parcours DataForSEO avec un bouton "Enregistrer et analyser maintenant"
* Affiche les mots-cles exacts utilises lors du dernier import SERP
* Alerte si les mots-cles affiches ne correspondent pas encore au dernier import relance
* Evite la confusion entre sauvegarde de la configuration et simple rafraichissement
= 0.1.19 =
* Ajout d un bloc A faire maintenant dans le Tableau de bord
* Ajout d un Resume du jour avec les hausses, baisses et opportunites fortes
* Ajout d une vue Pages en alerte avec actions directes
* Ajout d un bloc Opportunites rapides avec estimation d impact

= 0.1.18 =
* Separation du menu en 4 entrees : Tableau de bord, Local, Google Search Console et DataForSEO
* Le Tableau de bord devient la page d arrivee
* Le detail de l audit local passe dans son propre sous-menu Local

= 0.1.17 =
* Renommage du premier sous-menu en Tableau de bord pour regrouper le dashboard et la lecture locale

= 0.1.16 =
* Ajout d un tableau de bord actionnable dans le sous-menu Local
* Ajout d un parcours conseille pour guider la mise en place du suivi
* Ajout d alertes du moment pour remonter les blocages et baisses visibles
* Ajout de mini graphiques pour les clics Google et les positions suivies
* Ajout d actions directes pour modifier les pages dans WordPress, Elementor ou Divi

= 0.1.15 =
* Ajout d un bloc Opportunites SEO prioritaires
* Ajout d actions concretes basees sur les signaux locaux, Google et concurrents
* Priorisation des actions a fort impact et langage plus clair pour l utilisateur

= 0.1.14 =
* Ajout d un suivi Google Search Console avec historique local
* Ajout d un podium Google des requetes actuelles
* Ajout d une tendance par page avec evolution de position et de clics
* Ajout d un rafraichissement Google quotidien automatique via WordPress

= 0.1.13 =
* Ajout d un comparatif SERP enrichi avec podium actuel
* Ajout de l affichage de ton site et de chaque concurrent suivi
* Ajout de la tendance avec fleches et places gagnees ou perdues
* Ajout d un historique local des snapshots SERP
* Ajout d un rafraichissement SERP quotidien automatique via WordPress

= 0.1.2 =
* Creation du plugin WP Rank Tracker
* Ajout d un audit local des pages
* Ajout de recommandations SEO de base par page
* Ajout de la connexion OAuth Google Search Console
* Ajout d'un import Search Analytics page + query
* Ajout d'un premier tableau de bord WordPress
* Ajout d une integration DataForSEO pour la phase 3
* Ajout d une base de comparatif concurrentiel preparatoire
* Ajout du mecanisme de mise a jour GitHub pour WordPress
* Ajout du mode service central via l API FastAPI
* Simplification de l interface pour un usage grand public
* Correction du flux Connecter Google pour forcer le service central
