# WHMCS Marketplace listing

The texts for the listing at marketplace.whmcs.com (My Account → Submit a
Listing). The description is Markdown, without links or images (Marketplace
rules); the vendor's website and support links come from the Group.

| Field | Value |
|---|---|
| Name | OpenPlex |
| Tagline | Provision OpenPlex hosting accounts: create, suspend, upgrade, terminate and single sign-on. |
| Category | Provisioning Modules |
| Price | Free |
| Download URL | https://github.com/avakiam/openplex-whmcs/releases/download/v1.0.1/openplex-whmcs-1.0.1.zip |
| Group website | https://openplex.dev |
| Group support | https://openplex.dev/contact/ (WHMCS wants a contact page that needs no account, so not GitHub issues) |
| Icon | `marketplace/icon-200.png` (200×200, from OpenPlex's icon) |
| Screenshots | `marketplace/1-product-settings.png`, `2-admin-service.png`, `3-client-area.png` (Twenty-One theme, with the "Log in to OpenPlex" button) |

## Description

OpenPlex is an open-source hosting control panel for Linux servers: websites in
their own containers with free HTTPS, WordPress, Joomla and Drupal, MariaDB and
PostgreSQL databases, e-mail with webmail, DNS and backups. This free module
lets you sell OpenPlex hosting from WHMCS.

**What it does**

- **Create:** a customer or reseller account with the product's limits
  (websites, databases, database size), the dashboard language, and a website
  for the order's domain with the runtime you choose (PHP, static, Node.js,
  Python or Go).
- **Suspend / Unsuspend:** the account can't sign in and its websites go
  offline, then everything comes back as it was. A reseller's customers follow.
- **Terminate:** the websites (files, mail, DNS), the databases and the account
  are removed.
- **Change package and password** from WHMCS.
- **Single sign-on:** customers and admins open the panel with a one-time link,
  without typing a password.
- **Test Connection** checks the API key from the server page.

**Requirements**

- OpenPlex 0.31 or newer, and an OpenPlex API key (administrator or reseller).
- WHMCS 8 or newer, PHP 7.4+ with cURL. Tested with WHMCS 9.1.1.

**Setup**

1. Unzip the module into your WHMCS folder.
2. Add a server with module OpenPlex, the panel's hostname and the API key in
   the Access Hash field.
3. Create a product with module OpenPlex and set its limits.

Every API call is written to the WHMCS module log without the API key,
passwords or sign-in links. The module is open source under the MIT licence.
