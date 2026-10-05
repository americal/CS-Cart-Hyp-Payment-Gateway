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
- Payment links sent to the customer by SMS / e-mail from the order page
- A customer who leaves the payment page without paying (back button, cancel,
  declined card) is shown a popup that the order was **not** placed and must be
  placed and paid again, instead of the "thank you" page. Pressing "Place
  order" again for an order still left unpaid opens the payment form for it
  rather than the "thank you" page, and the cart keeps its products until the
  payment actually goes through
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
- Which of the buyer's EzCount details travel onto the document — see below

For detailed API behavior, refer to the official Hyp documentation.

---

## 🧾 EzCount — who the document is made out to

A document names its customer by the buyer's CS-Cart profile. Three of those
fields are EzCount's rather than CS-Cart's, and they live where the
[EzCount Invoice Generator](https://github.com/americal/EzCount-Doc-Generator)
add-on keeps them, so a document issued here and a document issued there name
the same customer the same way:

| On the document | Profile field | Sent as |
| --- | --- | --- |
| EzCount name | **Fax** | `customer_name` |
| VAT ID | **URL** | `customer_crn` |
| CC e-mail | a custom field, `ezcount_additional_email` by default | `cc_emails` |

Each is off until it is switched on, so an installation that issued documents
before these settings existed keeps issuing exactly the same ones. The name
falls back to the profile name: a customer who never filled the EzCount name
field in is still named on the document rather than not at all.

A fourth setting, **Customer card**, sends `customerAction=ASSOC_ONLY` — EzCount
then files the document under a customer card that already matches instead of
creating a new one.

The custom field holding the CC e-mail is found by the name the EzCount Invoice
Generator gives it, so neither add-on has to be told about it twice. An
installation whose field is named something else can name its ID outright in
**CC e-mail profile field ID**.

**Integrated mode is not the same set.** In `direct` mode this add-on issues the
document itself and can send all four. In `integrated` mode Hyp issues it, and a
payment request carries only two of them: the EzCount name (as `ClientName`, in
place of both halves of the profile name — it is a whole name, usually the
company's) and the VAT ID (as `UserId`, the number Hyp files the document
under). They are configured separately from the direct ones, under
**EzCount (Integrated)**.

The VAT ID is deliberately never sent with a J5 authorization: `UserId` there is
the cardholder's ID, which Shva checks the later capture against — a company
number is not that — and a hold issues no document to put it on anyway.

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

**A new authorization number for a refused capture**

The capture sends Shva the authorization number the hold came back with. When
the credit company refuses that capture, they can sometimes issue a fresh
approval number over the phone — re-sending the one they already turned down
would only be refused again. So once a capture has failed, the order page offers
a **New authorization number** field beside the instalments. Type the number the
credit company gave you and capture again; it replaces the one on the hold and is
remembered, so a further attempt does not need it retyped. Leaving it empty keeps
the current number (shown as the field's placeholder). Digits only, and anything
that does not clean down to a number is refused rather than sent.

The field appears only after a capture has actually been refused — before the
first attempt there is nothing to re-authorize, and the number that came back
with the hold is the one to use.

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

## 🔗 Payment links (pay by SMS / e-mail)

**Settings.** A link is not tied to the order's payment method — it can be
sent for an order placed with bank transfer, by phone, with anything — so its
settings are the add-on's own: **Add-ons → Hypay → Settings → Payment links**,
the same for every link. They are:

| Setting | What it does |
|---|---|
| *Payment method (terminal) for payment links* | The Hypay payment method every new link goes through: its terminal and `PassP`, its EzCount and payment page settings. A disabled method can be chosen too — a terminal kept for links alone. Left on *the order's own Hypay method, otherwise the first active one*, it works as before. A link already created keeps the method it was made with; the link window names it (*Goes through: … (terminal …)*). |
| *Link lifetime, days* | See **Link lifetime** below. |
| *Document after a payment link is paid* | 320, 400 or none for every link, or *as the payment method sets* (that method's own EzCount setting). |
| *Customer's orders offered in the link* | See **Several orders, one link** below. |
| *Order status after the link is paid* | The status every order of a paid link moves to; *as the payment method sets* = the method's success status. |
| *Additional status after the link is paid* | The additional status every order of a paid link gets; *as the payment method sets* = the method's success additional status. |
| *Order status / Additional status after a J5 link is held* | The same for a J5 (hold only) link; by default the method's J5 statuses. |
| *Additional status after the link is created / cancelled* | See **Additional statuses** below. |

The additional status settings need the eCom Labs *Additional Order Statuses*
add-on; without it they offer nothing to choose.

These used to be settings of each Hypay payment method, which left it unclear
whose settings a link went by when there were several. CS-Cart reads an
add-on's settings from `addon.xml` only when it is installed, so on an
installation updated from an earlier version they are added the first time an
admin page (the order list, an order, the add-on manager, a payment method) is
opened — through CS-Cart's own `fn_update_addon_settings()`, without a
reinstall — and take the values the shop's Hypay payment method had. Should that
not be possible on the CS-Cart version, the payment method page keeps its
*Payment links* block and the links keep reading it there; reinstalling the
add-on then adds them (a reinstall now points the Hypay payment methods at the
newly registered processor again, instead of leaving them without one).

An order with no document attached gets a **Payment Link 💳** item in the
order's tools menu — the gear next to **Save**, beside the other order actions.
It opens a window (a dialog, not part of the order form) where the merchant can:

- **choose the orders** the link pays for (see below) and **create and send**
  the link for their total — Hyp sends it to the customer by e-mail, by SMS, or
  both (`action=payRequest&iCommand=CREATE`);
- **copy** the link, to send it any other way;
- **cancel** the link (`iCommand=DELETE`) — the customer can no longer pay with it;
- **check payment** (`iCommand=LIST`) — ask Hyp whether the link has been paid;
- **close** the window.

Under the menu item the order page says what became of the last link — *created
on …*, *cancelled on …*, *expired on …*, or, once it is paid, **Paid by payment link on *date,
time*** (which the Payment information block also shows, as a line of the
payment). The window's buttons work in place: Create, Cancel and Check payment
redraw the window with the outcome, without reloading the page. When a payment
is found, the order page reloads as the window closes, so it shows the payment.

The window lists the orders the way the EzCount Doc Generator does: order,
date, total, status, whether it has a document, and that document's type and
number, with a status filter and the sum of what is ticked. Orders that already
have a document or a link of their own are listed but cannot be ticked.

**Send it, or just make it.** Neither *Send by e-mail* nor *Send by SMS* is
ticked by default. Tick one or both to have Hyp send the link; leave both
unticked to only create the link: it is shown in the window to copy
and send the customer any way you like. `payRequest` does not make a link
without somewhere to send it, so that link is the signed payment page the
checkout uses (`APISign` / `SIGN`) — the payment comes back the same way and is
recorded on every order of the link. Hyp keeps no record of it as a payment
link: *Check payment* is not offered, and *Cancel* marks it cancelled in the
store only (a payment made on it anyway is still recorded).

The signed page URL is well over 255 characters. Versions that stored it in a
`varchar(255)` cut it off before `action=pay` and the signature, and Hyp
answered such a link with *"Action is not good"*. The column is now `text`;
links already cut off are cancelled automatically with that reason, so a new
one can be made for their orders.

**J4 or J5.** The window asks for the deal type: *Charge now (J4)*, the
default, or *Hold only (J5)*. A J5 link is made with `J5=True` and
`MoreData=True`, like a J5 checkout, and pays for the order it is opened on
only (a hold is captured and voided order by order). When the customer comes
back, the hold is stored exactly as a J5 checkout stores it — authorization,
UID, card token — the order gets the J5 authorization status and additional
status, and the money is captured or released from the order's J5 block. No
document is issued for the hold; the capture issues it. An order placed with
another method is captured through the Hypay method the link was made with.
If only the LIST lookup sees the link used (the customer's return never came),
the order is not moved: the details a capture needs come only with the return,
and the window says to capture it in the Hyp portal if it never arrives.

**Where the customer lands.** After paying on a link the customer is sent to
the order's page when the storefront will show it to them (their own order, or
a guest order placed in this browser), and to the home page with a "payment
received" message otherwise — never to a 403. A checkout return whose order the
visitor cannot see is sent to the home page the same way instead of
`checkout.complete`.

A checkout payment page opened for the order after the link was made leaves a
marker on the order. Earlier versions let that marker alone decide the return
was the checkout's, so a paid link was sent down the checkout path — nothing
recorded on an order no checkout was placed for, and `checkout.complete` with
403 for the customer. Now the return is the checkout's only when it cannot be
the link's: a different amount, a link already paid, or a J5 hold the link did
not ask for.

**Link lifetime.** *Link lifetime, days* sets how long a link
stays payable (empty or 0: no limit). An active link shows *valid until …*.
Once the time is up, the next time the order page or the window is opened the
link is looked up at Hyp once more (a payment made just before is recorded as
paid, not expired), withdrawn (`iCommand=DELETE`) and marked **expired**: the
window says *Link expired on …* and offers the form to create a new one. A
signed page cannot be withdrawn at Hyp; like a cancelled one, a payment made on
an expired link anyway is still recorded.

**Additional statuses.** With the eCom Labs *Additional Order Statuses* add-on
active, every order the link pays for gets *Additional status after the link is
created* when the link is created, *Additional status after the link is
cancelled* when it is cancelled — from the window, or from the Hyp portal
(found by the LIST lookup) — or expires, and *Additional status after the link
is paid* when the payment is recorded (left on *as the payment method sets*:
the method's success additional status, as before).

**Terminal permission.** The link API needs its own permission on the terminal:
sending links by hand from the Hyp portal does not grant it. Hyp refuses the
request with `CCode=901 … payRequest API is not enabled for this terminal`, and
the order page then names the terminal the request went through — the one of
the Hypay payment method the link goes through — so it can be enabled for that one
in Hyp Market or by Hyp support. The request is authenticated with the same
terminal number and `PassP` as the J5 captures.

**Several orders, one link**

The panel does not create the link straight away. It first lists the orders the
link will pay for: the order it was opened on — always included — and the same
customer's other orders whose status is one of those chosen under **Customer's
orders offered in the link** in the add-on settings.
Orders that already have a document attached, or a link of their own, are not
listed. Tick the ones to include; the table shows the total the customer will
be asked to pay, and the link is created for that sum.

Everything that happens to the link happens to every order in it:

- each of them shows the link, when it was created or cancelled, and the other
  orders it covers — cancel or check it from any of them;
- once paid, each of them gets the same payment information, the success status
  and the additional status, and the "Paid by payment link on …" line;
- the EzCount document (direct API) is one document for the whole payment,
  with every order's lines, recorded on each order — the same way the EzCount
  Doc Generator records a document it issues for several orders.

With no statuses selected the list offers nothing else, and a link pays for the
one order it was created from.

**How the payment reaches the order**

The link is paid on Hyp's own payment page, and the result comes back two ways:

1. **The customer's return.** Hyp redirects the customer to the terminal's
   success / failure URL — the same `payment_notification` the checkout payment
   page returns to. The return is recognised as a link payment (by the order
   number, or by the `Info` text and amount the link was created with) and is
   recorded exactly like a checkout payment: transaction Id, card brand and last
   four digits, number of payments, personal ID, the success status and the
   additional status, and the EzCount document in direct mode. The customer is
   sent to the storefront home page with a "payment received" message rather than
   to the checkout "thank you" page — they did not come from a checkout.
   A declined card on the link page does not change the order: the link stays
   open for another attempt, and the refusal is shown in the panel.
2. **The LIST lookup.** When the return never arrives, the admin panel asks Hyp
   by itself: the **order list** about every link still out, an **order page**
   about that order's link — before the page is built, so it already shows the
   payment, the status and the additional status — and **Check payment** on
   demand. LIST answers for every recent link of a terminal at once, so it is
   one request per terminal however many links are out; each link is asked
   about at most every 20 seconds (`HYPAY_LINK_AUTO_CHECK_INTERVAL`), with a
   15-second timeout (`HYPAY_LINK_AUTO_CHECK_TIMEOUT`). The page says which
   orders it found paid. `status=3` settles the order with what LIST knows —
   its transaction Id, but no card details; if the return turns up afterwards,
   it fills them in.

   Earlier versions looked only from the order page, and only once a minute
   counting from the moment the link was made — an order opened within that
   minute after a quick payment showed nothing until *Check payment* was
   pressed.

Whichever arrives first moves the order; the other one never moves it again.

**The document after a link is paid**

Each EzCount mode has its own **Document after a payment link is paid** setting:
*Tax Invoice Receipt* (320, the default), *Receipt* (400) or *No document*.

- **Direct API** — the add-on issues the chosen document as soon as the payment
  is recorded (from the return or from the LIST lookup) and stores it on the
  order exactly as after a checkout payment. The payment link block shows its
  type, number and PDF link.
- **Integrated** — Hyp issues the document itself when the link is paid. The
  link is created with `SendHesh` / `Pritim` / `heshDesc` as configured and the
  document type in `EZ.type` (the constant `HYPAY_EZ_INT_DOC_TYPE_PARAM` in
  `func.php`); the document number Hyp returns in `Hesh` is shown in the block.
  *No document* sends `SendHesh=False` and no invoice data — but a terminal whose
  invoice module issues a document on every payment may still issue one.

The panel says in advance which document the payment will produce.

**Safeguards**

- A link is offered for an order that has no document attached yet — a tax
  invoice, proforma invoice or tax invoice receipt, issued by this add-on or by
  the EzCount Doc Generator (`?:order_data` type `X` with a document number).
  An order with a document has been billed, so it gets no button. The order
  does not have to be placed with a Hypay method: the link then goes through
  the shop's active Hypay payment method. Only one link can be active per order.
- If the order total changes after the link was sent, the panel says so: the
  customer would pay the old amount, so cancel the link and send a new one.
- An order paid at checkout while a link is still out has its link cancelled
  automatically; if Hyp answers that the link was paid too, the panel warns
  about the double payment.

**Before you start:** enable the *payment links* feature for your terminal in
Hyp Market, and make sure the terminal's success / failure URL points at the
store's `payment_notification` URL (the same one the checkout payment page
uses). Links, their state and dates are kept in `?:hypay_payment_links`, which
is created automatically.

---

## 🛡️ Verifying the return from Hyp

Hyp reports the outcome of a payment by sending the customer's browser back to
the store's `payment_notification` URL, with the result in the query string
(`CCode=0`, `Amount`, `Order`, …). That is an ordinary address anyone can open
and edit, so on its own it proves nothing: before this check, opening it with
`CCode=0` and an order number was enough to mark that order paid.

Now every return that would record money — a charge (`CCode=0`) or a J5 hold
(`CCode=700`) — is checked with Hyp, **server to server**, before anything is
written. The customer's session plays no part in it, so it works the same for a
guest, a customer signed in as somebody else, or nobody at all.

- **Signed return.** While verification is on, every payment page — checkout
  and payment links made without e-mail / SMS — is requested with `Sign=True`
  (whatever the *Sign* checkbox says), and Hyp adds a `Sign` value to the return.
  The store passes the parameters it received back to
  `APISign` / `What=VERIFY` with the terminal's `KEY` and `PassP`; `CCode=0`
  means Hyp signed exactly these values. The signed `Order` has to be the order
  the return is recorded on, no parameter may appear twice (Hyp would check the
  first one, PHP reads the last), and the `Amount` has to match the order total
  (or the link's amount).
- **A link Hyp sent by e-mail / SMS** whose return carries no signature is
  confirmed with `payRequest` / `LIST`: the link has to be paid (`status=3`),
  with the same transaction Id when Hyp gives one.

A return that does not check out changes nothing — no status, no document, no
link marked paid. The order's payment information gets an *Unverified return*
line (it never repeats anything the request said), the link window says so, and
the customer is told the store will confirm the payment. A verified return that
arrives later clears the line. A link Hyp sent is still settled by the LIST
lookup from the order page if its return was refused.

**Setting:** *Verify payments with Hyp* (on by default). Switch it off only to
diagnose a problem.

**When updating:** payment pages opened before the update were requested
without `Sign=True` (unless the *Sign* box was ticked), so their returns carry
no signature and are not recorded automatically. Re-create payment links made
without e-mail / SMS that are still unpaid; links sent by e-mail / SMS are
confirmed through LIST and are not affected.

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
