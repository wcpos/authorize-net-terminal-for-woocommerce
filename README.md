# Authorize.net Tap to Pay for WooCommerce POS

Take card payments in WooCommerce POS with Authorize.net's own phone app. The cashier taps the card on
the phone, in the Authorize.net app. WCPOS starts the payment, waits for the matching Authorize.net
transaction and records it on the order.

Requires WooCommerce POS Pro 2.0 or later.

## Setup

1. In the Authorize.net Merchant Interface, open **Account → API Credentials & Keys** and copy the
   **API Login ID** and a **Transaction Key**. Enter both in WooCommerce → Settings → Payments →
   Authorize.net Tap to Pay, and choose Sandbox or Production.
2. Turn on the Transaction Details API: **Account → Transaction Details API**. WCPOS uses it to find
   the payments taken in the app.
3. Install the Authorize.net mobile app on the phone, and sign in with a user of the same Authorize.net
   account.
4. Enable the gateway in **POS → Settings → Checkout**.

## Taking a payment

- Start the payment in WCPOS, then take the same amount in the Authorize.net app.
- WCPOS completes the payment by itself when it finds exactly one new Authorize.net payment of that
  amount.
- When several payments match, pick yours on the order-pay page. The till shows "waiting" until you do.
- A declined card fails the payment. Start a new payment in WCPOS before tapping again.
- Cancelling asks WCPOS to stop waiting. If exactly one new payment of that amount has arrived, it is
  recorded and the payment completes instead; otherwise the payment is cancelled.

## Assumptions

We could not ask a merchant or test a phone, so these are assumptions, not tested facts:

- You use the Authorize.net 2.0 app, and tap to phone is offered in your region. Any processor
  Authorize.net supports should work. Your Authorize.net account takes one currency.
- Payments taken in the app appear in Authorize.net's unsettled transaction list within seconds (to be
  verified).
- Tips added in the app are not supported: the amount taken must match the WCPOS amount exactly.
- The phone's clock and the WCPOS server's clock agree to within 30 seconds.

## Limits and safety

- An Authorize.net transaction can be recorded once, ever, on one payment.
- Every failed check refuses: when anything about a transaction does not match, it is not recorded.
- When the amount or currency Authorize.net reports differs from the WCPOS payment, the payment fails
  with "amount mismatch" and an order note that shows what Authorize.net took. Refund or record that
  transaction by hand.
- A decline for the same amount, from any register, fails a payment that is still waiting and has no
  approved payment of that amount to match. That is the safe direction: the cashier starts again, and
  no money is recorded.
- Before Authorize.net settles a payment, only a full refund is possible (it voids the payment).
  Partial refunds work after settlement, which is usually overnight.
- Authorize.net refuses an identical refund repeated within 8 hours.
- A cancel completes the payment instead when one new payment of that amount has arrived. If several
  have arrived, or the card is tapped after the cancel, that Authorize.net transaction stays
  unrecorded. Refund it in the Merchant Interface, or start a new WCPOS payment within 15 minutes and
  pick it from the list.
- Registers taking the same amount in the same second may get each other's transaction. The money is
  right, but a refund goes to the other customer's card. The guards are the 30-second window for
  automatic matching and the pick list.

## Not supported

- Webhooks (WCPOS polls Authorize.net instead).
- Manual capture (authorise now, capture later).
- Partial payment on the phone.
- Visa Acceptance Tap to Phone SDK or cloud mode (possible later).

## How it works

For developers. The plugin registers a server provider with WCPOS Pro
(`includes/Server/Authorize_Net_Server_Provider.php`); WooCommerce POS Free owns the payment ledger.

- **Create** sends nothing to Authorize.net. It stores an action record for the ledger row (amount,
  currency, environment, start time), keyed on the row id, so a replay returns the same action.
- **Fetch** reads `getUnsettledTransactionList`. Candidates are captured transactions of the same
  amount, submitted at most 15 minutes before the payment started, and not recorded elsewhere. When
  auto-match is on and exactly one candidate was submitted no earlier than 30 seconds before the start,
  it is verified and claimed. Otherwise the till gets a pick-list whose buttons are `txn:<transId>`.
  When nothing captured fits and a same-amount decline was submitted since the start (less 30 s), the
  leg fails with `declined`.
- **Verify and claim**: `getTransactionDetails` must show a captured sale (responseCode 1) for exactly
  the amount, in a currency `getMerchantDetails` lists, submitted inside the window. The claim is an
  insert-once option per environment and transaction id, so one transaction never pays two orders.
  An amount or currency mismatch is not claimed: fetch reports it `completed` with the details'
  `settleAmount` and the merchant's currency, so Free fails the row with `amount_mismatch`.
- **Cancel** before a claim stores the request and returns `requested`; after a claim it is refused
  (refund instead). The next fetch claims a single fresh candidate (whatever the auto-match setting)
  and completes, or else cancels; it never prompts, and a failed request stays indeterminate. A
  doubt about a listed tap stays indeterminate. An
  action created in one environment fails with `mode_changed` if the gateway is switched to the other.
- **Refund** voids an unsettled transaction (full amount only) or sends `refundTransaction` for a
  settled one. responseCode 4 (held for review) is reported as pending.

## Beta zip

Every pull request builds an installable `authorize-net-terminal-for-woocommerce.zip`: open the
"Beta zip" workflow run and download it from the run's Artifacts list (kept 14 days). It is a test
build, not a release. `bash scripts/build-zip.sh <out-dir>` builds the same zip locally.

## Development

```sh
composer install
composer test   # regression tests, plain PHP
composer lint   # php -l over the plugin
```

WCPOS Pro's provider conformance suite runs under wp-env, with WCPOS Pro checked out as the sibling
directory `../woocommerce-pos-pro` (or set `WCPOS_PRO_DIR`):

```sh
npx -y @wordpress/env@10 start
npx -y @wordpress/env@10 run --env-cwd='wp-content/plugins/authorize-net-terminal-for-woocommerce' tests-cli -- vendor/bin/phpunit -c phpunit.conformance.xml.dist
npx -y @wordpress/env@10 stop
```

Golden transcripts live in `tests/conformance/transcripts/`. To record a missing one, add
`env WCPOS_RECORD_TRANSCRIPTS=1` before `vendor/bin/phpunit`, review the JSON, then rerun without it.
