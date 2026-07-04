# APsystems Solar Panels (OpenAPI)

## Installation

This plugin isn't a script you paste manually somewhere: it's a full device for the **private eedomus Store**, submitted as a zip.

1. Log in to the eedomus portal.
2. Go to the **eedomus Store**, then click **Publier sur le store** ("Publish to the store", top right).
3. Click **Parcourir** ("Browse"), select the `aps_eedomus_plugin.zip` file (`eedomus_plugin.json`, the .php scripts and the readmes must sit at the root of the zip, not in a subfolder), then click **Envoyer** ("Send").
4. The device is immediately available in **Private mode**, only for your account (no eedomus team review needed at this stage).
5. Go to **Configuration / Ajouter ou supprimer un peripherique / Store eedomus**, find the device in your list, click it, then click **Creer** ("Create").
6. Fill in the requested parameters (APP_ID, APP_SECRET, SID, ECU_ID, BASE_URL, POLLING): these are exactly the parameters described in `eedomus_plugin.json`.
7. Confirm: the 5 devices (today/month/year/lifetime production + instantaneous power) are created automatically, along with the associated `aps_solar.php` and `aps_discover.php` scripts.

If you later modify the zip (fixes, improvements), you can resubmit it the same way; as the author, updates to a device you've already made private/published don't require a new review.

This plugin pulls solar production data from your APsystems EMA account (via the OpenAPI) and creates the following devices on your eedomus box:

- **Today's production** (kWh) - main device
- **This month's production** (kWh)
- **This year's production** (kWh)
- **Lifetime production** (kWh)
- **Instantaneous power** (W) - only if you provide the ECU ID

## Before you start

1. **Request OpenAPI access**: email APsystems support stating who you are, why you want an OpenAPI account, and what you'll do with the data. You'll receive an **App Id** and **App Secret**.
2. **Find your SID**: your system's unique id, visible in your EMA account (https://www.apsystemsema.com/ema/index.action).
3. **(Optional) Find your ECU ID**: only needed for instantaneous power. You can get it via `GET /user/api/v2/systems/inverters/{sid}` using any REST client, or leave it blank - the energy counters will still work.

## Plugin parameters

| Parameter | Description |
|---|---|
| APP_ID | Your APsystems App Id |
| APP_SECRET | Your APsystems App Secret |
| SID | Your EMA system id |
| ECU_ID | Your ECU id (optional, see "Enable instantaneous power" below) |
| POLLING | Polling interval in minutes, max 1000 (default 60 = 1h) |

The API base URL (`https://api.apsystemsema.com:9282`) is now hardcoded in the script; it's no longer a form parameter (too rare a need to justify spending one of the 3 available variable slots, see the technical section below).

## Enable instantaneous power (ECU_ID)

For technical reasons related to eedomus HTTP sensors (see "How it works"), the ECU_ID you enter in the creation form is **not automatically passed** to the script on every cycle. You need to enable it once, manually:

1. Note the **API code** of the "Today's production" device (Device settings / Expert settings).
2. Visit this URL once in your browser (replace the values):

   `http://[your_box_ip]/script/?exec=aps_solar.php&p1=YOUR_APP_ID&p2=YOUR_APP_SECRET&p3=YOUR_SID&ecu=YOUR_ECU_ID&eedomus_controller_module_id=DEVICE_API_CODE`

3. The script then permanently remembers the ECU_ID (tied to that device) and will use it automatically on every future poll, with no need to repeat this.

If you skip this step, everything else works normally, but the "Instantaneous power" channel stays at -1 (unavailable).

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
   - the **ECU_ID** value(s) to paste into the plugin's parameters;
   - the list of inverter UIDs under each ECU, useful if you want to build your own more advanced queries (per-inverter, per-channel energy, etc.).

You can delete this script afterwards if you don't plan to reuse it; it's only needed for initial setup.

## API quota

APsystems limits OpenAPI accounts to **1000 calls/month**. Note: eedomus HTTP sensors poll continuously, 24/7 (there's no built-in "daylight hours only" mode), so the calculation has to be based on 24h, not just sunlight hours.

With the default **60-minute** interval, that's 24 calls/day, roughly 720/month for the "today" device alone (summary only): comfortably under quota.

If you enable instantaneous power (ECU_ID set), each cycle makes **2 calls** instead of 1 (summary + ECU power), roughly 1440/month at a 60-minute interval: **this exceeds the quota**. In that case, increase the interval to 90 or 120 minutes (roughly 960 or 720 calls/month).

The eedomus "Fréquence de la requête" field caps out at **1000 minutes** (about 16.6 days), which is more than enough if you want to go much lower to stay well under quota.

## How it works

The 5 devices (today/month/year/lifetime/power) are **channels** linked together (via "Rattacher à" / `parent_id`). eedomus then automatically shares the HTTP request across channels: only one request is made per cycle, and each channel extracts its own value via its own XPath from that same response.

The `aps_solar.php` script, called by the "Today's production" device:

1. Computes the HMAC-SHA256 signature required by the APsystems API;
2. Queries the system's "summary" endpoint (today/month/year/lifetime);
3. If an ECU_ID is set, also queries instantaneous power;
4. Returns an XML document with one tag per value (`<today>`, `<month>`, `<year>`, `<lifetime>`, `<power>`), which each channel reads via its own XPath (`//today`, `//month`, etc.).

On API error, all relevant tags return a negative number (the opposite of the APsystems error code), which stays compatible with decimal-number devices while remaining distinguishable from a real value (always positive).

Technical note: eedomus only substitutes `[VAR1]`, `[VAR2]` and `[VAR3]` in HTTP sensor URLs (no more), and concatenating several "tags" (`plugin.parameters.X`) inside one VAR field proved unreliable in practice (partial or zero substitutions depending on the run). Each VAR now carries exactly **one tag**, always guaranteed non-empty: `VAR1=APP_ID`, `VAR2=APP_SECRET`, `VAR3=SID`. The base URL is hardcoded in the script, and ECU_ID (potentially empty, hence the most fragile to pass this way) is handled separately via persistent storage (`saveVariable`/`loadVariable`), see "Enable instantaneous power" above.

## Troubleshooting

- **Main device shows a negative number**: that's the opposite of an APsystems error code (e.g. -4000, -3001...). Check your App Id/Secret, or your box's clock (signature depends on the timestamp).
- **Codes -7001/-7002/-2005**: API quota exceeded or too many requests, increase the polling interval.
- **No instantaneous power value (stays at -1)**: check your ECU_ID is correct and that there's data for today (none at night).
- **"Unit mismatch" in the logs / device stuck loading**: if you created the device before a plugin update, delete it entirely and recreate it from the Store to start from a clean configuration (eedomus doesn't retroactively update an already-created device when the JSON changes).
