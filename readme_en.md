# APsystems Solar Panels (OpenAPI)

## Installation

1. Go to **Configuration / Ajouter ou supprimer un peripherique / Store eedomus**, search for "APsystems Solar Panels", click it, then click **Creer** ("Create").
2. Fill in the requested parameters (APP_ID, APP_SECRET, SID, POLLING, POLLING_POWER): see the "Plugin parameters" section below for details on each. To also enable instantaneous power, enter `SID|ECU_ID` in the `SID` field (see "Before you start" below).
3. Confirm: the 5 devices (today/month/year/lifetime production + instantaneous power) are created automatically, along with the associated `aps_solar.php` and `aps_discover.php` scripts. `today` (with its `month`/`year`/`lifetime` channels) and `power` are two independent master devices, each with its own polling interval.

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

3. **(Optional) Find your ECU ID**: only needed for instantaneous power. The simplest way is your system's panel layout view in the EMA web interface: click a panel, its "Panel information" popup shows the **ECU** id directly (along with its UID and inverter type), e.g. `123456789`. You can also get it via `GET /user/api/v2/systems/inverters/{sid}` using any REST client, or the included `aps_discover.php` script (see below), or leave it blank - the energy counters will still work. If you have it, enter it in the creation form's `SID` field, appended to the SID with a `|`: `YOUR_SID|YOUR_ECU_ID` (no spaces).

   ![ECU ID in the EMA web interface](https://raw.githubusercontent.com/henryju/apsystems-eedomus/main/img/web_ecu_id.png)

## Plugin parameters

- **`APP_ID`**: Your APsystems App Id
- **`APP_SECRET`**: Your APsystems App Secret
- **`SID`**: Your EMA system id. To also enable instantaneous power, enter `SID|ECU_ID` (no spaces, ECU_ID optional) - see "Before you start" above.
- **`POLLING`**: Polling interval for today/month/year/lifetime, in minutes, max 1000 (default 180 = 3h)
- **`POLLING_POWER`**: Polling interval for instantaneous power, in minutes, max 1000 (default 30)

The API base URL (`https://api.apsystemsema.com:9282`) is now hardcoded in the script; it's no longer a form parameter (too rare a need to justify spending one of the 3 available variable slots, see the technical section below).

If you don't enter an ECU_ID, everything else works normally, but the "Instantaneous power" channel stays at -1 (unavailable).

## Discovery script (aps_discover.php)

To easily find your **ECU_ID** (and your inverter UIDs, useful if you want to go further), a `aps_discover.php` script is included. It is **not** tied to any device: run it manually, once.

1. Upload `aps_discover.php` to the same script folder as `aps_solar.php`.
2. From eedomus' script test page, run it with these arguments:
   - `app_id` = your App Id
   - `app_secret` = your App Secret
   - `sid` = your system id

   For example via this URL:
   `http://[your_box_ip]/script/?exec=aps_discover.php&app_id=XXXX&app_secret=YYYY&sid=ZZZZ`

   The result is displayed as a readable HTML page (not plain text anymore). You can force the display language with `&lang=fr` or `&lang=en` (default: French).

3. The script prints:
   - general system info (installed capacity, creation date, status);
   - the **ECU_ID** value(s) to append to the SID in the plugin's `SID` field (`SID|ECU_ID`);
   - the list of inverter UIDs under each ECU, useful if you want to build your own more advanced queries (per-inverter, per-channel energy, etc.).

You can delete this script afterwards if you don't plan to reuse it; it's only needed for initial setup.

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

## How it works

`today`/`month`/`year`/`lifetime` are **channels** linked together (via "Rattacher à" / `parent_id`): eedomus shares one HTTP request across them per cycle, each channel extracting its own value via its own XPath from that same response. `power` is now an **independent master device** with its own polling interval (`POLLING_POWER`), so it can be refreshed on a different schedule than the summary values.

Both devices call the same `aps_solar.php` script, passing a literal `p4` argument (`summary` or `power`) so the script only makes the API call(s) relevant to whichever device triggered this cycle - the values not refreshed this cycle are simply replayed from the last cached reading (`saveVariable`/`loadVariable`, keyed by SID so both devices of the same plugin instance share the same cache).

On each call (unless skipped for night, see above), the script:

1. Computes the HMAC-SHA256 signature required by the APsystems API;
2. In `summary` mode, queries the system's "summary" endpoint (today/month/year/lifetime);
3. In `power` mode, queries instantaneous power (if an ECU_ID is set);
4. Returns an XML document with one tag per value (`<today>`, `<month>`, `<year>`, `<lifetime>`, `<power>`) - freshly fetched for the relevant mode, cached for the rest - which each device/channel reads via its own XPath (`//today`, `//month`, etc.).

On API error, all relevant tags return a negative number (the opposite of the APsystems error code), which stays compatible with decimal-number devices while remaining distinguishable from a real value (always positive).

Technical note: eedomus only substitutes `[VAR1]`, `[VAR2]` and `[VAR3]` in HTTP sensor URLs (no more), and concatenating **several tags** (`plugin.parameters.X`) inside one VAR field proved unreliable in practice (partial or zero substitutions depending on the run). Each VAR carries exactly **one tag**, always guaranteed non-empty: `VAR1=APP_ID`, `VAR2=APP_SECRET`, `VAR3=SID`. The base URL is hardcoded in the script. ECU_ID (optional) has no dedicated VAR: it rides along in `VAR3`, appended to the SID with a `|` (`SID|ECU_ID`) - reliable here because there's still only **one tag** (`plugin.parameters.SID`) being substituted into that VAR; the `aps_solar.php` script does the `|` split, not eedomus.

## Troubleshooting

- **Main device shows a negative number**: that's the opposite of an APsystems error code (e.g. -4000, -3001...). Check your App Id/Secret, or your box's clock (signature depends on the timestamp).
- **Codes -7001/-7002/-2005**: API quota exceeded or too many requests, increase the polling interval.
- **No instantaneous power value (stays at -1)**: check the `SID` field actually contains `SID|ECU_ID` (no spaces), and that there's data for today (none at night).
- **Values stop updating at night**: expected - the plugin skips API calls while "Soleil Exterieur" reads 0 and just keeps showing the last known values. It resumes automatically at sunrise.
- **"Unit mismatch" in the logs / device stuck loading**: if you created the device before a plugin update, delete it entirely and recreate it from the Store to start from a clean configuration (eedomus doesn't retroactively update an already-created device when the JSON changes). This is required after upgrading to the two-device (today/power) polling split described above.
