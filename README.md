# Tinylist_Dropshipping (Magento 1)

Magento 1 connector module for the Tinylist dropshipping platform. It bundles
five things into one module:

1. **API role** — a `Tinylist Connector` SOAP/XML-RPC role, created on install
   with a curated, read-focused resource set.
2. **Payment method** — an offline `Tinylist Dropshipping` method, admin-only.
3. **Shipping method** — a zero-cost `tinylist_freeshipping` carrier, available
   to the API and the admin only, never on the storefront.
4. **Invoice PDF SOAP method** — return an order's invoice as a base64 PDF.
5. **Carrier tracking status SOAP method** — resolve an `(order, tracking)`
   pair to a live carrier status (Sameday first; pluggable for more carriers).

Target platform: **Magento 1.9 CE / OpenMage LTS** (Blugento). Deployable with
[modman](https://github.com/colinmollenhour/modman).

---

## Install

```bash
# from the Magento root
modman init                      # once, if this store has no .modman yet
modman link /path/to/module-m1   # or: modman clone <repo-url>
```

`app/etc/modules/Tinylist_Dropshipping.xml` and
`app/code/community/Tinylist/Dropshipping` are symlinked into place; the
`install-1.0.0.php` setup script runs automatically on the next request. Clear
the cache (`var/cache`) afterwards so the config/API/WSDL merges pick up.

**Uninstall / reinstall note:** the setup script only creates the API role if a
role with the same name does not already exist, so re-running setup is safe.
Removing the module does not delete the role (Magento never auto-drops data);
delete it by hand under *System → Web Services → SOAP/XML-RPC - Roles* if needed.

---

## 1. API role

The setup script creates a SOAP/XML-RPC **role** named `Tinylist Connector`
(idempotent — skipped if the name already exists) granting exactly the resources
checked in the reference tree:

| Group             | Granted (read-focused)                                                                                                                                                   |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Cart              | full cart flow: create, order, info, totals, license, product add/update/remove/list, customer set/addresses, shipping method/list, payment method/list                 |
| Core              | store list, Magento info                                                                                                                                                 |
| Catalog           | category tree/info; product info; attribute read/types/info/option, attribute set list; product link; product media; product tags list/info; custom options + values     |
| Sales (read only) | order info; shipment info; invoice info; credit-memo info + list                                                                                                         |
| Catalog Inventory | stock info                                                                                                                                                               |
| Directory         | country, region                                                                                                                                                          |

Write operations (create/update/delete/change/capture/…) are deliberately **not**
granted. The full resource-id list lives in one readable array in
`sql/tinylist_dropshipping_setup/install-1.0.0.php`; parent container resources
are included to mirror the reference role exactly — prune to leaf resources for
strict least-privilege.

It does **not** create an API *user*. Create one under **System → Web Services →
SOAP/XML-RPC - Users**, assign it the `Tinylist Connector` role, and copy the
generated API Key into Tinylist's merchant settings (`apiUser` + `apiKey`).

---

## 2. Payment method — "Tinylist Dropshipping"

- **Disabled by default** (`payment/tinylist_dropshipping/active = 0`).
- When enabled: available to **admins** creating orders (`_canUseInternal`),
  **hidden on the storefront** (`_canUseCheckout = false`) and multishipping.
- **New Order Status**: a config dropdown of *processing*-state statuses. Placed
  orders are moved straight into that status via the method's `initialize()`.

Configure under **System → Configuration → Sales → Payment Methods → Tinylist
Dropshipping** (Enabled / Title / New Order Status / Sort Order).

---

## 3. Shipping method — "Tinylist Dropshipping"

Rate code **`tinylist_freeshipping`** — this is the value to put in Tinylist's
merchant settings as the placement shipping method.

- **Disabled by default** (`carriers/tinylist/active = 0`).
- When enabled: returned to **SOAP/XML-RPC API** calls and to the **admin**
  order-create screen; **never** to a storefront quote.
- **Always priced 0.00.** Not configurable, on purpose — see below.

Configure under **System → Configuration → Sales → Shipping Methods → Tinylist
Dropshipping** (Enabled / Title / Method Name / Sort Order).

### Why it exists

Order placement needs a shipping method that always resolves. A real carrier
does not: a table rate declines whenever the destination has no matching row or
the subtotal is under its lowest threshold, and the SOAP API reports that as a
bare `Shipping method is not available` (fault 1062) that names neither cause.

### Why it is hidden from the storefront

Magento 1 has no carrier equivalent of the payment method's `_canUseCheckout`,
so the rule lives in `collectRates()` (`Model/Carrier/Freeshipping.php`): a rate
is returned only when `Mage::app()->getStore()->isAdmin()` or the request's
module name is `api`. A storefront checkout reports `checkout` and gets nothing,
which is what stops every buyer choosing free delivery.

> **If the store serves the SOAP API under a rewritten front name** (not
> `/api/…`), update `API_MODULE_NAME` in `Model/Carrier/Freeshipping.php` or the
> carrier will go silent for placement.

### Why the price is not configurable

Tinylist charges the buyer no shipping, and Magento reprices every placed order
from its own catalogue. A non-zero placement rate would therefore have the
courier collect items + shipping as cash on delivery from a buyer who agreed to
neither. A config field is exactly how that gets reintroduced.

---

## 4. Carrier tracking status

**System → Configuration → General → Tinylist Dropshipping → Carrier Tracking
Status → Enabled Carriers** — a multiselect over the fixed provider set. Only
enabled carriers are contacted; a disabled one returns a friendly
`carrier_not_enabled` message. Sameday is enabled by default.

Given an `(orderIncrementId, trackNumber)` pair, the dispatcher
(`Model/Carrier/Status.php`):

1. finds the matching Magento shipment track in the DB
   (`sales_flat_shipment_track`, via `order->getTracksCollection()`),
2. resolves its `carrier_code` to an **enabled** provider,
3. calls that carrier's API and returns a normalized JSON envelope.

### Sameday provider

`Model/Carrier/Status/Sameday.php` reuses the `Blugento_SamedayCourier` module's
stored credentials (`carriers/bgsamedaycourier/{username,password,test_mode}`)
and calls the Sameday eAWB API directly — the Sameday module exposes no status
method and is **left untouched**:

```
POST /api/authenticate                 X-AUTH-USERNAME / X-AUTH-PASSWORD  -> token
GET  /api/client/awb/{awb}/status       X-AUTH-TOKEN
```

Handles both `bgsamedaycourier` and `bgsamedayeasybox` carrier codes. Test mode
follows the Sameday module's own `test_mode` flag (demo host vs production).

### Adding the next carriers

1. Add a provider class extending
   `Tinylist_Dropshipping_Model_Carrier_Status_Abstract` — implement `fetch()`
   and return the normalized shape documented in that class.
2. Register it under `global/tinylist_dropshipping/carrier_status_providers` in
   `config.xml` with a `<label>`, `<model>`, and the `<carrier_codes>` it owns.
3. It automatically appears in the Enabled Carriers switch — no dispatcher, API,
   or WSDL changes needed.

---

## API documentation

Both methods are exposed on the **SOAP v2** endpoint and merged into the WSDL
served at `/api/v2_soap/?wsdl=1`. Both dispatch modes are covered:
`etc/wsdl.xml` (default RPC/encoded) and `etc/wsi.xml` (WS-I document/literal,
used when *System → Configuration → Services → Magento Core API → WS-I
Compliance* is **Yes**). No config change is required to call them once the
module is installed and the cache cleared.

Authentication is the standard Magento API handshake: `login(apiUser, apiKey)`
returns a `sessionId` passed as the first argument to every call. The calling
user's role must grant the ACL resource listed per method — the `Tinylist
Connector` role (section 1) already does.

### Method: `tinylistDropshippingOrderInvoicePdf`

Return an order's invoice rendered as a base64-encoded PDF.

| | |
| ---------------- | ------------------------------------------------------ |
| **ACL resource** | `sales/order/invoice/info` |
| **Signature**    | `tinylistDropshippingOrderInvoicePdf(string sessionId, string orderIncrementId) : string` |

**Parameters**

| Name               | Type   | Description                                   |
| ------------------ | ------ | --------------------------------------------- |
| `orderIncrementId` | string | Order increment id, e.g. `100000123`.         |

**Returns** — a `string`:

- Base64-encoded PDF of the order's invoice(s) when the order has one or more
  invoices. Decode and write to a `.pdf` file.
- **Empty string** (`""`) when the order exists but has no invoice yet.

**Faults**

| Code | Name                 | When                              |
| ---- | -------------------- | --------------------------------- |
| 101  | `order_not_exists`   | No order with that increment id.  |
| 102  | `invoice_pdf_failed` | PDF rendering raised an error.    |

**Example (PHP SoapClient, v2)**

```php
$client  = new SoapClient('https://store/api/v2_soap/?wsdl=1');
$session = $client->login('apiUser', 'apiKey');

$b64 = $client->tinylistDropshippingOrderInvoicePdf($session, '100000123');
if ($b64 !== '') {
    file_put_contents('invoice.pdf', base64_decode($b64));
} else {
    // order has no invoice yet
}
```

### Method: `tinylistDropshippingOrderTrackingStatus`

Fetch the live carrier status for an `(order, tracking)` pair.

| | |
| ---------------- | ------------------------------------------------------ |
| **ACL resource** | `sales/order/info` |
| **Signature**    | `tinylistDropshippingOrderTrackingStatus(string sessionId, string orderIncrementId, string trackNumber) : string` |

**Parameters**

| Name               | Type   | Description                                                     |
| ------------------ | ------ | -------------------------------------------------------------- |
| `orderIncrementId` | string | Order increment id, e.g. `100000123`.                          |
| `trackNumber`      | string | Tracking / AWB number as stored on the order's shipment track. |

**Returns** — a **JSON string** (a stable contract as more carriers are added).
Parse with `json_decode`. This method does **not** raise SOAP faults for
expected outcomes; failures come back in-band with `success:false`.

Success envelope:

```json
{
  "success": true,
  "order_increment_id": "100000123",
  "track_number": "2ROBG1234567",
  "carrier_code": "bgsamedaycourier",
  "carrier": "sameday",
  "status": "in_transit",
  "delivered": false,
  "canceled": false,
  "delivery_attempts": 0,
  "delivered_at": null,
  "history": [
    {
      "code": 5,
      "status": "in_transit",
      "label": "AWB in transit",
      "state": "...",
      "date": "2026-07-08 14:21:00",
      "county": "Cluj",
      "reason": "",
      "transit_location": "Cluj-Napoca"
    }
  ]
}
```

Error envelope:

```json
{
  "success": false,
  "order_increment_id": "100000123",
  "track_number": "BADAWB",
  "error": "The Sameday Courier shipping module is not installed or is disabled on this store.",
  "error_code": "carrier_module_unavailable"
}
```

**Response fields**

| Field                | Type          | Notes                                                             |
| -------------------- | ------------- | ----------------------------------------------------------------- |
| `success`            | bool          | `true` on a resolved status, `false` otherwise.                   |
| `order_increment_id` | string        | Echoed back.                                                      |
| `track_number`       | string        | Echoed back.                                                      |
| `carrier_code`       | string        | Magento carrier code from the matched track (success only).       |
| `carrier`            | string        | Provider key, e.g. `sameday` (success only).                      |
| `status`             | string        | Current normalized status name (success only).                    |
| `delivered`          | bool          | Whether the carrier reports delivery (success only).              |
| `canceled`           | bool          | Whether the shipment is canceled (success only).                  |
| `delivery_attempts`  | int \| null   | Delivery attempts reported by the carrier (success only).         |
| `delivered_at`       | string \| null| Delivery timestamp if available (success only).                   |
| `history`            | array         | Chronological status entries (success only); each entry has `code, status, label, state, date, county, reason, transit_location`. |
| `error`              | string        | Human-friendly message (error only).                              |
| `error_code`         | string        | Machine-readable code (error only) — see below.                   |

**`error_code` values**

| Code                          | Meaning                                                           |
| ----------------------------- | ----------------------------------------------------------------- |
| `missing_parameters`          | Order number or tracking number was empty.                        |
| `order_not_found`             | No order with that increment id.                                  |
| `tracking_not_found`          | That tracking number is not on any of the order's shipments.      |
| `carrier_not_enabled`         | The order's carrier is not in the Enabled Carriers switch.        |
| `carrier_module_unavailable`  | The carrier's shipping module is missing or disabled.             |
| `carrier_not_configured`      | The carrier module has no API credentials configured.            |
| `carrier_auth_failed`         | Authentication with the carrier API failed.                       |
| `carrier_unreachable`         | Network/transport error reaching the carrier API.                 |
| `carrier_status_unavailable`  | The carrier has no status for that AWB yet.                       |
| `provider_invalid`            | Misconfigured provider mapping (should not happen in normal use). |
| `unexpected_error`            | Anything else; details are logged server-side.                    |

**Example (PHP SoapClient, v2)**

```php
$client  = new SoapClient('https://store/api/v2_soap/?wsdl=1');
$session = $client->login('apiUser', 'apiKey');

$json   = $client->tinylistDropshippingOrderTrackingStatus($session, '100000123', '2ROBG1234567');
$result = json_decode($json, true);

if ($result['success']) {
    echo $result['status'];              // e.g. "in_transit"
} else {
    echo $result['error'];               // friendly message
    echo $result['error_code'];          // e.g. "carrier_not_configured"
}
```

---

## Layout

```
modman
README.md
app/etc/modules/Tinylist_Dropshipping.xml
app/code/community/Tinylist/Dropshipping/
  etc/
    config.xml                                 # module, models, payment + carrier defaults, provider registry, admin ACL
    system.xml                                 # payment method + shipping carrier + carrier-status admin config
    api.xml                                    # SOAP resources, methods, v2 prefixes, faults
    wsdl.xml                                   # SOAP v2 (RPC/encoded) operations
    wsi.xml                                    # SOAP v2 WS-I (document/literal) operations
  Helper/Data.php
  Model/
    Payment/Method/Dropshipping.php            # admin-only offline payment method
    Carrier/Freeshipping.php                   # API/admin-only zero-cost carrier (tinylist_freeshipping)
    Order/Invoice/Api.php                      # invoice PDF resource (v1)
    Order/Invoice/Api/V2.php                   # invoice PDF resource (v2)
    Order/Tracking/Api.php                     # tracking status resource (v1)
    Order/Tracking/Api/V2.php                  # tracking status resource (v2)
    Carrier/Status.php                         # dispatcher: DB lookup + provider routing + envelope
    Carrier/Status/Abstract.php                # provider base class
    Carrier/Status/Sameday.php                 # Sameday provider (auth + status + normalize)
    Carrier/Status/Exception.php               # friendly, caller-facing error
    System/Config/Source/Carrier.php           # "Enabled Carriers" options
  sql/tinylist_dropshipping_setup/
    install-1.0.0.php                          # creates the "Tinylist Connector" API role
```

## Notes / limitations

- **Tracking status returns a JSON string**, not a typed SOAP struct — a
  deliberate choice so the contract stays stable across carriers with different
  native payloads. Callers `json_decode` the result.
- `catalog/product/tag` resources exist in Magento 1.9 CE (Blugento) but were
  removed in OpenMage; an inert rule row there is harmless.
- Not runtime-tested against a live store here — verification was PHP lint + XML
  well-formedness, with the Sameday endpoint/response shape confirmed against
  Sameday's official PHP SDK.
