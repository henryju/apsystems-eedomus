# Panneaux solaires APsystems (OpenAPI)

## Installation

1. Allez dans **Configuration / Ajouter ou supprimer un périphérique / Store eedomus**, recherchez "Panneaux solaires APsystems", cliquez dessus puis sur **Créer**.
2. Renseignez les paramètres demandés dans le formulaire (`APP_ID`, `APP_SECRET`, `SID`, `POLLING`, `POLLING_POWER`) : voir la section "Paramètres du plugin" ci-dessous pour le détail de chacun. Pour activer aussi la puissance instantanée, saisissez `SID|ECU_ID` dans le champ `SID` (voir "Avant de commencer" ci-dessous).
3. Validez : les 5 périphériques (production aujourd'hui/mois/année/vie + puissance instantanée) sont créés automatiquement, ainsi que le script `aps_solar.php` associé. "today" (avec ses canaux mois/année/vie) et "power" sont deux périphériques maîtres indépendants, chacun avec son propre intervalle d'interrogation.

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

3. **(Facultatif) Trouver votre ECU ID** : nécessaire uniquement si vous voulez la puissance instantanée. Le plus simple est la vue "plan des panneaux" de l'interface web EMA : cliquez sur un panneau, la popup "Panel information" affiche directement l'**ECU** (ainsi que son UID et le type d'onduleur), par exemple `123456789`. Vous pouvez aussi l'obtenir via l'appel API `GET /user/api/v2/systems/inverters/{sid}` (avec un client REST quelconque et vos identifiants), ou le laisser vide si vous ne l'avez pas - le plugin fonctionnera quand même pour les compteurs d'énergie. Si vous l'avez, saisissez-le dans le champ `SID` du formulaire de création, collé au SID avec un `|` : `MON_SID|MON_ECU_ID` (sans espaces).

   ![ECU ID dans l'interface web EMA](https://raw.githubusercontent.com/henryju/apsystems-eedomus/main/img/web_ecu_id.png)

## Paramètres du plugin

- **`APP_ID`** : Votre App Id APsystems
- **`APP_SECRET`** : Votre App Secret APsystems
- **`SID`** : Identifiant de votre système EMA. Pour activer aussi la puissance instantanée, saisissez `SID|ECU_ID` (sans espaces, ECU_ID facultatif) - voir "Avant de commencer" ci-dessus.
- **`POLLING`** : Intervalle d'interrogation du résumé (aujourd'hui/mois/année/vie), en minutes, max 1000 (par défaut 180 = 3h)
- **`POLLING_POWER`** : Intervalle d'interrogation de la puissance instantanée, en minutes, max 1000 (par défaut 30)

Si vous ne renseignez pas d'ECU_ID, tout fonctionne normalement mais le canal "Puissance instantanée" reste à -1 (valeur non disponible).

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

## Dépannage

- **Le périphérique principal affiche un nombre négatif** : c'est l'opposé d'un code d'erreur APsystems (ex. -4000, -3001...). Vérifiez votre App Id/App Secret, ou l'horloge de votre box (la signature dépend du timestamp).
- **Codes -7001/-7002/-2005** : quota API dépassé ou trop de requêtes, augmentez l'intervalle de polling.
- **Suivre votre consommation d'API** : dans l'interface web EMA, sur la même page où vous avez récupéré votre App Id/App Secret, la section **Historical Call Statistics** ("Only show the number of visits per month in the last six months.") indique le nombre d'appels par mois sur les 6 derniers mois.
- **Aucune valeur de puissance instantanée (reste à -1)** : vérifiez que le champ `SID` contient bien `SID|ECU_ID` (sans espaces) et qu'il y a bien des données pour aujourd'hui (rien la nuit).
- **Les valeurs cessent de se mettre à jour la nuit** : comportement attendu - le plugin coupe les appels API tant que "Soleil Extérieur" vaut 0 et continue d'afficher les dernières valeurs connues. La mise à jour reprend automatiquement au lever du soleil.
- **"Unit mismatch" dans les logs / périphérique bloqué en chargement** : si vous avez déjà créé le périphérique avant une mise à jour du plugin, supprimez-le entièrement puis recréez-le depuis le Store pour repartir d'une configuration propre (eedomus ne met pas à jour rétroactivement un périphérique déjà créé quand le JSON change).
- **Consulter les logs eedomus** : sur l'interface locale de votre box, allez dans **Paramètres -> Logs -> http_sensor.log** (ou directement `http://eedomus.local/log/?log=http_sensor.log`) pour voir le détail des requêtes du plugin (URL appelée, code retour, valeur lue).
