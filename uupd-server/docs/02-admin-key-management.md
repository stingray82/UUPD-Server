# Rotate keys and create child licences in WordPress admin

Use the licence editor when you need to manage one customer's key directly.

## Rotate a key

1. Open **UUPD Server → Licenses** and edit the licence.
2. In **Licence key**, find the action beside **Current key**. **Allow customers to rotate** controls only the customer dashboard.
3. If the licence has no key, click **Generate key** and save. If it already has a key, click **Rotate key**. Administrators can do this even when customer rotation is disabled.
4. Copy the new key from the licence editor and give it to the owner through your normal support process.

Rotation changes the key immediately. The previous key no longer passes UUPD checks, so sites using it need the new key. The licence ID stays the same; existing order and customer links that use that ID remain attached.

## Create a child key

1. Edit the master licence.
2. In **Child keys**, set **Allow customers to create child keys** as needed and save. Administrators can create children even when customer creation is disabled.
3. In **Child keys**, enter a positive **Activation cap** and an optional purpose note, then click **Create child key**.
4. Find the new key under the master. The row shows the child's activation use and cap.

The child belongs to the same customer and product, and validation is tied to the master. A child cannot create further children. Its activation cap cannot exceed the master's total activation limit when finite. In **Child keys**, leave **Maximum child keys** blank for the site default or enter a number, such as 2 or 4, and save. The automatic default matches the master activation limit; unlimited masters have an **Unlimited child keys** option beneath the number field. A numeric maximum above a finite master's activation limit is capped at that limit.

Child records are collapsed under the master. Search by key, note, or connected site, then filter by status or whether a site is connected. Open a child to change its cap or note and click **Save child details**. The cap cannot be reduced below the child's current activation count. Each child lists its connected sites; **Disconnect** removes one site's activation from the server, and subsequent checks that send the site's domain fail until it is activated again. Clients that check a key without a domain cannot distinguish a disconnected site. **Revoke child** invalidates the child key and releases all its activated domains. A revoked child still uses a child key slot. **Delete child** removes its record permanently and frees that slot. The summary shows slots used and shared activations used.

Changing **Allow customers to create child keys** from enabled to disabled shows a notice. Existing children are revoked by default; check **Keep existing child keys active instead** before saving if they should continue to work. Existing children always follow the master's status and expiry; when the master is unusable, they cannot validate or activate.

## Suspend or expire a master

Change the master's status or expiry in the usual licence editor. UUPD checks the master whenever a child validates or activates. The child stops working while the master is unusable, even if the child's stored status still says active. Restoring the master restores children that were not individually revoked.

The master's activations and all active children's activations draw from one total. Each child also has its own cap. The child records are managed from the master rather than appearing as separate purchases in the customer list.

If a customer cannot see an action, check the licence's feature choices, status, and child key maximum, then check the defaults under **Settings → UUPD Server**. New child keys appear under the master in the customer key manager.
