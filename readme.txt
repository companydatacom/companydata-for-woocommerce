=== CompanyData for WooCommerce - Company Lookup & Address Autofill ===
Contributors: companydata
Tags: woocommerce, b2b, company lookup, address autocomplete, kvk
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Company name or registration number in, billing address out. Customers pick their company at checkout; the number is saved on the order.

== Description ==

B2B checkouts ask for a company name and then make the customer type the address that the company register already holds. This plugin turns the WooCommerce Company field into a lookup: start typing a company name, or paste a registration number (KvK, Companies House, SIREN and 1,200+ other registers), pick the company, and the street, postcode, city, state and country fill in from the [CompanyData](https://companydata.com/) database of 400M+ companies in 200+ countries.

**For the customer**

* Suggestions appear after three characters, with address and registration number so the right branch is easy to spot.
* Picking one fills the billing address (and the shipping address, if you enable it there). Everything stays editable.
* Keyboard friendly: arrow keys, Enter, Escape; screen readers get a proper listbox.
* If the lookup is unavailable for any reason, the checkout simply works as before.

**For you**

* The company's registration number and CompanyData ID are saved on the order and shown on the order screen, and remembered on the customer account.
* An optional visible "Company registration number" field, optional or required, which also looks the company up when a number is typed.
* Which countries to search (your selling countries by default), which fields to fill, how many suggestions, how long to cache.
* Limits that protect your plan: lookups per visitor per day and a site-wide daily cap. Repeat lookups are served from cache and cost nothing.
* A Usage screen with the searches sent per day.

**What it costs**

The lookup runs on the [CompanyData API](https://companydata.com/apis/). You need an API key; the trial needs no credit card. Each completed lookup at checkout uses one search from your plan, and nothing else: the address comes with the search result, so no credits are spent.

**Supported checkout**

This version works with the classic (shortcode) checkout, `[woocommerce_checkout]`. Support for the block-based checkout is planned; the settings screen tells you which one your shop uses.

== External services ==

This plugin connects to the **CompanyData API** at `https://app.companydata.com` once a site owner has entered an API key. Without a key it makes no external requests.

What is sent, and when:

* **Company lookup** - the text a customer typed into the Company field (or the registration-number field) after it reaches the configured minimum length, and the country selected on the checkout. The request is made from your server, so the customer's IP address, name, email and other checkout details are not sent. Results are cached in your database for the number of days you choose.
* **Test connection** - a fixed test search when you press the button on the settings screen.

Every request carries your API key and a User-Agent with the plugin, WordPress and WooCommerce versions.

CompanyData terms: https://companydata.com/terms-conditions-webshop/
CompanyData privacy statement: https://companydata.com/privacy-statement/

The optional "Company lookup by CompanyData" line under the field is off by default and can be switched on under WooCommerce > CompanyData. No other external requests are made. No tracking scripts are loaded.

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. Get an API key at https://app.companydata.com/signup-api and paste it under **WooCommerce > CompanyData**.
3. Make sure the Company field is shown on your checkout (WooCommerce > Settings > Advanced > Checkout: Company field "Optional" or "Required").
4. Open your checkout and start typing a company name.

== Frequently Asked Questions ==

= Does it work with the block-based checkout? =

Not yet. This version supports the classic checkout page with the `[woocommerce_checkout]` shortcode. The settings screen warns you if your checkout page uses the block.

= Which countries and registers are covered? =

200+ countries, including Companies House (UK), KvK (Netherlands), Handelsregister (Germany), INSEE/SIREN (France) and many more. The lookup searches the country the customer selected on the checkout. If there is no match there but the company is registered elsewhere, the suggestion says so.

= Does it validate VAT numbers? =

No. VAT numbers are not part of the company records. Use a VAT validation plugin alongside this one; they do not conflict.

= How many searches does a checkout use? =

Typically one to three: one request each time the customer pauses typing for the configured wait (350 ms by default), once three characters are in. Identical text is served from cache afterwards. The number lookup is one request.

= Can customers still type the address themselves? =

Yes. The lookup only suggests; every field stays editable, and the checkout works without the lookup when it is off or unavailable.

= Where is the registration number stored? =

As order meta (`_companydata_registration` and `_companydata_id`), visible on the order screen, and on the customer account for logged-in customers. Both are included in the WordPress privacy export and erase tools.

== Screenshots ==

1. Typing a company name at checkout shows matching companies with their address.
2. The address filled in after picking a company.
3. Settings: key, countries, fields, limits.
4. The registration number on the order screen.

== Changelog ==

= 0.1.0 =
* First release: company lookup and address fill on the classic checkout, registration-number field, order and customer meta, usage screen, privacy tools.
