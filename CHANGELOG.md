# Changelog

All notable changes to this project will be documented in this file.

## Unreleased
- Documented API rate limiting scope and 429 error shape in docs/api.md.
- Swapped React components to Zustand selector hooks (StarField, Settings, Toolbar) to reduce re-renders.
- Added keyboard navigation guidance to the HUD controls panel.
- Wrapped heavy render layers (InstancedStars, SignalsLayer) in error boundaries.
- Extracted camera offset and star disk segments into named constants to replace magic numbers.

## 2026-01-24
- Escaped tooltip fields to close XSS surface in map-interactive.js.
- Added same-origin validation for configure.php redirects.
- Gated production console logging and fixed StarField response length logging bug.
- Added *.swp to .gitignore.
