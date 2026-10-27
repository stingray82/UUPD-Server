# Set the defaults for key rotation and child licences

UUPD Server can let licence owners replace a key or create child keys. These features are optional and work with licences created before this version.

## Change the site defaults

1. In WordPress admin, open **Settings → UUPD Server**.
2. Under **Key rotation**, choose whether customers may rotate their own keys by default. Administrators can always rotate a key.
3. Under **Child licences**, choose whether customers may create child keys by default. Administrators can still create them.
4. Optionally enter a **Default maximum child keys** number, then save. Leave it blank for automatic sizing.

The defaults are rotation **on** and child creation **off**. The child key maximum is **automatic**: it follows the master's activation limit, or is unlimited when the master has unlimited activations. Enabling child creation therefore does not impose a separate two-key cap. A numeric site maximum is capped by each finite master's activation limit. Licences without an override inherit these defaults.

An administrator can override these settings for one licence in **UUPD Server → Licenses → Edit licence**. Rotation and child creation permissions apply to customers; administrators can manage keys regardless of these choices. The child maximum still applies to everyone. The permission controls offer **Use site default**, **Yes**, and **No**. The child maximum is a free number field; blank inherits the site setting. An unlimited master also offers **Unlimited child keys** beneath that field. Child keys cannot create another generation of keys.

## What each feature does

- **Rotate key** replaces the key on the same licence record. The old key stops validating. The licence owner, product, expiry, activations, and order link stay with that record. A customer must enter the new key on any sites still using the old one.
- **Create child key** makes a subordinate key under a master licence. The child uses the master's owner, product, package, and expiry. It has its own key and activated domains, but uses the master's entitlement when UUPD checks it. You set a positive activation cap for that child and can add a note describing its purpose.

The master's activation limit is shared by the master and every active child. A child cannot exceed its own cap or take the family over the master's total. A child cap is a maximum, not a reserved allocation: other keys may use the shared slots first. The separate child key maximum counts child records, including revoked children. Revoking a child invalidates its key and releases its activations, but it still occupies a child slot. Deleting it permanently frees that slot. Children depend on the master, so suspending, expiring, cancelling, or trashing the master stops them. Renewing the master extends their term without changing their individual caps.

An administrator can override the child key maximum for an individual master in its **Licence key** panel. For example, a master with four activations may allow up to four child records; entering a larger number is capped at four. Revoked children still occupy a child slot until deleted.

Turning child creation off in the site settings revokes existing children of licences that inherit the site permission and releases their activations. Check **Keep existing child keys active** before saving to retain them. Turning it off for one licence has the same default revoke behaviour and its own keep-active checkbox. Keeping children active stops new creation but lets existing children continue to validate while the master remains usable.

## Existing integrations

The existing licence check and activation endpoints continue to accept valid keys. Licensed products using the legacy metadata and download endpoints now also check that the key is currently usable. This means an old, rotated key or a child whose master is inactive cannot be used for a licensed download.

If the product uses a remote download URL hosted elsewhere, UUPD can stop returning that URL to an invalid key, but it cannot revoke a copy of the external URL that someone already has.
