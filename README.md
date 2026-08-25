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

**Per-usergroup behaviour**

The payment method setting **Payment type** offers:

- `Regular charge (J4)` — current behaviour, the card is charged at checkout;
- `Hold funds (J5) for everyone`;
- `Hold funds (J5) by usergroup` — customers of the selected usergroups (e.g.
  `Dealer` / `Shop`) get a J5 hold, everybody else pays as before.

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

**Data**

Authorizations and captures are stored in `?:hypay_transactions` (kept on uninstall).


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
