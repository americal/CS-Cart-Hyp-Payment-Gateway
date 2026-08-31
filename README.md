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

**The cardholder's ID**

Shva checks the capture against the ID number it is sent, and a **Direct (debit)
card** is the one that makes this matter: its issuer verifies the ID against the
account the card is drawn on and refuses the charge with `CCode=6` when it does
not match, where an ordinary credit card lets a wrong number through unnoticed.

The payment page does not always collect an ID — when it does not, Hyp still
fills `UserId` in the redirect, with a ten-digit identifier of its own that
belongs to nobody. The authorization keeps what Hyp said, and the capture judges
it: nothing but a real ID reaches Shva, and a hold that has none sends the
documented `000000000` placeholder, which is what the authorization itself was
approved with.

The order page says which of the two happened, so a refused capture explains
itself:

```
Personal ID    000000000 (Original: 1577484600)
```

That line is composed every time the order is read, not stored, so a hold taken
before any of this was understood reads correctly too, and is captured correctly
too — nothing has to be migrated, and no existing order has to be corrected by
hand. Regular J4 charges are untouched: they never send an ID anywhere, so
theirs is printed exactly as Hyp reported it, as it always was.

When the issuer does want the real number, the order page offers a
**Cardholder's ID** field beside the instalments. Fill it in and capture again —
the value is remembered on the authorization, so a further attempt does not need
it retyped. It is held to the same format as any other: nine digits, left-padded
with zeros, the last of them a check digit computed from the other eight, and a
number that fails is answered as it is typed. One that fails is not sent either:
the capture goes out with the placeholder and says so, because the issuer would
refuse it just as surely as Hyp's identifier was refused.

The field appears only when there is something to do about it — no usable ID on
the hold, or a capture already refused over one. A hold that has a real ID needs
no field: the number is printed in the **Payment information** block above, and
retyping it cannot make it any more correct.

**One row for the card**

Hyp reports the brand and the last four digits separately, and `fn_finish_payment`
stores them that way, so the order page used to print `Brand: MasterCard` above
`Credit card: 5956` — two rows saying one thing between them, and neither quite
readable alone. They are composed into a single **Card** row on the way out
(`MasterCard ****5956`), joined by the special card type when Hyp reported one,
so a Direct card is named beside the card rather than further down the page.
Nothing is migrated: an order paid before this reads exactly like one paid after,
and an order paid through another processor is left alone.

**Direct (immediate-debit) cards**

A capture points Shva back at the hold by carrying its authorization number.
An immediate-debit card will not take one: Shva reads the attached number as an
approval obtained by hand and refuses the whole transaction with `CCode=512`,
*cannot enter an approval received from voice response for this transaction* —
even though the request is exactly what the Hyp reference asks for, and the same
request goes through unremarked on an ordinary credit card.

Nothing on the payment page can prevent this. `J5` is fixed when the payment
link is signed, long before a card exists; the card type first appears in the
redirect, as `spType`, by which point the hold has already been taken. So the
addon reads it there — along with `TransType`, `Issuer` and `bincard`, which
ride back in the same redirect — stores it on the authorization, and names it on
the order page, where an `Immediate` card is flagged while the hold is still
open.

The capture then handles the refusal rather than reporting it. On `CCode` 512,
455 or 445 the same request is repeated **without** the authorization number and
the three `inputObj` fields, which is precisely the documented *charge a saved
token* call — so the amount is taken as an ordinary transaction on the card
token that was saved when the hold was made. Then `CancelTrans` is asked to
reverse the hold, which usually it cannot: a hold is captured days after it was
taken, and only the day it was taken is it cancellable. Either way the hold is
never captured again and the issuer releases it when the window expires; the
order page says which of the two happened.

Because that charge is a fresh sale rather than a capture, nothing ties it to
the hold and nothing bounds it by it — so the addon does the bounding. On an
immediate-debit card the amount is pinned to the one the customer approved:
capture is refused, both on the order page and in the capture itself, while the
order total says anything else. Partial captures stay available on an ordinary
credit card, where the charge really is tied to the hold.

The refused capture is kept on the transaction and printed beside the charge, so
an order paid this way explains itself. The fallback is only tried on a definite
refusal — an unreadable answer still locks the row, because a charge that may
have gone through must never be repeated. It can be switched off with **Refused
capture → Charge the saved card when the capture is refused**; with it off the
refusal is reported and nothing is charged.

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

If the [eCom Labs] Additional Order Statuses add-on is installed and active,
each order status the payment method sets on a successful payment gains an
additional-status selector beside it — the ordinary charge included, not only
the two J5 ones:

| Selector | Applied when | Alongside |
|---|---|---|
| **Additional status: successful payment** | an ordinary (J4) charge goes through | *Order status on success* |
| **Additional status: funds held** | Hyp confirms the hold at checkout | *Order status: funds held* |
| **Additional status: captured** | the held amount is charged | *Order status: captured* |

Each writes the chosen status to `?:orders.additional_status` right after the
main order status moves. Left at *do not change*, only the main status moves —
that is the default for all three, so nothing changes until you pick something.

The J4 selector follows the charge, not the return: a failed payment takes the
failure status alone, and the additional status stays where it was.

The hold selector fires only on a genuine authorization. A replayed return — a
refresh, or the back button on an order already captured or cancelled — leaves
the order alone, additional status included, the same way it already leaves the
main status alone.

All three selectors are hidden whenever the add-on is missing or disabled, since
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

Both buttons move the order to the status configured for them **without sending
a single notification** — not to the customer, not to the order department, not
to the vendor. The status change here is bookkeeping that follows money which
has already moved, and the person who moved it is looking straight at the
result, so there is nobody left to inform: an e-mail saying "your order is now
*Cancelled*" minutes after a hold was released is noise at best and alarming at
worst. Every notification receiver is switched off explicitly on the call, so
the store's own notification settings for those statuses are left untouched and
keep working for every other way a status can change.

Checkout is deliberately not part of this. The status the payment return sets —
*funds held*, paid, or failed — is the one that carries the order confirmation
the customer expects, and it still goes out as before.

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

Regular (J4) charges keep their own wording: their *Payment status* is `Success`
or `Failure` plus whatever Hyp said, in English, as it has always been. What they
share with the J5 lines is the repair below.

**A payment status that can be printed**

Apple Pay and Google Pay charges arrived with a blank *Payment status* on the
order page. Everything around it — transaction, brand, last four digits, number
of payments, personal ID — was there and correct, and the label itself was
printed, so nothing looked lost enough to explain it.

The cause is one byte. Hyp answers in UTF-8 while `UTF8out` is on, but not on
every route it takes: a wallet charge sends its `errMsg` in windows-1255, the
encoding the terminal speaks natively, and the add-on appended that to
`🟢 Success` verbatim. CS-Cart runs Smarty with `escape_html` on, so the finished
line is printed through `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` — and that
returns an **empty string**, not a replacement character and not the valid part,
when its input is not valid UTF-8. The row kept rendering because the template
tests the *unescaped* value, which is not empty; only what it said was gone.
Regular card charges send no `errMsg`, which is why they were never affected and
why this looked like a wallet-only fault.

Text from Hyp is now made valid UTF-8 before it goes anywhere near an order:
`errMsg` on the way in, every gateway message that goes through
`fn_hypay_format_error()` (capture and cancel failures, `getToken`, the
notifications and order log lines built from them), and the finished
`payment_info` payload on its way to `fn_finish_payment()`.

A successful charge no longer carries the gateway's note at all. It said nothing
the row did not already say — `אושרה (0)`, Hyp's own way of repeating the
`CCode=0` read a few lines earlier — and it was the single part of that line
written in the terminal's encoding rather than ours. The whole return, that note
included, is in the debug log; the order page gets the verdict. A failure still
explains itself, mostly in the add-on's own words: the code and what this add-on
knows it to mean, plus Hyp's wording when it survived the trip.

The repair is done byte by byte rather than by decoding the whole string, because
the string is a concatenation: `🟢 Success — ` is written here in UTF-8 and only
the tail is legacy. Running the finished line through a windows-1255 decoder
fixes the tail and turns the marker into `נ¢` — so anything that opens a
well-formed UTF-8 sequence is kept exactly as it stands, and only the bytes that
cannot be are looked up as windows-1255. A byte with no meaning in either
encoding is dropped rather than left to blank the line a second time.

Orders paid before this was fixed print correctly too: their stored bytes are
repaired on the way out, in the same pass that re-renders the J5 lines. Nothing
was migrated. Values that are already valid UTF-8 are returned byte for byte, so
an order paid through any other processor is not touched at all.

Some of those orders cannot be repaired, only cleared up. Repair puts back what
the wrong encoding hid; it cannot put back what something upstream had already
thrown away, and a status stored while this was broken can hold a row of
replacement characters — `U+FFFD`, in one case written out literally as
`&#65533;` by whatever escaped it on the way in — where the note used to be. The
letters behind those are gone and no decoder returns them. So a note reduced to
them is dropped from the line rather than printed, and the row reads the plain
`🟢 Success` it was meant to. The verdict itself is never dropped: a damaged line
still beats no line.

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
