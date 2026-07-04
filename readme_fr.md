# Panneaux solaires APsystems (OpenAPI)

## Installation

Ce plugin n'est pas un script a coller manuellement : c'est un peripherique complet du **Store eedomus prive**, a soumettre via le zip.

1. Connectez-vous sur le portail eedomus.
2. Allez sur le **Store eedomus**, puis cliquez sur **Publier sur le store** (en haut a droite).
3. Cliquez sur **Parcourir**, selectionnez le fichier `aps_eedomus_plugin.zip` (les fichiers `eedomus_plugin.json`, les scripts .php et les readme doivent etre a la racine du zip, pas dans un sous-dossier), puis cliquez sur **Envoyer**.
4. Le peripherique est immediatement disponible **en mode Prive**, reserve a votre compte (aucune validation par l'equipe eedomus n'est necessaire a ce stade).
5. Allez dans **Configuration / Ajouter ou supprimer un peripherique / Store eedomus**, retrouvez le peripherique dans votre liste, cliquez dessus puis sur **Creer**.
6. Renseignez les parametres demandes dans le formulaire (APP_ID, APP_SECRET, SID, ECU_ID, BASE_URL, POLLING) : ce sont exactement les parametres decrits dans `eedomus_plugin.json`.
7. Validez : les 5 peripheriques (production aujourd'hui/mois/annee/vie + puissance instantanee) sont crees automatiquement, ainsi que les scripts `aps_solar.php` et `aps_discover.php` associes.

Si vous modifiez plus tard le zip (correction, amelioration), vous pouvez le soumettre a nouveau de la meme maniere ; en tant qu'auteur, les mises a jour d'un peripherique deja prive/publie par vous ne necessitent pas de nouvelle validation.

Ce plugin recupere la production de vos panneaux solaires APsystems (via l'EMA / OpenAPI) et cree les peripheriques suivants sur votre box eedomus :

- **Production aujourd'hui** (kWh) - peripherique principal
- **Production ce mois** (kWh)
- **Production cette annee** (kWh)
- **Production totale / vie** (kWh)
- **Puissance instantanee** (W) - uniquement si vous renseignez l'ID ECU

## Avant de commencer

1. **Demander un acces OpenAPI** : envoyez un email au support APsystems en indiquant qui vous etes, pourquoi vous voulez un compte OpenAPI, et ce que vous comptez faire des donnees. Vous recevrez un **App Id** et un **App Secret**.
2. **Trouver votre SID** : c'est l'identifiant de votre systeme, visible dans votre compte EMA (https://www.apsystemsema.com/ema/index.action).
3. **(Facultatif) Trouver votre ECU ID** : necessaire uniquement si vous voulez la puissance instantanee. Vous pouvez l'obtenir via l'appel API `GET /user/api/v2/systems/inverters/{sid}` (avec un client REST quelconque et vos identifiants), ou le laisser vide si vous ne l'avez pas - le plugin fonctionnera quand meme pour les compteurs d'energie.

## Parametres du plugin

| Parametre | Description |
|---|---|
| APP_ID | Votre App Id APsystems |
| APP_SECRET | Votre App Secret APsystems |
| SID | Identifiant de votre systeme EMA |
| ECU_ID | Identifiant de votre ECU (facultatif, voir "Activer la puissance instantanee" ci-dessous) |
| POLLING | Intervalle d'interrogation en minutes, max 1000 (par defaut 60 = 1h) |

L'URL de base de l'API (`https://api.apsystemsema.com:9282`) est fixee directement dans le script ; ce n'est plus un parametre du formulaire (cas d'usage trop rare pour justifier de consommer l'un des 3 emplacements de variable disponibles, voir la section technique plus bas).

## Activer la puissance instantanee (ECU_ID)

Pour des raisons techniques liees aux capteurs HTTP eedomus (voir "Fonctionnement technique"), l'ECU_ID que vous saisissez dans le formulaire de creation **n'est pas automatiquement transmis** au script a chaque cycle. Il faut l'activer une fois, manuellement :

1. Notez le **code API** du peripherique "Production aujourd'hui" (Parametres du peripherique / Parametres expert).
2. Visitez une fois cette URL dans votre navigateur (remplacez les valeurs) :

   `http://[ip_de_votre_box]/script/?exec=aps_solar.php&p1=VOTRE_APP_ID&p2=VOTRE_APP_SECRET&p3=VOTRE_SID&ecu=VOTRE_ECU_ID&eedomus_controller_module_id=CODE_API_DU_PERIPH`

3. Le script memorise alors l'ECU_ID de facon permanente (associe a ce peripherique), et l'utilisera automatiquement a chaque interrogation future, sans que vous ayez besoin de repeter l'operation.

Si vous ne faites pas cette etape, tout fonctionne normalement mais le canal "Puissance instantanee" reste a -1 (valeur non disponible).

## Script de decouverte (aps_discover.php)

Pour retrouver facilement votre **ECU_ID** (et au passage l'UID de vos onduleurs, utile si vous voulez aller plus loin), un script `aps_discover.php` est fourni. Il n'est **pas** lie a un peripherique : il se lance manuellement, une seule fois.

1. Deposez `aps_discover.php` dans le meme dossier de scripts qu'`aps_solar.php`.
2. Depuis la page de test de script d'eedomus, executez-le avec ces arguments :
   - `app_id` = votre App Id
   - `app_secret` = votre App Secret
   - `sid` = votre identifiant systeme

   Par exemple via l'URL :
   `http://[ip_de_votre_box]/script/?exec=aps_discover.php&app_id=XXXX&app_secret=YYYY&sid=ZZZZ`

   Le resultat s'affiche sous forme de page HTML lisible (et non plus en texte brut). Vous pouvez forcer la langue d'affichage avec `&lang=fr` ou `&lang=en` (par defaut : francais).

3. Le script affiche :
   - les informations generales du systeme (puissance installee, date de creation, etat) ;
   - la ou les valeurs **ECU_ID** a copier dans les parametres du plugin ;
   - la liste des onduleurs (UID) rattaches a chaque ECU, si vous souhaitez creer vos propres requetes plus poussees (energie par onduleur, par canal, etc.).

Vous pouvez ensuite supprimer ce script si vous ne comptez pas le reutiliser, il n'est utile qu'a la configuration initiale.

## A savoir sur le quota API

APsystems limite les comptes OpenAPI a **1000 appels par mois**. Attention : le capteur HTTP eedomus interroge en continu, 24h/24 (il n'y a pas de notion de "plage horaire jour/nuit" native) - le calcul doit donc se faire sur une base de 24h, pas seulement sur les heures d'ensoleillement.

Avec l'intervalle par defaut de **60 minutes**, cela fait 24 appels/jour, soit environ 720/mois pour le peripherique "today" seul (summary uniquement) : on reste sous le quota.

Si vous activez la puissance instantanee (ECU_ID renseigne), chaque cycle fait **2 appels** au lieu d'1 (summary + puissance ECU), soit environ 1440/mois a 60 minutes d'intervalle : **cela depasse le quota**. Dans ce cas, augmentez l'intervalle a 90 ou 120 minutes (environ 960 ou 720 appels/mois).

Le champ "Frequence de la requete" d'eedomus est plafonne a **1000 minutes** (environ 16,6 jours) : largement suffisant pour rester tres en dessous du quota si besoin.

## Fonctionnement technique

Les 5 peripheriques (aujourd'hui/mois/annee/vie/puissance) sont des **canaux** rattaches les uns aux autres (via "Rattacher a" / `parent_id`). eedomus mutualise alors automatiquement la requete HTTP entre canaux : une seule requete est faite par cycle, et chaque canal extrait sa propre valeur via son propre chemin XPath dans la meme reponse.

Le script `aps_solar.php`, appele par le peripherique "Production aujourd'hui" :

1. Calcule la signature HMAC-SHA256 exigee par l'API APsystems ;
2. Interroge l'endpoint "summary" du systeme (today/month/year/lifetime) ;
3. Si un ECU_ID est renseigne, interroge egalement la puissance instantanee ;
4. Renvoie un document XML avec une balise par valeur (`<today>`, `<month>`, `<year>`, `<lifetime>`, `<power>`), que chaque canal lit via son propre XPath (`//today`, `//month`, etc.).

En cas d'erreur API, toutes les balises concernees renvoient un nombre negatif (oppose du code d'erreur APsystems), ce qui reste compatible avec des peripheriques de type nombre decimal tout en restant distinguable d'une vraie valeur (toujours positive).

Note technique : eedomus ne substitue que `[VAR1]`, `[VAR2]` et `[VAR3]` dans les URL de capteur HTTP (pas plus), et la concatenation de plusieurs "tags" (`plugin.parameters.X`) dans un seul champ VAR s'est averee non fiable a l'usage (substitutions partielles ou nulles selon les cas). Chaque VAR ne porte donc plus qu'**un seul tag**, toujours garanti non vide : `VAR1=APP_ID`, `VAR2=APP_SECRET`, `VAR3=SID`. L'URL de base est fixee en dur dans le script, et l'ECU_ID (potentiellement vide, donc le plus fragile a transmettre de cette maniere) est gere a part via une memorisation persistante (`saveVariable`/`loadVariable`), voir la section "Activer la puissance instantanee" plus haut.

## Depannage

- **Le peripherique principal affiche un nombre negatif** : c'est l'oppose d'un code d'erreur APsystems (ex. -4000, -3001...). Verifiez votre App Id/App Secret, ou l'horloge de votre box (la signature depend du timestamp).
- **Codes -7001/-7002/-2005** : quota API depasse ou trop de requetes, augmentez l'intervalle de polling.
- **Aucune valeur de puissance instantanee (reste a -1)** : verifiez que l'ECU_ID est correct et qu'il y a bien des donnees pour aujourd'hui (rien la nuit).
- **"Unit mismatch" dans les logs / peripherique bloque en chargement** : si vous avez deja cree le peripherique avant une mise a jour du plugin, supprimez-le entierement puis recreez-le depuis le Store pour repartir d'une configuration propre (eedomus ne met pas a jour retroactivement un peripherique deja cree quand le JSON change).

