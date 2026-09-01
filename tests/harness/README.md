# Dashboard harness

Runs the **real** dashboard JS and CSS against captured API fixtures, with no
firewall, no database and no network. It catches what `php -l`, `node --check`
and `tests/check_plugin.php` cannot: a runtime error in the page, a broken
render, or the JS asking for an endpoint that does not exist.

```sh
# from the repository root — the page loads the plugin's real assets by relative path
python3 -m http.server 8899
open http://127.0.0.1:8899/tests/harness/dashboard.html
```

The page stubs `window.fetch`, answers each `/api/bandwidthd/data/<action>` call
from the matching `<action>.json`, and after ~2.5s appends a
`#bwd-harness-result` block reporting collected errors, which actions were
called, any call that went somewhere other than the data API, and what rendered
(host rows, hero total, cards, charts). A healthy run has `"errors": []` and an
empty `"nonApiCalls"`.

`dashboard.html` is generated from the shipping Volt view with the template tags
stripped, so the markup under test is the markup that ships.

**The fixtures are anonymised.** They were captured from a live box and then
scrubbed: IPs remapped into `192.0.2.0/24` (TEST-NET-1), MACs and hostnames
replaced with stable synthetic values, vendors replaced with placeholder brands.
Traffic volumes and the overall shape are real, which is the point. Do not
replace them with an unscrubbed capture.

`opnsense-main.css` and `opnsense-dark.css` are copies of the firewall's own
theme stylesheets, so the harness shows the page as the GUI actually renders it.
Append `?theme=dark` to load the dark one — the plugin CSS is identical between
them, and anything that needs a dark-specific rule is a bug this switch exists to
expose.

Four font requests 404 here: OPNsense's theme CSS references its Source Sans
files by absolute `/ui/themes/...` path, which only resolves on the box. Layout
falls back to Helvetica/Arial, which is close enough for what the harness checks
and does not affect the `errors` it reports.
