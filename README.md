# WopCart Direct

Take payments through your own gateway accounts, with no WopCart platform fee.

This is the paid add-on to [WopCart](https://wopcart.com). It carries the one
thing a merchant pays for: **their own gateway credentials.**

| | WopCart | WopCart Direct |
|---|---|---|
| Sold through | WordPress.org, free | wopcart.com |
| Payments settle | through WopCart's Stripe Connect platform, which retains 2% | directly in the merchant's own accounts, 0% |
| Gateways | Stripe, Apple Pay | adds PayPal, Authorize.net, Mollie, 2Checkout — and Stripe and Apple Pay on the merchant's own keys |
| Licence key | none, and never any | one, for updates and domain count |

Installing it does not replace anything. The shop, the checkout, subscriptions,
licensing, invoices, tax and everything else stay in WopCart and keep being
updated from WordPress.org. Removing this add-on leaves a working shop that
settles through the platform again at 2%.

## Why it exists as a separate plugin

The WordPress.org directory does not allow code to ship inside a plugin and then
be unlocked by payment. Until now PayPal, Authorize.net, Mollie and 2Checkout
were all present in WopCart and switched on by a plan key, which is exactly what
the rule forbids. Connecting to an outside service — including a paid one — is
allowed as long as it is disclosed, so settling free-plan payments through the
platform at 2% is fine; the plan key was not.

So the paid part moved out. It is an add-on rather than a second full plugin
because a merchant who buys it should not have to deactivate one plugin and
install another, both plugins share the same tables and settings, and a bug fix
in the shop should reach paying and non-paying stores in the same release.

## What it needs

- **WopCart 1.3.0 or newer.** 1.3.0 is the release that added the extension
  points this plugin is built out of. Against anything older the add-on stays
  inert and says so in the admin, rather than registering gateways it cannot
  supply credentials for. The plugin header also carries
  `Requires Plugins: wopcart`, so WordPress will not let it be activated without
  core at all.
- WordPress 6.4 or newer, PHP 8.2 or newer — the same floors as core.

It creates no database tables and adds no admin menu. Everything it contributes
appears inside WopCart's own screens.

## Installing

1. Make sure **WopCart** is installed and active, at 1.3.0 or newer.
2. Upload the zip under *Plugins → Add New*, or copy this directory to
   `wp-content/plugins/wopcart-direct/`, and activate it.
3. Go to **WopCart → Settings → Payments**. Paste your licence key in the first
   section, then enter the credentials for the gateways you want to use.
4. Enable those gateways under *Active gateways* on the same tab.

Credentials saved before installing the add-on are found again as they are. The
option names, field types and storage are core's, unchanged — nothing is
migrated and nothing needs re-entering.

## The licence key, and what it does not do

The key has two jobs, and they are the two that genuinely need one:

- **Securing updates.** wopcart.com will not hand over a package without it.
- **Counting production domains.** The product is priced per 1, 10 and 100
  domains, and an activation is how a domain is counted. Local, `.test` and
  recognised staging hosts activate without using one of your places, so a
  developer's copies are free.

**An expired licence never takes your gateways away.** Payments keep going
through your own accounts, no platform fee is reintroduced, and nothing about the
checkout changes. Only updates stop, and an admin notice says so. The same is
true if you have not entered a key at all, or if wopcart.com is unreachable: this
plugin never fails closed. Treating a lapsed licence as a kill switch would mean
a paying customer silently going back to paying 2% for an expiry they had not
noticed — and against a perpetual licence it is unenforceable in several
jurisdictions anyway.

Saving a key activates this site at once. Saving the same key again retries an
activation that failed — because our store was unreachable, or because you were
out of places and have since freed one. Replacing a key releases the one it
replaced first, so moving a site between licences does not consume a place on
both. Clearing the field releases this site.

Updates then arrive through WordPress's own *Plugins* screen, with the usual
"View details" and one-click update.

### Overriding the store and product

Both are constants rather than settings — a merchant has no reason to change
either, and a store URL that could be edited from the admin would be a route for
sending a licence key somewhere else. Define them in `wp-config.php` before
WordPress loads plugins:

```php
// Where licence checks and updates are sent. Default: https://wopcart.com
define( 'WOPCART_DIRECT_STORE_URL', 'https://staging.wopcart.test' );

// The product id on that store that licences are issued for.
define( 'WOPCART_DIRECT_PRODUCT_ID', 1234 );
```

`WOPCART_DIRECT_PRODUCT_ID` defaults to `0`, which is not a real product, so a
build has to be told which product it is. Until it is, licence calls are refused
by the store and updates do not arrive — deliberately a visible failure rather
than a silent one.

Mollie's keys can also be kept out of the database entirely, as they could in
core: `WOPCART_MOLLIE_LIVE_KEY` and `WOPCART_MOLLIE_TEST_KEY` win over the
stored settings.

## How it attaches to core

Everything is done through filters core has published and treats as a frozen
interface from 1.3.0 onward. The add-on adds no menu, no table, no route, and
patches nothing.

| Core hook | What this plugin does with it |
|---|---|
| `wopcart_gateway_classes` | registers PayPal, Authorize.net, 2Checkout and Mollie |
| `wopcart_uses_own_gateway_credentials` | answers yes, whenever the add-on is active |
| `wopcart_stripe_secret_key` / `wopcart_stripe_publishable_key` | answers from the merchant's saved keys for the current mode |
| `wopcart_secret_setting_keys` | routes every credential, and the licence key, to the non-autoloaded secrets option |
| `wopcart_settings_sections` | contributes the licence section and five credential sections to the Payments tab |
| `wopcart_save_settings` | Mollie's write-only key fields |
| `wopcart_settings_saved` | activates a newly saved licence key, releases the one it replaced |
| `wopcart_form_gateways` | the redirect gateways a payment form may offer: core's Stripe plus PayPal, Mollie and 2Checkout |
| `wopcart_invoice_gateway_reports_no_payment` | Mollie's answer, which is what lets "Start over" appear before the attempt window ends |
| `wopcart_integration_cards` / `wopcart_integration_test` | the "Stripe (your own keys)" card and its connection test, a licence card with a Test button, plus any gateway card core did not build |
| `wopcart_system_webhook_checks` | the "webhook secret set?" rows core does not list |
| `wopcart_privacy_third_party_content` | a paragraph per enabled gateway naming the company data is sent to |
| `wopcart_enqueue_gateway_scripts` | Authorize.net's Accept.js on the checkout |

Two details are deliberate and easy to get wrong:

**The filters are registered at file scope, not on `plugins_loaded`.** Core
consults `wopcart_secret_setting_keys` on *every* settings read, because that
filter is what decides which of two options a value lives in. Registered late,
any read that happens first looks in the wrong option and comes back empty —
which is indistinguishable from an unconfigured gateway. Core documents this in
`Options::secret_keys()`.

**Every callback checks `Plugin::active()` when it runs, not when it is
registered.** WordPress loads `wopcart-direct/` before `wopcart/`, so at file
scope core does not exist yet and nothing can ask whether it is new enough. Each
callback asks at the moment it runs instead, and until the answer is yes it hands
back the value it was given untouched. A copy of this plugin next to a missing or
too-old core contributes nothing at all — it is inert, not half-present. The one
exception is the secret-key list, which answers unconditionally: withdrawing a
key would not hide a credential, it would send core looking for it in the wrong
place.

## Running against a core that still carries the gateways

Core kept its own copies of the four gateway classes for one release cycle, on
purpose: the paid code was only deleted from core once this add-on had shipped
and a release had passed, so that no paying store was ever briefly left without
a gateway it was using. **Core 1.4.0 removed them**, together with the plan key,
the plan card, the credential settings sections and the "Stripe (your own keys)"
card and its connection test.

The minimum core here is still 1.3.0, though, so a merchant can be running a
core that has all of it — WordPress does not force a plugin update, and this
add-on updates on its own licence check rather than alongside core. So the
de-duplication stays in place, and against a 1.4.0 core it simply finds nothing
to do:

- Core's four credential sections are removed from the Payments tab before this
  plugin's are added, so there is one "Stripe" heading and one input named
  `stripe_live_secret`, not two of each.
- Core's **plan key** field and its Integrations card are retired. The plan key
  is dropped outright rather than carried over — there were no existing
  customers — so a field collecting it describes a credential that no longer does
  anything, and a merchant pasting their Direct licence into it would get no
  error and no effect.
- Integration cards, webhook rows and privacy paragraphs are only added where
  core has not already produced one. That includes the Stripe own-keys card,
  which this plugin supplies under core's own `stripe` key: on an older core the
  card is core's, on 1.4.0 and newer it is this plugin's, and either way there is
  one of it and the Test button works.
- Core's default list of form gateways is de-duplicated, and its `twocheckout`
  entry — a slug no gateway has ever reported — is dropped in favour of the real
  `2checkout`.

`Gateways\Registry::duplicate_in_core()` says which side of that line the core on
a site is on, and names what can be deleted when the minimum core reaches 1.4.0.

## Uninstalling

Deactivating changes nothing except that payments settle through the platform
again at 2%. Credentials, the licence key and the site's activation are all left
alone.

Uninstalling removes this plugin's own bookkeeping and nothing else — not the
credentials, which are core's data and what you paid for, and not the licence
key. A site is released from your account page on wopcart.com, which is where
the domain count you are paying for is shown.

Core used to sweep every option matching `wopcart_%` on uninstall, which took
`wopcart_gateway_secrets` with it — the credentials a merchant paid for, and the
licence key stored beside them. Core 1.4.0 narrowed that: it strips only the
keys it collects itself and writes the rest back. This plugin's own options are
named `wopcartdirect_…` in any case, without the underscore, so they were never
inside that pattern.

## Development

Composer here is a convenience only. The plugin ships its own PSR-4 autoloader in
`src/Autoloader.php` and has no runtime dependency beyond core. Without a
`vendor/` directory of its own it uses core's binaries, with both repositories
checked out side by side:

```bash
../digital-cart/vendor/bin/phpunit    # 208 tests
../digital-cart/vendor/bin/phpcs
```

The suite reuses core's own test bootstrap — core's source is needed anyway,
since these gateways extend core's `AbstractGateway` — and finds it next to this
checkout, or at `WOPCART_CORE_DIR`. On top of that it implements enough of
WordPress's hook API to run callbacks for real, which is what lets the tests
assert the thing that actually matters: core asks a question and this plugin
answers it.

Four of the gateway files are copies of core's and are meant to stay diffable
against them. The differences are the namespace, the `wopcart-direct` text
domain, imports for the two core classes they used to share a namespace with,
and — in Mollie only — the removal of its three plan checks. Nothing else should
drift; `phpcs` output for those files is identical to core's for the same files.

Three test files came over the same way — `ArbIntervalTest`,
`SecWebhookRefundTest` and the `SecAtomicOrderFakeDb` double they share — and are
kept diffable for the same reason, which matters more for them than for anything
else here: they pin ARB interval floors, PayPal webhook order binding and refund
idempotency, so the move has to be provably behaviour-preserving. Diff either
against `git show <core>:tests/Unit/<file>` and the whole difference is the
classes they name. `ArbIntervalTest` therefore carries eleven `phpcs` errors
verbatim from core's copy; `phpcbf` would clear ten of them and cost that diff.

## Licence

GPL-2.0-or-later, the same as core. The protection against redistribution is
supply, not restriction: updates and support need a valid licence, the platform
is ours to run, and every download carries its customer and licence number. No
feature is ever switched off for want of a key.
