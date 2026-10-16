# Native UUPD Server Release API (2.1.0)

This release adds an authenticated endpoint to the **existing UUPD Server plugin** (not Stackless and not the customer-side UUPD Licence UI).

## Endpoint

`POST https://YOUR-UUPD-SERVER/wp-json/uupd/v1/products/{software-slug}/release`

Authentication: WordPress Application Password through HTTP Basic Authentication. User must have `manage_options` and permission to edit the matching Update post. Use HTTPS. The **Update must already exist** in UUPD Server, and its saved `slug` must be an exact match.

### Multipart fields

| Field | Meaning |
|---|---|
| `version` | Required installed/published version |
| `package` | Uploaded ZIP (`multipart/form-data`), **or** `package_url` |
| `package_url` | Alternate HTTPS remote ZIP URL; use instead of `package` |
| `download_mode` | `replace` (default) or `append` |
| `changelog` | Update `changelog_html` (HTML sanitized) |
| `requires_wp` | Update `min_wp_version` |
| `tested_wp` | Update `tested` |
| `requires_php` | Update `min_php_version` |
| `homepage` | Update `homepage` |
| `icon_128_url`, `icon_256_url`, `banner_773_url`, `banner_1544_url` | Optional UUPD update artwork |

`replace` changes the active package list to the newly uploaded ZIP. `append` **prepends** it to `files`, preserving older attachment entries. UUPD's existing metadata and download handler use the first `files` entry as the active package. Neither mode deletes old physical files, makes historical packages available in customer accounts, or changes licensing permissions.

`package_url` writes `remote_url`; the existing UUPD download handler prioritizes that URL. In `append` mode previously uploaded attachments remain stored, but URLs are not separately versioned.

**Important storage note:** Uploaded ZIPs are WordPress Media Library attachments, exactly as the original UUPD Server's admin UI expects. WordPress typically serves uploads over publicly accessible URLs; a signed UUPD download link does **not** protect a directly accessible media URL. Do not rely on this as private storage for sensitive ZIPs. Harden the original UUPD storage/download design before production use with private packages.

## Use with existing deploy.sh v2.6

For a **UUPD Server** deployment, point the script's already-supported Woo-named fields to your UUPD Server. No WooCommerce installation is required:

```ini
DEPLOY_TARGET=woo
WOO_STORE_URL=https://your-uupd-server.example
WOO_SOFTWARE_SLUG=example-plugin
WOO_DOWNLOAD_MODE=append
WOO_CREDENTIALS_FILE=C:/your-private-folder/uupd-credentials.env
```

Credentials file (store outside your Git repository):

```bash
export WOO_USER="your-deploy-username"
export WOO_APP_PASSWORD="application-password-created-on-uupd-server"
```

The script calls the same `/wp-json/uupd/v1/products/{slug}/release` path, so its upload flags work unchanged. Note that `DEPLOY_TARGET=github+woo` is also supported, but the variable names remain Woo-prefixed for compatibility with the existing script.

## Manual request example

```bash
curl --fail-with-body -u "$WOO_USER:$WOO_APP_PASSWORD" \
  -F 'version=1.2.3' -F 'download_mode=append' \
  -F 'package=@example-plugin.zip;type=application/zip' \
  -F 'requires_wp=6.5' -F 'tested_wp=6.8' -F 'requires_php=8.0' \
  --form-string 'changelog=<p>Version 1.2.3</p>' \
  'https://your-uupd-server.example/wp-json/uupd/v1/products/example-plugin/release'
```

## Testing and limits

- Uploaded attachment checks require PHP ZipArchive.
- Check actual zip folder structure before deploying a release.
