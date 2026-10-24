# Changelog

## 0.2.2

- Add this changelog.

## 0.2.1

- **Self-updater.** A daily check (Services → Game Detect → "Check daily for
  new releases on GitHub", on by default) shows a banner on Status → Game
  Detect when a newer release exists. **Update now** downloads the release
  package, verifies it against the release's `SHA256SUMS`, and installs it with
  `pkg add`. Nothing is installed without an admin clicking the button.
- Removed the `?format=json` data endpoint. Results are shown only on the
  Status page; the page refreshes from an internal feed that carries only what
  the table displays.

## 0.2.0

- First public release.
