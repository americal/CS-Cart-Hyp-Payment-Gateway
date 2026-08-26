# Hypay Payment Gateway for CS-Cart

Open-source payment gateway integration for the **Hyp** payment system, supporting **credit card payments**, implemented for the **CS-Cart** e-commerce platform.

This addon enables merchants to process online credit card payments via Hyp using the official API.

---

## 📌 Overview

- Open-source payment gateway integration for **Hyp (Israel)**
- Designed for the **CS-Cart** platform
- Supports secure payment flow and order processing
- Implemented according to the official Hyp API specification

👉 Official API documentation:  
https://hypay.docs.apiary.io/

---

## ⚙️ Features

- Secure payment request generation
- Sandbox (test) and production modes
- Configurable merchant credentials
- Support for authorization and capture flows
- Compatible with CS-Cart payment processor architecture

---

## 🧩 Requirements

- CS-Cart (compatible version required)
- Active Hyp merchant account
- Merchant credentials issued by Hyp

---

## 🚀 Installation

1. Download or clone this repository
2. Install the addon via the CS-Cart Add-ons manager
3. Create a new payment method
4. Select **Hypay** as the payment processor
5. Configure merchant credentials and addon settings

---

## 📄 Configuration

The addon provides configuration options for:

- Merchant ID
- API key
- Sandbox / Production mode
- Order ID prefix
- Authorization / capture behavior
- EzCount document line items — itemized products or the order number alone,
  set separately for regular checkout and for J5 captures
- Additional order status to set when a J5 hold is confirmed and when it is
  captured (needs the eCom Labs add-on)
- Additional Hyp-specific parameters

For detailed API behavior, refer to the official Hyp documentation.

---

## 🔒 J5 — two-phase commits (hold now, charge later)

The addon can hold the customer's funds instead of charging them, and charge the
held amount later from the order page. This follows the Hyp two-phase commit
flow: [developers.hyp.co.il](https://developers.hyp.co.il/pay/advanced-features/two-phase-commits).

**How it works**

1. **Authorization (J5).** The payment page is opened with `J5=True&MoreData=True`.
   Hyp returns `CCode=700` together with `Id`, `ACode`, `UserId` and `UID`, and the
   money is only blocked on the card. No document is issued at this point.
2. **Card token.** Right after the authorization the addon calls
   `action=getToken&TransId=<Id>` and stores `Token` / `Tokef`.
3. **Capture (J4).** From the order page the merchant charges the held amount with
   `action=soft` (`Token=True`, `CC=<Token>`, `AuthNum=<ACode>`,
   `inputObj.originalAmount`, `inputObj.originalUid`). The EzCount document is
   created at this moment, for the amount that was actually charged.

**What the document says**

The EzCount (Direct API) settings **Document line items** decide how a document
is filled in. Either choice is available:

- `List products` (default) — a line per product, plus shipping, payment
  surcharge, discounts, redeemed gift certificates and a rounding adjustment so
  the lines add up to the order total exactly;
- `Order number only` — a single line naming the order, priced at the order
  total. That line always reads `Order #1234`, on a Hebrew order too: the
  wording stays the same everywhere so the documents can be reconciled against
  order numbers without minding the language they were issued in.

There are two of these settings, because the two documents are not really the
same document. **Regular deals** covers the receipt issued the moment a customer
pays at checkout. **J5 (after capture)** covers the one issued when the held
amount is charged — days later, from the order page, often for a dealer rather
than a walk-up customer, and by then the order may have been edited.

The J5 setting starts at *same as regular deals* and follows the one above until
it is set to something of its own, so an installation that configured a single
mode before the split keeps issuing exactly what it issued before.

**Per-usergroup behaviour**

The payment method setting **Payment type** offers:

- `Regular charge (J4)` — current behaviour, the card is charged at checkout;
- `Hold funds (J5) for everyone`;
- `Hold funds (J5) by usergroup` — customers of the selected usergroups (e.g.
  `Dealer` / `Shop`) get a J5 hold, everybody else pays as before.

**Reset selected** under the usergroup list clears the whole selection in one
click, which beats ctrl-clicking entries loose one at a time. It only changes
the form — the method still has to be saved — and an empty list under this
payment type means nobody gets a hold at all: every customer is charged at
checkout, as if the type were `Regular charge (J4)`.

**Additional statuses**

If the [eCom Labs] Additional Order Statuses add-on is installed and active, each
of the two J5 order statuses gains an additional-status selector beside it:

| Selector | Applied when | Alongside |
|---|---|---|
| **Additional status: funds held** | Hyp confirms the hold at checkout | *Order status: funds held* |
| **Additional status: captured** | the held amount is charged | *Order status: captured* |

Each writes the chosen status to `?:orders.additional_status` right after the
main order status moves. Left at *do not change*, only the main status moves —
that is the default for both, so nothing changes until you pick something.

The hold selector fires only on a genuine authorization. A replayed return — a
refresh, or the back button on an order already captured or cancelled — leaves
the order alone, additional status included, the same way it already leaves the
main status alone.

Both selectors are hidden whenever the add-on is missing or disabled, since
there would be no column to write to. Choices made earlier are not lost in the
meantime: they stay stored, hidden, and start working again the moment the
add-on is switched back on. A status deleted after the fact is skipped rather
than written, with the reason recorded in the log.

**On the order page**

The *J5 hold* block shows the authorized amount, the authorization number, the
capture deadline and the current state, plus two buttons:

- **Capture** — charges the current **order total**. To charge less, edit the order
  first: the capture amount must equal the order total, otherwise the operation is
  rejected so the EzCount document can never disagree with the money taken.
  Capturing more than the authorized amount is rejected as well.
- **Cancel hold** — the authorization is abandoned and never captured; the issuer
  releases the funds when the authorization window (about 5 days) expires. Hyp does
  not document a server-to-server release call, so no request is sent for this.

**Payment information language**

The *Payment status* and *J5 hold* lines follow whoever is reading them, not
whoever paid. CS-Cart stores payment info as finished strings, and the language
that produced them is the customer's — so a Hebrew storefront would hand a
Russian-speaking admin Hebrew payment lines forever. Everything those lines say
is also in `?:hypay_transactions`, so they are composed again from the
transaction on the way out, in the language the reader is using.

On the order details page that happens in the add-on's own `orders` controller,
which has the finished `order_info` the templates are about to render. The
`get_order_info_post` hook does the same for every other order read, but it
cannot be the only pass: whether `payment_info` is already attached when that
hook fires is not the add-on's to decide, and on the details page it is not.

This fixes orders that were already paid for, too: nothing was migrated, the
text is simply no longer read back verbatim. The stored strings stay where they
are and remain the fallback for a J5 order whose transaction row is gone, and
for the `capturing` state, where the text written at that moment is the only
account of what happened.

Regular (J4) charges are unaffected: their *Payment status* is `Success` or
`Failure` plus whatever Hyp said, in English, as it has always been.

**Said once, not twice**

On the order details page the *J5 hold* row is dropped from payment info,
because the panel right below it already prints the same hold and prints it
better: the amount goes through the store's price format, the deadline through
its date format, and an expired hold is called out in red — none of which a flat
line of text can do.

The row is only dropped where that panel actually renders. On the order list,
printable documents and the storefront it stays, since there is nothing else
there to say the order is holding money.

The two long hints under the Capture / Cancel hold buttons are down to one line
each, with the full wording moved onto an `i` marker beside them — hover it and
the whole explanation appears. Nothing was cut, only folded away.

The panel's *UID* row now appears only while the payment method has debug mode
on. It is what a stuck capture gets diagnosed with, which is exactly when debug
mode is on anyway; the rest of the time it was a long opaque string occupying a
row of its own.

**Data**

Authorizations and captures are stored in `?:hypay_transactions` (kept on uninstall).


---

## 🔒 3-D Secure (3DS)

**There is no 3DS setting in this add-on, for J5 or for anything else — by design.**

3DS is switched on per *terminal*, in the Hyp Pay account, and Hyp only passes the
authentication through between the card issuer and the acquirer. Once it is active
on a terminal, no request parameter turns it on or off: every transaction sent to
that terminal — J5 authorization, J4 charge, capture — follows whatever the
terminal is configured for. A setting here would be a switch wired to nothing.

Configure it in the Hyp Pay account of a **production** terminal (3DS is not
available on test terminals), under הגדרות (Settings) → 3DS עסקה בטוחה:

- פרטי עסקה בטוחה — merchant name in English (no spaces, up to 10 characters),
  website domain, country code `376` for Israel, and the size of the OTP prompt;
- הגדרות — per card brand: MID (10 digits starting with `972` for American
  Express), acquirer, MCC, and both ישראל and תייר card types enabled.

Two of those settings are the closest thing to the per-deal control a J5 toggle
would have given:

- a **minimum amount per currency** — 3DS only kicks in above it, so small holds
  can skip the challenge;
- the **fallback** checkbox — lets a transaction through without 3DS when the 3DS
  system is temporarily unreachable.

To run J5 with 3DS and regular checkout without it (or the other way round), the
only real option is a second terminal configured differently, since the split has
to happen at the terminal.

**Reading the result.** J5 authorizations always go out with `MoreData=True`, so
Hyp returns the `ECI` code on the redirect. It says whether the acquirer carries
the chargeback liability:

| ECI (Visa / Mastercard) | Meaning | Chargeback protection |
|---|---|---|
| `05` / `02` | Fully authenticated — the customer passed the challenge | Per the acquirer's rules |
| `06` / `01` | Attempted — 3DS started, issuer or card does not support it | Per the acquirer's rules |
| `07` / `00` | Failed — verification did not happen or did not pass | None |

The add-on does not store `ECI` today; enable debug mode to see the full return in
`var/log/hypay_ezcount.log`.

See [3-D Secure on developers.hyp.co.il](https://developers.hyp.co.il/pay/advanced-features/3-d-secure)
for the full setup guide.

---

## ⚠️ Disclaimer

This software is provided **AS IS**, without warranty of any kind.

- No guarantees of correctness or suitability for any purpose
- No obligation for maintenance, updates, or support
- Use at your own risk

This project is **not an official Hyp product** and is not affiliated with Hyp, except for using their publicly available API.

---

## 📜 License

This project is licensed under the **GNU General Public License v3.0 (GPL-3.0)**.  
See the `LICENSE` file for details.

---

## 🤝 Contributions

Issues, pull requests, and suggestions are welcome.  
However, review and acceptance are not guaranteed.
