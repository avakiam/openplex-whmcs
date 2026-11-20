# OpenPlex for WHMCS

A WHMCS provisioning module for [OpenPlex](https://github.com/avakiam/openplex):
sell hosting on an OpenPlex server and let WHMCS create, suspend and remove the
accounts as orders are paid, go overdue or are cancelled.

| WHMCS action | What happens in OpenPlex |
|---|---|
| Create | A customer (or reseller) account with the product's limits, and a website for the order's domain |
| Suspend | The account can't sign in and its websites go offline — a reseller's customers' too |
| Unsuspend | The account and each website are back as they were |
| Terminate | The websites (files, mail, DNS) and databases are deleted, then the account |
| Change package | The new limits (websites, databases, database size) |
| Change password | The account's new password |
| Log in to OpenPlex | A button on the customer's service page (and on the admin service page) signs them in to the panel with a one-time link — no password typed |

## Requirements

- OpenPlex **0.31** or newer (the single sign-on, suspension and purge endpoints).
- WHMCS 8 or newer, PHP 7.4+ with cURL.
- WHMCS must reach the panel's API: its HTTPS domain, or its address and port.

## Install

1. Download `openplex-whmcs-<version>.zip` from the
   [releases](https://github.com/avakiam/openplex-whmcs/releases) and unzip it
   in your WHMCS installation's folder, so that you have
   `<whmcs>/modules/servers/openplex/openplex.php`.
2. In OpenPlex, create an API key on **API keys** as an administrator (to sell
   any kind of account) or as a reseller (accounts are then its customers).
3. In WHMCS, **System Settings → Servers → Add New Server**:
   - **Module:** OpenPlex
   - **Hostname:** the panel's domain, e.g. `panel.example.com`
   - **Access Hash** (or **Password**): the API key, `opx_…`
   - **Secure:** on, port 443 when the panel has a domain with HTTPS. For a panel
     reached by its address only: secure off, port 7080 (keep that port closed to
     the internet except to WHMCS).
   - **Test Connection** checks the key and that it isn't a customer's.
4. Create a product (**Products/Services**) with module **OpenPlex** under
   **Module Settings**, and choose when to create the account (usually when the
   first payment arrives).

## Product settings

| Setting | |
|---|---|
| Account type | Customer, or Reseller (manages customers of its own) |
| Websites | how many websites the account may have; `0` = no limit |
| Databases | how many databases; `0` = no limit |
| Database size (MB) | default size limit of each database; `0` = no limit |
| Create the website | create a website for the order's domain |
| Website runtime / Runtime version | what that website runs: PHP, static, Node.js, Python or Go; empty version = the runtime's default |
| Dashboard language | English, Spanish, Catalan, or the customer's browser |

## Details

- **Username:** OpenPlex accepts 3–32 characters (lowercase letters, digits,
  `_`, `.`, `-`, starting with a letter). The module derives it from the one
  WHMCS suggests (or the domain) and, if it is taken, adds three digits. The final
  name is saved on the service.
- **Password:** at least 10 characters with letters and digits. When the one
  WHMCS generated is shorter, the module makes another and saves it on the
  service. Changing it to a shorter one is refused.
- **Account ID:** saved in the service's custom field *OpenPlex account ID*
  (created automatically). A service without it — imported from elsewhere — is
  matched to the account with its username **and** the client's e-mail address.
- **Single sign-on:** the link lasts two minutes and works once. The customer
  still needs the current password to change it in the panel. A suspended account
  can't be signed in.
- **If the website can't be created** (the domain is already on the server, the
  limit is 0…), the account stays and WHMCS shows the reason; add the website from
  the panel.
- **Terminating a reseller** with customers is refused: terminate the customers
  first.
- **Logs:** every API call goes to WHMCS's module log (**System Logs → Module
  Log**, when enabled), without the API key, passwords or sign-in links.

## Testing

`tests/lifecycle.php` runs every action against a real panel on the same
machine, the way WHMCS calls them, and checks the result through the API:

```bash
OPX_KEY=opx_… OPX_PORT=7080 php tests/lifecycle.php
```

It needs an administrator's API key and creates (then deletes) test accounts
named `test.user-1…` and a website `whmcs-test.lan`.

[`tests/whmcs`](tests/whmcs) runs the same actions in a real WHMCS (through its
own API, as orders do) against a real OpenPlex server, all in Docker on one
machine. Last run: WHMCS 9.1.1 and OpenPlex 0.33.0, all checks passed.

## License

MIT — see [LICENSE](LICENSE). WHMCS is a trademark of WHMCS Limited; this module
is not affiliated with or endorsed by WHMCS.
