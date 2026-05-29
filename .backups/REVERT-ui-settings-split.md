# How to revert the "Settings tab / slim Import" UI batch

This undoes ONLY the most recent UI refactor (the Settings tab + slimmed Import
tab). It keeps all earlier work intact: the duplicate-detection fix
(`_beehiiv_post_id` + `beehiiv_campaign_id` + slug adoption), the atomic
`Support\Lock`, and the preview/per-post-selection feature.

## Steps
1. Restore the previous Import UI:
   - Copy `.backups/Import.prebatch.jsx` over `assets/src/pages/Import.jsx`.
2. Delete the new Settings page:
   - Remove `assets/src/pages/Settings.jsx`.
3. Undo the Settings tab wiring in `assets/src/App.jsx`:
   - Remove the `import Settings from './pages/Settings';` line.
   - Remove the `{ name: 'settings', title: __( 'Settings', 'beehiiv-sync' ) }` tab entry.
   - Remove the `if ( tab.name === 'settings' ) { return <Settings />; }` branch.
4. Undo the stylesheet addition in `assets/src/style.scss`:
   - Remove the `.bs-help-text { ... }` block.
5. Rebuild the bundle:
   - `npm run build`

That returns the admin UI to the "Step 1 / Step 2 + preview table" layout.

## Notes
- `Import.prebatch.jsx` was syntax-checked and matches the state right before
  the UI batch.
- Nothing here touches PHP — the backend (dedup, lock, preview endpoint) is
  unaffected by reverting the UI.
