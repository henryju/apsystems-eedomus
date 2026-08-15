# apsystems-eedomus

An [eedomus](https://doc.eedomus.com) plugin that retrieves solar production data from an APsystems EMA account (via the OpenAPI, HMAC-SHA256 signed) and exposes it as devices on your eedomus box: today/month/year/lifetime production and instantaneous power.

Full installation and configuration instructions:
- [readme_en.md](readme_en.md) — English
- [readme_fr.md](readme_fr.md) — Francais

## Repository layout

- `eedomus_plugin.json` — plugin manifest (devices, parameters)
- `aps_solar.php` — main script, polled by the "today" (summary) and "power" (instantaneous power) HTTP sensors, each on its own interval, with a night-time skip based on eedomus's "Soleil Exterieur" peripheral
- `aps_discover.php` — utility script, run manually once to find the ECU_ID and inverter UIDs
- `img/apsolar.png` — plugin icon
- `SESSION_NOTES.md` — pitfalls and design notes from developing this plugin against the eedomus SDK

## Design notes

The APsystems API base URL is hardcoded in `aps_solar.php` rather than exposed as a plugin parameter: eedomus HTTP sensors only substitute 3 variable slots (`VAR1`-`VAR3`) into `RAW_URL`, already spent on `APP_ID`/`APP_SECRET`/`SID` — too rare a need to justify a 4th. See `SESSION_NOTES.md` for this and other eedomus SDK constraints that shaped the plugin's design.

## Building

The plugin is distributed to the eedomus Store as a single zip (`aps_eedomus_plugin.zip`) with all files at its root. `dist/` is not committed; build it locally with:

```bash
./build.sh       # bash (Linux/macOS/WSL, requires `zip`)
```

```powershell
.\build.ps1      # PowerShell (Windows)
```

Every push and pull request also builds the zip via [GitHub Actions](.github/workflows/ci.yml) and uploads it as a workflow artifact.

## Publishing to the eedomus Store

1. Log in to the eedomus portal.
2. Go to the **eedomus Store**, then click **Publier sur le store** ("Publish to the store", top right).
3. Click **Parcourir** ("Browse"), select `dist/aps_eedomus_plugin.zip` (built as described above; `eedomus_plugin.json`, the .php scripts and the readmes must sit at the root of the zip, not in a subfolder), then click **Envoyer** ("Send").
4. The device becomes available immediately - in **Private mode** while unlisted, no eedomus team review needed at this stage.

Resubmitting the same way after a fix/improvement updates it in place; as the author, updates to a device you've already made private/published don't require a new review. Note that eedomus does **not** retroactively update devices that were already created from a previous version of the manifest: after any change to `devices`/`parameters` in `eedomus_plugin.json`, existing installs (including your own) need to delete and recreate the plugin's devices to pick up the change.

## Releasing

Publishing a GitHub release (tag `X.Y` or `X.Y.Z`, no `v` prefix) triggers the [release workflow](.github/workflows/release.yml), which updates `eedomus_plugin.json`'s `version`/`modification_date` to match the tag, moves the tag to that commit, builds the zip, and attaches it to the release.

## License

[GPL-3.0](LICENSE)
