# CompanyData Company Lookup

Customers type their company name or registration number at the WooCommerce
checkout and the billing address fills itself from the
[CompanyData](https://companydata.com/) register of 345M+ companies. The
registration number and CompanyData ID are saved on the order.

- API docs: https://companydata.com/api-docs/
- User docs, FAQ and the list of external services: [`readme.txt`](readme.txt) (wordpress.org format)

## How it works

1. The checkout script watches the Company field (and the optional
   registration-number field). After three characters and a short pause it
   calls the plugin's own REST route, `/wp-json/companydata/v1/lookup`.
2. The route checks the nonce, the per-IP and daily limits, then calls
   `GET /api/company/search` on app.companydata.com with the server-side key,
   filtered to the country the customer selected. Results are cached.
3. Picking a suggestion fills company, street, postcode, city, state and, when
   the match is registered elsewhere, the country. Two hidden inputs carry the
   CompanyData ID and registration number into the order.

Only the search endpoint is used. The address comes with the search result,
so a checkout costs one search and no export credit.

## Layout

```
companydata-company-lookup.php   bootstrap, WooCommerce feature compatibility
uninstall.php                     removes options; order/customer meta only if opted in
includes/
  class-plugin.php      wiring, WooCommerce-missing notice
  class-settings.php    options + encrypted API key
  class-api-client.php  server-side HTTP to app.companydata.com, caching, counters
  class-rest.php        /wp-json/companydata/v1/lookup
  class-checkout.php    classic checkout: fields, hidden inputs, order + customer meta, admin display
  class-checkout-blocks.php  block checkout: registration field, Store API extension data, order meta
  class-admin.php       settings and usage screens under WooCommerce
  class-privacy.php     WP privacy exporter + eraser
  helpers.php           country mapping, throttles, credit line
assets/js/checkout.js   classic checkout autocomplete (jQuery, because its checkout events are jQuery)
assets/js/checkout-blocks.js  block checkout autocomplete (writes through the wc/store/cart data store)
assets/css/             checkout.css, admin.css
```

No build step.

## Local development

Any WordPress with WooCommerce, with the block checkout or the classic
`[woocommerce_checkout]` shortcode on the checkout page. Symlink or copy this folder into `wp-content/plugins/`, activate,
paste a key under WooCommerce > CompanyData.

```
wp option update woocommerce_checkout_company_field optional
```

Run [Plugin Check](https://wordpress.org/plugins/plugin-check/) before a release.

## Releasing

1. Bump `Version` in `companydata-company-lookup.php` and `COMPANYDATA_WC_VERSION`, and `Stable tag` + changelog in `readme.txt`.
2. `git archive --format=zip --prefix=companydata-company-lookup/ -o companydata-company-lookup.zip HEAD`

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
