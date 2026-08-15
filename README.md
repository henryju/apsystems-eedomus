# apsystems-eedomus

An [eedomus](https://doc.eedomus.com) plugin that retrieves solar production data from an APsystems EMA account (via the OpenAPI, HMAC-SHA256 signed) and exposes it as devices on your eedomus box: today/month/year/lifetime production and instantaneous power.

Full installation and configuration instructions:
- [readme_en.md](readme_en.md) — English
- [readme_fr.md](readme_fr.md) — Francais

## Repository layout

- `eedomus_plugin.json` — plugin manifest (devices, parameters)
- `aps_solar.php` — main script, polled by the "today" (summary) and "power" (instantaneous power) HTTP sensors, each on its own interval, with a night-time skip based on eedomus's "Soleil Exterieur" peripheral
- `aps_discover.php` — one-off utility script that used to help find the ECU_ID and inverter UIDs; kept here for reference but no longer bundled in the distributed zip (superseded by the EMA web interface's "Panel information" popup, see the user docs)
- `img/apsolar.png` — plugin icon
- `SESSION_NOTES.md` — pitfalls and design notes from developing this plugin against the eedomus SDK

## Design notes

- **Channels vs. independent devices**: `today`/`month`/`year`/`lifetime` are eedomus "channels" linked via `parent_id` — eedomus shares one HTTP request per cycle among them, each extracting its own value via its own XPath from that same response. `power` is a separate top-level device with its own `POLLING_POWER` interval, so it can refresh on a different schedule than the summary values.
- **Single script, mode-switched**: both `today` and `power` call `aps_solar.php`, passing a literal `p4` argument (`summary` or `power`) so the script only makes the API call(s) relevant to whichever device triggered the cycle. Values not refreshed this cycle are replayed from the last cached reading (`saveVariable`/`loadVariable`, keyed by SID so both devices of the same plugin instance share the same cache). On each call (unless skipped for night, see the user docs), the script computes the HMAC-SHA256 signature, queries the relevant APsystems endpoint(s), and returns an XML document with one tag per value (`<today>`, `<month>`, `<year>`, `<lifetime>`, `<power>`) — fresh for the relevant mode, cached for the rest, each device/channel reading its own tag via its own XPath. On API error, the affected tags return a negative number (the opposite of the APsystems error code) — stays compatible with decimal-number devices while remaining distinguishable from a real, always-positive value.
- **VAR1-3 limit**: eedomus HTTP sensors only substitute `[VAR1]`-`[VAR3]` into `RAW_URL` (no more), and concatenating several `plugin.parameters.X` tags inside one VAR field proved unreliable in practice (partial or zero substitutions depending on the run). Each VAR carries exactly one tag: `VAR1=APP_ID`, `VAR2=APP_SECRET`, `VAR3=SID`. The APsystems API base URL is hardcoded in the script rather than exposed as a parameter — too rare a need to justify a 4th slot. ECU_ID (optional) has no dedicated VAR either: it rides along in `VAR3`, appended to the SID with a `|` (`SID|ECU_ID`) — reliable because there's still only one tag (`plugin.parameters.SID`) being substituted into that VAR; `aps_solar.php` does the `|` split itself, not eedomus.

See `SESSION_NOTES.md` for the full list of eedomus SDK pitfalls and constraints that shaped these decisions.

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
