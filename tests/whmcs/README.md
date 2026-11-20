# Testing the module in a real WHMCS

An end-to-end test on this machine: WHMCS (PHP 8.2, Apache, ionCube) with
MariaDB, and an OpenPlex server in a systemd container, on one Docker network.
WHMCS's own API (`localAPI`) runs every module action the way an order does,
and the OpenPlex API checks each result. Last run: WHMCS 9.1.1 with OpenPlex
0.33.0, all 31 checks passed (including the client-area button), and the module log (with module debugging on)
held no API key, password or sign-in link.

It needs a WHMCS licence: a development licence from WHMCS works, and its terms
ask that the installation is not reachable from the internet. Here WHMCS only
listens on `127.0.0.1:8088`.

## Run

From this folder, with the WHMCS release zip unpacked to `whmcs/`:

```bash
docker compose up -d --build

# OpenPlex: install it, open its API port to the WHMCS network only, create an admin API key
docker compose exec openplex bash -c 'curl -fsSL https://github.com/avakiam/openplex/releases/latest/download/install.sh | bash -s -- --listen 0.0.0.0:7080'
subnet=$(docker network inspect whmcs-test_default --format '{{(index .IPAM.Config 0).Subnet}}')
docker compose cp opx-setup.sh openplex:/root/ && docker compose exec openplex bash /root/opx-setup.sh "$subnet"
docker compose exec openplex cat /root/whmcs-api-key | docker compose exec -T whmcs sh -c 'cat >/root/opx-key'

# WHMCS: copy it and the module in, install it with your licence key
tar -C whmcs -cf - . | docker compose exec -T whmcs tar -C /var/www/html -xf -
tar -C ../.. -cf - modules | docker compose exec -T whmcs tar -C /var/www/html -xf -
docker compose cp whmcs-install.sh whmcs:/root/ && docker compose exec whmcs bash /root/whmcs-install.sh "$WHMCS_LICENSE"
docker compose exec whmcs rm -rf /var/www/html/install

# The test
docker compose cp lifecycle-whmcs.php whmcs:/root/
docker compose exec -w /var/www/html whmcs php /root/lifecycle-whmcs.php
```

The admin area is at <http://localhost:8088/admin/> (user `admin`, password in
`/root/whmcs-admin-password` inside the `whmcs` container).

Notes:

- The OpenPlex container keeps Docker's and containerd's data in volumes:
  nested overlay mounts fail otherwise and websites never start.
- OpenPlex limits sign-in attempts per address. Several runs in a row end in
  HTTP 429 on the sign-in checks; `systemctl restart openplexd` in the
  `openplex` container resets it.
- WHMCS's `AddProduct` API does not save the eighth module setting (the
  dashboard language), so the test sets it on the product directly, as the
  product page does.
- The licence binds to the domain, IP and folder of its first check, and WHMCS
  takes the IP from the request: open WHMCS always the same way (the browser on
  this machine, or a browser in the Docker network that maps `localhost` to the
  container's address). After a change, reissue the licence in the WHMCS client
  area and clear the cached check (`update tblconfiguration set value='' where
  setting='License'`).
- `docker compose down -v` removes everything.
