# Changes

## 1.0.1 — 2026-11-20

- The customer's service page has a **Log in to OpenPlex** button (single sign-on):
  WHMCS shows none of its own in the client area, so customers had no way in.
- Tested end to end in WHMCS 9.1.1 against OpenPlex 0.33.0 (`tests/whmcs`): connection
  test, create, suspend, unsuspend, change package and password, single sign-on for
  customers and admins, the client-area button, terminate.
## 1.0.0 — 2026-11-13

First release: create, suspend, unsuspend, terminate, change package and
password, single sign-on for customers and admins, connection test.
Requires OpenPlex 0.31.
