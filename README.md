# apsystems-eedomus

An [eedomus](https://doc.eedomus.com) plugin that retrieves solar production data from an APsystems EMA account (via the OpenAPI, HMAC-SHA256 signed) and exposes it as devices on your eedomus box: today/month/year/lifetime production and instantaneous power.

Full installation and configuration instructions:
- [readme_en.md](readme_en.md) — English
- [readme_fr.md](readme_fr.md) — Francais

## Repository layout

- `eedomus_plugin.json` — plugin manifest (devices, parameters)
- `aps_solar.php` — main script, polled continuously by the "today" HTTP sensor
- `aps_discover.php` — utility script, run manually once to find the ECU_ID and inverter UIDs
- `img/apsolar.png` — plugin icon
- `SESSION_NOTES.md` — pitfalls and design notes from developing this plugin against the eedomus SDK

## Building

The plugin is distributed to the eedomus Store as a single zip (`aps_eedomus_plugin.zip`) with all files at its root. `dist/` is not committed; build it locally with:

```bash
./build.sh       # bash (Linux/macOS/WSL, requires `zip`)
```

```powershell
.\build.ps1      # PowerShell (Windows)
```

Every push and pull request also builds the zip via [GitHub Actions](.github/workflows/ci.yml) and uploads it as a workflow artifact.

## Releasing

Publishing a GitHub release (tag `X.Y` or `X.Y.Z`, no `v` prefix) triggers the [release workflow](.github/workflows/release.yml), which updates `eedomus_plugin.json`'s `version`/`modification_date` to match the tag, moves the tag to that commit, builds the zip, and attaches it to the release.
