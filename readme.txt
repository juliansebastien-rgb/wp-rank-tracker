=== WP Rank Tracker ===
Contributors: lelabodazertaf
Tags: seo, rank tracker, search console, google
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.6
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
