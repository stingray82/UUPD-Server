# Let customers manage UUPD licence keys

Customers must be logged in and linked to the licence through its **User** field. They can act only on their own licences.

## Add a key management page

Add the **UUPD Licence Keys** block to a WordPress page, or place this shortcode in a Shortcode block:

```text
[uupd_license_keys]
```

This lists the logged-in customer's licences with their current keys. To display only one licence, use its WordPress licence post ID:

```text
[uupd_license_keys license_id="123"]
```

The UUPD purchase table shows a link named for the available action when the customer can rotate or manage children. The link opens the selected manager below the table. The controls also appear on purchase confirmation and activation pages when available. If neither rotation nor child management is available, the key manager is omitted. A master lists its existing child keys beneath it even when new child creation is disabled, so they can still be managed.

The shared activation and child counters appear only when child management is available. A customer who can only rotate sees the key and rotation action without child-related information.

## What customers can do

- **Rotate key:** Replaces that licence's key. The old key stops working immediately. The customer must update any installation using it.
- **Create child key:** Open the creation panel, enter a positive activation cap and an optional purpose note, then create a key under an active master. The child counter shows used slots against the allowed maximum. Creation stops when the maximum is reached.
- **Manage child:** Open its edit panel to change its cap and note. The child lists connected sites with a **Disconnect** action. Disconnect removes that site's server activation; its next child-key check fails until the site is activated again. Revoking stops the child key and frees all its activations, but still occupies a child slot. Deletion permanently removes the child and frees the slot.

Child keys appear as collapsed rows beneath the master. Search by key, note, or connected site, then filter by status or site connection to find a child. Open its row to copy the key or use its management controls.

Buttons are hidden when the action is disabled or the licence is not usable. UUPD verifies ownership and a security nonce again when the button is submitted; hiding a button alone does not grant or remove access.

If a child key stops working, check its own status and cap, the master's status and expiry, and the family's total activation use. All active keys share the master's total allowance. Child keys appear under their master, not as separate purchases. The automatic child maximum matches a finite master's activation limit; unlimited masters have no default child count cap. A store owner can set a smaller site or licence maximum. Disabling child creation revokes existing children by default unless the store owner chooses to keep them active.
