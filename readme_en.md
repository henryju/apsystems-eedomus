# APsystems Solar Panels (OpenAPI)

## Installation

1. Go to **Configuration / Ajouter ou supprimer un peripherique / Store eedomus**, search for "APsystems Solar Panels", click it, then click **Creer** ("Create").
2. Fill in the requested parameters (APP_ID, APP_SECRET, SID, POLLING, POLLING_POWER): see the "Plugin parameters" section below for details on each. To also enable instantaneous power, enter `SID|ECU_ID` in the `SID` field (see "Before you start" below).
3. Confirm: the 5 devices (today/month/year/lifetime production + instantaneous power) are created automatically, along with the associated `aps_solar.php` script. `today` (with its `month`/`year`/`lifetime` channels) and `power` are two independent master devices, each with its own polling interval.

This plugin pulls solar production data from your APsystems EMA account (via the OpenAPI) and creates the following devices on your eedomus box:

- **Today's production** (kWh) - main device
- **This month's production** (kWh)
- **This year's production** (kWh)
- **Lifetime production** (kWh)
- **Instantaneous power** (W) - only if you provide the ECU ID (see below)

## Before you start

1. **Get your OpenAPI credentials**: get your **App Id** and **App Secret** directly, no request needed - either in the EMA Android app (**Settings -> OpenAPI Service -> Developer Authorization**) or on the web at https://www.apsystemsema.com/apsystems/web/setting/personalSetting/openAPIService (e.g. App Id `11aa22bb33cc44dd55ee66ff77889900`). (If neither is available for your account, email APsystems support stating who you are, why you want an OpenAPI account, and what you'll do with the data.)

   ![OpenAPI credentials in the EMA web interface](https://raw.githubusercontent.com/henryju/apsystems-eedomus/main/img/web_openapi.png)

2. **Find your SID**: your system's unique id, visible in your EMA account (https://www.apsystemsema.com/ema/index.action), e.g. `D11D123456789012`.

   ![SID in the EMA web interface](https://raw.githubusercontent.com/henryju/apsystems-eedomus/main/img/web_sid.png)

3. **(Optional) Find your ECU ID**: only needed for instantaneous power. The simplest way is your system's panel layout view in the EMA web interface: click a panel, its "Panel information" popup shows the **ECU** id directly (along with its UID and inverter type), e.g. `123456789`. You can also get it via `GET /user/api/v2/systems/inverters/{sid}` using any REST client, or leave it blank - the energy counters will still work. If you have it, enter it in the creation form's `SID` field, appended to the SID with a `|`: `YOUR_SID|YOUR_ECU_ID` (no spaces).

   ![ECU ID in the EMA web interface](https://raw.githubusercontent.com/henryju/apsystems-eedomus/main/img/web_ecu_id.png)

## Plugin parameters

- **`APP_ID`**: Your APsystems App Id
- **`APP_SECRET`**: Your APsystems App Secret
- **`SID`**: Your EMA system id. To also enable instantaneous power, enter `SID|ECU_ID` (no spaces, ECU_ID optional) - see "Before you start" above.
- **`POLLING`**: Polling interval for today/month/year/lifetime, in minutes, max 1000 (default 180 = 3h)
- **`POLLING_POWER`**: Polling interval for instantaneous power, in minutes, max 1000 (default 30)

If you don't enter an ECU_ID, everything else works normally, but the "Instantaneous power" channel stays at -1 (unavailable).

## Night skip and API quota

APsystems limits OpenAPI accounts to **1000 calls/month**. To stay well under that limit, the script skips every APsystems call while it's night: it reads eedomus's own **"Soleil Exterieur"** system peripheral (values `0=Couche, 20=Se Couche, 80=Se leve, 100=Leve`) and only calls the API when its value isn't 0. While skipped, the plugin simply keeps showing the last known values (no update, no API call). If that peripheral is missing or unreadable on your box, the plugin fails open and behaves as if it were always day (no regression, just no quota savings).

Because of this, actual monthly usage scales with **daylight hours**, not 24h - roughly:

```
calls/month = (daylight hours/day) x 60 / POLLING_interval x 30
```

With the default **POLLING = 180 min** (today/month/year/lifetime) and **POLLING_POWER = 30 min** (instantaneous power), and assuming ~12h of daylight on average: that's about 4 + 24 = 28 calls/day, roughly 840/month combined - well under quota (versus 1440/month with the old always-on 24/7 default and power enabled), and you have two independent intervals to tune further:

- Raise `POLLING` further (e.g. 240-360 min) since today/month/year/lifetime barely change hour to hour - this frees up quota for `POLLING_POWER`.
- Lower `POLLING_POWER` for a more responsive live power reading, or raise it if you're close to the quota.

The eedomus "Fréquence de la requête" field caps out at **1000 minutes** (about 16.6 days) for either interval.

## Troubleshooting

- **Main device shows a negative number**: that's the opposite of an APsystems error code (e.g. -4000, -3001...). Check your App Id/Secret, or your box's clock (signature depends on the timestamp).
- **Codes -7001/-7002/-2005**: API quota exceeded or too many requests, increase the polling interval.
- **Track your API usage**: in the EMA web interface, the same page where you retrieved your App Id/App Secret has a **Historical Call Statistics** section ("Only show the number of visits per month in the last six months.") showing the number of calls per month for the last six months.
- **No instantaneous power value (stays at -1)**: check the `SID` field actually contains `SID|ECU_ID` (no spaces), and that there's data for today (none at night).
- **Values stop updating at night**: expected - the plugin skips API calls while "Soleil Exterieur" reads 0 and just keeps showing the last known values. It resumes automatically at sunrise.
- **"Unit mismatch" in the logs / device stuck loading**: if you created the device before a plugin update, delete it entirely and recreate it from the Store to start from a clean configuration (eedomus doesn't retroactively update an already-created device when the JSON changes).
- **Check the eedomus logs**: on your box's local interface, go to **Parametres -> Logs -> http_sensor.log** (or directly `http://eedomus.local/log/?log=http_sensor.log`) to see the detail of the plugin's requests (URL called, return code, value read).
