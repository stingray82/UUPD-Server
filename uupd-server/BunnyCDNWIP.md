## Changes

1. **Remote downloads stay behind a signed gateway.** The gateway signs `slug|remote:SHA256(remote_url)|expires`; validates the HMAC, expiry and licence/domain **before** redirecting. Local attachment signatures remain `slug|file_id|expires` for compatibility.
2. **Gateway link validity is now 24 hours** rather than one hour. 
3. **Licence domain enforcement** for both licensed metadata and downloads: checks `used_domains` for the requested `domain` after validating the licence. Missing or unregistered domains cannot obtain a licensed ZIP. Matching uses canonical host and optional port; this preserves the REST activation data but should be tested for any sites that register paths or alternate hostnames.
4. **Release publish lock** now uses atomic `add_option()` rather than read-then-write transients. If PHP crashes mid-publish, the lock intentionally remains until an administrator clears it; identify with `uupd_release_lock_<update post ID>` in `wp_options`, then inspect the incomplete release before removing. **This is not a transactional/atomic multi-meta publish**; readers might still observe intermediate state during publish. Use a maintenance window for large releases until snapshot-based publishing is implemented.
5. **Optional CDN delivery:** `legacy` (default), `bunny` (signed pull-zone URL), and `cloudflare_r2` (externally supplied S3 SigV4 presigned GET link). These operate at *download time*, after entitlement validation. Metadata still returns the signed UUPD gateway URL, not the CDN origin.
6. **Optional Nginx local offload** via `X-Accel-Redirect`, keeping local ZIPs off PHP streaming for matching protected file paths.

## Config in `wp-config.php` (BEFORE WordPress bootstrap)

### Default behavior (no settings)

`legacy` continues to redirect to the product's configured HTTPS `remote_url`. The gateway requires the signed token, but the remote URL itself is not private if anyone already knows it. Local files continue to stream through PHP.

### Bunny CDN token authentication

```php
define( 'UUPD_CDN_MODE', 'bunny' );
define( 'UUPD_BUNNY_HOST', 'downloads.example.com' );
define( 'UUPD_BUNNY_TOKEN_KEY', getenv( 'UUPD_BUNNY_TOKEN_KEY' ) );
```

Each update's `remote_url` should be an HTTPS path on that **exact** Bunny pull-zone host, with no existing query string. Enable **Bunny token authentication** at the pull zone using the same key; protect the origin, or a direct origin URL can bypass the CDN. Signed CDN redirects expire in 10 minutes. **A gateway redirect is still validated even if the CDN URL is cached elsewhere.** Never embed the signing key in frontend JS or repository code. Check Bunny's current token-authentication settings/format when configuring your pull zone.

### Cloudflare R2 private bucket

```php
define( 'UUPD_CDN_MODE', 'cloudflare_r2' );
add_filter( 'uupd/server/r2_presigned_get_url', function ( $empty, $remote_url, $slug, $product_id, $ttl_seconds ) {
    // Use the AWS SDK for PHP or another verified S3 SigV4 implementation to
    // return a complete presigned HTTPS GET URL for a PRIVATE R2 object.
    // DO NOT return the public $remote_url, or fabricate a signature.
    return ''; // Fails closed (503) until signer is implemented.
}, 10, 5 );
```

Supply the filter from a small must-use plugin or server-only bootstrap file. Do not put credentials in customer plugins. R2 S3 presigned links use the `*.r2.cloudflarestorage.com` API endpoint, not an arbitrary custom domain; a Cloudflare Worker/access-controlled service is another possible integration but is **not** included in this build. Cloudflare standard CDN cache on a public custom domain alone does not make ZIPs private. The default unsigned mode is not a secure substitute for a private origin.

### Local ZIP Nginx offload (optional)

```php
define( 'UUPD_X_ACCEL_ROOT', '/srv/wordpress/private-uupd-zips' );
define( 'UUPD_X_ACCEL_URI', '/_uupd_private/' );
```

Configure the matching Nginx internal location, for example:

```nginx
location /_uupd_private/ {
    internal;
    alias /srv/wordpress/private-uupd-zips/;
}
```

Only local attachment paths **within** the configured real directory are offloaded. Other paths fall back to PHP streaming. Files in regular public WordPress uploads may still be accessible directly; move sensitive ZIPs to protected storage and validate your WordPress attachment management workflow first.

## Known limitations / security caveats

- No generic Cloudflare CDN signed-URL implementation is claimed. Cloudflare R2 **requires a real SigV4 signer** provided through the filter.
