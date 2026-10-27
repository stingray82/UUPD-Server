# UUPD Server changelog

## 2.2.0 - Next release

These changes are in the current working package. The plugin header remains at 2.1.0 until release.

### Added

- Customer key rotation and child licences through the **UUPD Licence Keys** block, `[uupd_license_keys]` shortcode, and existing account pages. Site and licence settings control customer access; administrators retain their management actions.
- Child keys with notes, activation caps, per-site disconnection, revocation, deletion, and an optional child-count limit. Children share the master's activation allowance and cannot outlive an inactive or expired master.
- A redesigned licence editor and customer key manager with collapsed child rows, search, status and site filters, and shared usage counts when child management is available.
- Optional private interaction history for each licence, capped at 20 entries. The licence editor can show issuance, REST checks, activations, updates, and events supplied by integrations.
- Licence list filters for username, order or invoice reference, expiry date range, and download, with a **Clear filters** button.
- Help articles for defaults, administrator actions, and customer key management.

### Changed

- The Updates list uses **Add Plugin** and **Search Plugins**; the Licences list uses **Add Licence** and **Search Licences**.
- The generic order reference remains available to integrations through `uupd_license_order_reference_html`; UUPD does not depend on FluentCart.
- Disabling child creation revokes existing inheriting children and releases their activations by default, with an option to keep them active.
- Customer purchase tables show only the key actions available for each licence, and empty key-management sections are hidden.

### Fixed

- Rotated keys and child keys cannot pass licence or download checks when their master is unusable.
- A disconnected child's site fails domain-specific checks until reactivated. Key-only checks cannot identify the disconnected site.
- A previous rotation success message no longer persists after a later settings change.

## 2.1.0 - Existing GitHub version, 2026-10-16

- The repository snapshot identifies version 2.1.0 and includes the authenticated release API for publishing Update posts. This is the only prior version verified from the available GitHub history.
- Verified local GitHub commit: [`6e4b194` - V2.1 Release API](https://github.com/stingray82/UUPD-Server/commit/6e4b1940330ec705c7bf23eb8d9e6e2fd5dd3bca).
