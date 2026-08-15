# Panneaux solaires APsystems (OpenAPI)

## Installation

1. Allez dans **Configuration / Ajouter ou supprimer un périphérique / Store eedomus**, recherchez "Panneaux solaires APsystems", cliquez dessus puis sur **Créer**.
2. Renseignez les paramètres demandés dans le formulaire (`APP_ID`, `APP_SECRET`, `SID`, `POLLING`, `POLLING_POWER`) : voir la section "Paramètres du plugin" ci-dessous pour le détail de chacun. Pour activer aussi la puissance instantanée, saisissez `SID|ECU_ID` dans le champ `SID` (voir "Avant de commencer" ci-dessous).
3. Validez : les 5 périphériques (production aujourd'hui/mois/année/vie + puissance instantanée) sont créés automatiquement, ainsi que les scripts `aps_solar.php` et `aps_discover.php` associés. "today" (avec ses canaux mois/année/vie) et "power" sont deux périphériques maîtres indépendants, chacun avec son propre intervalle d'interrogation.

Ce plugin récupère la production de vos panneaux solaires APsystems (via l'EMA / OpenAPI) et crée les périphériques suivants sur votre box eedomus :

- **Production aujourd'hui** (kWh) - périphérique principal
- **Production ce mois** (kWh)
- **Production cette année** (kWh)
- **Production totale / vie** (kWh)
- **Puissance instantanée** (W) - uniquement si vous renseignez l'ECU_ID (voir ci-dessous)

## Avant de commencer

1. **Récupérer vos identifiants OpenAPI** : obtenez directement votre **App Id** et **App Secret**, sans démarche préalable - soit dans l'application EMA Android (**Paramètres -> Service OpenAPI -> Autorisation du développeur**), soit sur le web via https://www.apsystemsema.com/apsystems/web/setting/personalSetting/openAPIService (ex. App Id `11aa22bb33cc44dd55ee66ff77889900`). (Si aucune de ces options n'est disponible sur votre compte, envoyez un email au support APsystems en indiquant qui vous êtes, pourquoi vous voulez un compte OpenAPI, et ce que vous comptez faire des données.)

   ![Identifiants OpenAPI dans l'interface web EMA](https://raw.githubusercontent.com/henryju/apsystems-eedomus/main/img/web_openapi.png)

2. **Trouver votre SID** : c'est l'identifiant de votre système, visible dans votre compte EMA (https://www.apsystemsema.com/ema/index.action), par exemple `D11D123456789012`.

   ![SID dans l'interface web EMA](https://raw.githubusercontent.com/henryju/apsystems-eedomus/main/img/web_sid.png)

3. **(Facultatif) Trouver votre ECU ID** : nécessaire uniquement si vous voulez la puissance instantanée. Le plus simple est la vue "plan des panneaux" de l'interface web EMA : cliquez sur un panneau, la popup "Panel information" affiche directement l'**ECU** (ainsi que son UID et le type d'onduleur), par exemple `123456789`. Vous pouvez aussi l'obtenir via l'appel API `GET /user/api/v2/systems/inverters/{sid}` (avec un client REST quelconque et vos identifiants), via le script `aps_discover.php` fourni (voir plus bas), ou le laisser vide si vous ne l'avez pas - le plugin fonctionnera quand même pour les compteurs d'énergie. Si vous l'avez, saisissez-le dans le champ `SID` du formulaire de création, collé au SID avec un `|` : `MON_SID|MON_ECU_ID` (sans espaces).

   ![ECU ID dans l'interface web EMA](https://raw.githubusercontent.com/henryju/apsystems-eedomus/main/img/web_ecu_id.png)

## Paramètres du plugin

- **`APP_ID`** : Votre App Id APsystems
- **`APP_SECRET`** : Votre App Secret APsystems
- **`SID`** : Identifiant de votre système EMA. Pour activer aussi la puissance instantanée, saisissez `SID|ECU_ID` (sans espaces, ECU_ID facultatif) - voir "Avant de commencer" ci-dessus.
- **`POLLING`** : Intervalle d'interrogation du résumé (aujourd'hui/mois/année/vie), en minutes, max 1000 (par défaut 180 = 3h)
- **`POLLING_POWER`** : Intervalle d'interrogation de la puissance instantanée, en minutes, max 1000 (par défaut 30)

Si vous ne renseignez pas d'ECU_ID, tout fonctionne normalement mais le canal "Puissance instantanée" reste à -1 (valeur non disponible).

## Script de découverte (aps_discover.php)

Pour retrouver facilement votre **ECU_ID** (et au passage l'UID de vos onduleurs, utile si vous voulez aller plus loin), un script `aps_discover.php` est fourni. Il n'est **pas** lié à un périphérique : il se lance manuellement, une seule fois.

1. Déposez `aps_discover.php` dans le même dossier de scripts qu'`aps_solar.php`.
2. Depuis la page de test de script d'eedomus, exécutez-le avec ces arguments :
   - `app_id` = votre App Id
   - `app_secret` = votre App Secret
   - `sid` = votre identifiant système

   Par exemple via l'URL :
   `http://[ip_de_votre_box]/script/?exec=aps_discover.php&app_id=XXXX&app_secret=YYYY&sid=ZZZZ`

   Le résultat s'affiche sous forme de page HTML lisible (et non plus en texte brut). Vous pouvez forcer la langue d'affichage avec `&lang=fr` ou `&lang=en` (par défaut : français).

3. Le script affiche :
   - les informations générales du système (puissance installée, date de création, état) ;
   - la ou les valeurs **ECU_ID** à coller après le SID dans le champ `SID` du plugin (`SID|ECU_ID`) ;
   - la liste des onduleurs (UID) rattachés à chaque ECU, si vous souhaitez créer vos propres requêtes plus poussées (énergie par onduleur, par canal, etc.).

Vous pouvez ensuite supprimer ce script si vous ne comptez pas le réutiliser, il n'est utile qu'à la configuration initiale.

## Coupure de nuit et quota API

APsystems limite les comptes OpenAPI à **1000 appels par mois**. Pour rester largement sous ce quota, le script ne fait plus aucun appel APsystems pendant la nuit : il lit le périphérique système eedomus **"Soleil Extérieur"** (valeurs `0=Couché, 20=Se Couche, 80=Se lève, 100=Levé`) et n'appelle l'API que quand sa valeur n'est pas 0. Pendant la coupure, le plugin continue simplement d'afficher les dernières valeurs connues (pas de mise à jour, pas d'appel API). Si ce périphérique est absent ou illisible sur votre box, le plugin se comporte comme s'il faisait toujours jour (aucune régression, juste pas d'économie de quota).

De ce fait, la consommation réelle mensuelle dépend des **heures d'ensoleillement**, pas de 24h - environ :

```
appels/mois = (heures d'ensoleillement/jour) x 60 / intervalle_POLLING x 30
```

Avec les valeurs par défaut **POLLING = 180 min** (aujourd'hui/mois/année/vie) et **POLLING_POWER = 30 min** (puissance instantanée), et en supposant ~12h d'ensoleillement en moyenne : cela fait environ 4 + 24 = 28 appels/jour, soit environ 840/mois au total - largement sous le quota (contre 1440/mois avec l'ancien fonctionnement 24h/24 et la puissance activée), et vous disposez de deux intervalles indépendants pour affiner :

- Augmentez encore `POLLING` (par ex. 240-360 min) puisque aujourd'hui/mois/année/vie varient peu d'une heure à l'autre - cela libère du quota pour `POLLING_POWER`.
- Diminuez `POLLING_POWER` pour une puissance instantanée plus réactive, ou augmentez-le si vous approchez du quota.

Le champ "Fréquence de la requête" d'eedomus est plafonné à **1000 minutes** (environ 16,6 jours) pour chacun des deux intervalles.

## Fonctionnement technique

`today`/`month`/`year`/`lifetime` sont des **canaux** rattachés les uns aux autres (via "Rattacher à" / `parent_id`) : eedomus mutualise une requête HTTP par cycle entre ces canaux, chacun extrayant sa propre valeur via son propre chemin XPath dans la même réponse. `power` est désormais un **périphérique maître indépendant**, avec son propre intervalle d'interrogation (`POLLING_POWER`), afin de pouvoir être rafraîchi selon un rythme différent du résumé.

Les deux périphériques appellent le même script `aps_solar.php`, en passant un argument littéral `p4` (`summary` ou `power`) afin que le script ne fasse que le ou les appels API pertinents pour le périphérique ayant déclenché ce cycle - les valeurs non rafraîchies à ce cycle sont simplement rejouées depuis la dernière lecture mémorisée (`saveVariable`/`loadVariable`, indexée par SID afin que les deux périphériques d'une même instance du plugin partagent le même cache).

À chaque appel (sauf coupure de nuit, voir ci-dessus), le script :

1. Calcule la signature HMAC-SHA256 exigée par l'API APsystems ;
2. En mode `summary`, interroge l'endpoint "summary" du système (today/month/year/lifetime) ;
3. En mode `power`, interroge la puissance instantanée (si un ECU_ID est renseigné) ;
4. Renvoie un document XML avec une balise par valeur (`<today>`, `<month>`, `<year>`, `<lifetime>`, `<power>`) - fraîchement récupérée pour le mode concerné, en cache pour le reste - que chaque périphérique/canal lit via son propre XPath (`//today`, `//month`, etc.).

En cas d'erreur API, toutes les balises concernées renvoient un nombre négatif (opposé du code d'erreur APsystems), ce qui reste compatible avec des périphériques de type nombre décimal tout en restant distinguable d'une vraie valeur (toujours positive).

Note technique : eedomus ne substitue que `[VAR1]`, `[VAR2]` et `[VAR3]` dans les URL de capteur HTTP (pas plus), et la concaténation de **plusieurs tags** (`plugin.parameters.X`) dans un seul champ VAR s'est avérée non fiable à l'usage (substitutions partielles ou nulles selon les cas). Chaque VAR ne porte donc qu'**un seul tag**, toujours garanti non vide : `VAR1=APP_ID`, `VAR2=APP_SECRET`, `VAR3=SID`. L'URL de base est fixée en dur dans le script. L'ECU_ID (facultatif) n'a pas de VAR dédiée : il se glisse dans `VAR3`, à la suite du SID et séparé par un `|` (`SID|ECU_ID`) - c'est fiable ici car il n'y a toujours qu'**un seul tag** `plugin.parameters.SID` à substituer dans ce VAR ; c'est le script `aps_solar.php` qui fait le split sur `|`, pas eedomus.

## Dépannage

- **Le périphérique principal affiche un nombre négatif** : c'est l'opposé d'un code d'erreur APsystems (ex. -4000, -3001...). Vérifiez votre App Id/App Secret, ou l'horloge de votre box (la signature dépend du timestamp).
- **Codes -7001/-7002/-2005** : quota API dépassé ou trop de requêtes, augmentez l'intervalle de polling.
- **Aucune valeur de puissance instantanée (reste à -1)** : vérifiez que le champ `SID` contient bien `SID|ECU_ID` (sans espaces) et qu'il y a bien des données pour aujourd'hui (rien la nuit).
- **Les valeurs cessent de se mettre à jour la nuit** : comportement attendu - le plugin coupe les appels API tant que "Soleil Extérieur" vaut 0 et continue d'afficher les dernières valeurs connues. La mise à jour reprend automatiquement au lever du soleil.
- **"Unit mismatch" dans les logs / périphérique bloqué en chargement** : si vous avez déjà créé le périphérique avant une mise à jour du plugin, supprimez-le entièrement puis recréez-le depuis le Store pour repartir d'une configuration propre (eedomus ne met pas à jour rétroactivement un périphérique déjà créé quand le JSON change). C'est nécessaire après cette mise à jour qui sépare le polling en deux périphériques (today/power).
