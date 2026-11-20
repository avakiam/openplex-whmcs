<?php
// End-to-end test of the OpenPlex module inside a real WHMCS: WHMCS's own
// API (localAPI) drives each action and the OpenPlex API checks the result.
// Run as root from the WHMCS directory: php /root/lifecycle-whmcs.php
require '/var/www/html/init.php';
require_once '/var/www/html/modules/servers/openplex/openplex.php';

use WHMCS\Database\Capsule;

$key = trim(file_get_contents('/root/opx-key'));
$fail = 0;

function check($name, $ok, $detail = '')
{
    global $fail;
    if (!$ok) {
        $fail++;
    }
    printf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? " ($detail)" : '');
}

function api($cmd, array $values = [])
{
    $r = localAPI($cmd, $values, 'admin');
    if (($r['result'] ?? '') !== 'success') {
        echo "  $cmd -> " . json_encode($r) . "\n";
    }
    return $r;
}

function opx($method, $path, $body = null, $token = null)
{
    global $key;
    $ch = curl_init('http://openplex:7080/api/v1' . $path);
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . ($token ?? $key)];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $out, true)];
}

function service($sid)
{
    $r = api('GetClientsProducts', ['serviceid' => $sid]);
    return $r['products']['product'][0] ?? [];
}

function siteOf($domain)
{
    [, $list] = opx('GET', '/domains');
    $items = $list['items'] ?? $list['domains'] ?? $list ?? [];
    foreach ((array) $items as $d) {
        if (is_array($d) && ($d['name'] ?? '') === $domain) {
            return $d;
        }
    }
    return null;
}

function waitSite($domain, $status, $secs = 300)
{
    for ($t = 0; $t < $secs; $t += 5) {
        $s = siteOf($domain);
        if (($s['status'] ?? '') === $status) {
            return $s;
        }
        sleep(5);
    }
    return siteOf($domain);
}

// --- set-up: gateway, server, product group, products, client -------------
if (!Capsule::table('tblpaymentgateways')->where('gateway', 'banktransfer')->exists()) {
    foreach (['name' => 'Bank Transfer', 'type' => 'Invoices', 'visible' => 'on'] as $k => $v) {
        Capsule::table('tblpaymentgateways')->insert(['gateway' => 'banktransfer', 'setting' => $k, 'value' => $v, 'order' => 1]);
    }
}
Capsule::table('tblservers')->where('type', 'openplex')->delete();
$serverId = Capsule::table('tblservers')->insertGetId([
    'name' => 'OpenPlex test', 'hostname' => 'openplex', 'ipaddress' => '', 'port' => 7080, 'secure' => '',
    'type' => 'openplex', 'accesshash' => $key, 'username' => '', 'password' => encrypt(''),
    'maxaccounts' => 100, 'active' => 1, 'disabled' => 0,
]);
$groupId = Capsule::table('tblservergroups')->insertGetId(['name' => 'OpenPlex', 'filltype' => 1]);
Capsule::table('tblservergroupsrel')->insert(['groupid' => $groupId, 'serverid' => $serverId]);

// Test Connection, the way the server page calls it.
$tc = openplex_TestConnection(['serverhostname' => 'openplex', 'serverport' => 7080, 'serversecure' => '', 'serveraccesshash' => $key]);
check('Test Connection with the admin key', ($tc['success'] ?? false) === true, $tc['error'] ?? '');
$bad = openplex_TestConnection(['serverhostname' => 'openplex', 'serverport' => 7080, 'serversecure' => '', 'serveraccesshash' => 'opx_wrong']);
check('Test Connection refuses a wrong key', ($bad['success'] ?? true) === false, $bad['error'] ?? '');

$pg = Capsule::table('tblproductgroups')->insertGetId(['name' => 'OpenPlex hosting', 'slug' => 'openplex-hosting', 'hidden' => 0, 'order' => 1]);
$product = function ($name, $sites, $dbs, $dbSize) use ($pg, $groupId) {
    $r = api('AddProduct', [
        'name' => $name, 'gid' => $pg, 'type' => 'hostingaccount', 'paytype' => 'free',
        'module' => 'openplex', 'servergroupid' => $groupId, 'autosetup' => 'payment',
        'configoption1' => 'customer', 'configoption2' => $sites, 'configoption3' => $dbs,
        'configoption4' => $dbSize, 'configoption5' => 'on', 'configoption6' => 'static',
        'configoption7' => '', 'configoption8' => 'es',
    ]);
    // AddProduct does not save configoption8: set it the way the product page does.
    Capsule::table('tblproducts')->where('id', $r['pid'])->update(['configoption8' => 'es']);
    return (int) $r['pid'];
};
$small = $product('OpenPlex Small ' . time(), 2, 1, 0);
$big = $product('OpenPlex Big ' . time(), 5, 3, 500);

$email = 'client' . time() . '@whmcs-test.lan';
$client = api('AddClient', [
    'firstname' => 'Test', 'lastname' => 'Client', 'email' => $email, 'address1' => 'Street 1',
    'city' => 'Girona', 'state' => 'Girona', 'postcode' => '17003', 'country' => 'ES',
    'phonenumber' => '+34.600000000', 'password2' => 'ClientPass123', 'skipvalidation' => true,
]);
$clientId = (int) $client['clientid'];

// --- Create: order + accept with auto-setup ---------------------------------
$domain = 'whmcs-test' . time() . '.lan';
$order = api('AddOrder', [
    'clientid' => $clientId, 'pid' => [$small], 'domain' => [$domain], 'billingcycle' => ['free'],
    'paymentmethod' => 'banktransfer', 'noinvoice' => true, 'noemail' => true,
]);
$accept = api('AcceptOrder', ['orderid' => $order['orderid'], 'autosetup' => true, 'sendemail' => false]);
$sid = (int) Capsule::table('tblhosting')->where('orderid', $order['orderid'])->value('id');
$svc = service($sid);
check('Create: service active in WHMCS', ($svc['status'] ?? '') === 'Active', $svc['status'] ?? 'no service');
$opxId = (int) Capsule::table('tblcustomfieldsvalues as v')
    ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
    ->where('f.fieldname', 'OpenPlex account ID')->where('v.relid', $sid)->value('v.value');
check('Create: account ID stored on the service', $opxId > 0, (string) $opxId);
[$code, $user] = opx('GET', "/users/$opxId");
check('Create: OpenPlex account exists', $code === 200, "HTTP $code");
check('Create: username matches the service', ($user['username'] ?? '') === ($svc['username'] ?? '?'), ($user['username'] ?? '') . ' / ' . ($svc['username'] ?? ''));
check('Create: role, limits and language', ($user['role'] ?? '') === 'customer' && ($user['max_domains'] ?? 0) === 2
    && ($user['max_databases'] ?? 0) === 1 && ($user['locale'] ?? '') === 'es', json_encode(array_intersect_key($user ?? [], array_flip(['role', 'max_domains', 'max_databases', 'max_db_size_mb', 'locale']))));
check('Create: e-mail is the client\'s', ($user['email'] ?? '') === $email);
$site = siteOf($domain);
$site = waitSite($domain, 'running');
check('Create: website running', ($site['status'] ?? '') === 'running', $site['status'] ?? 'missing');
check('Create: website for the order\'s domain', $site !== null && (int) ($site['owner_id'] ?? 0) === $opxId, $site ? ($site['runtime'] ?? '') : 'missing');

// The service password works in OpenPlex.
$pw = decrypt(Capsule::table('tblhosting')->where('id', $sid)->value('password'));
[$code] = opx('POST', '/auth/login', ['username' => $svc['username'], 'password' => $pw], '');
check('Create: the service password signs in to OpenPlex', $code === 200, "HTTP $code");

// --- Suspend / Unsuspend -----------------------------------------------------
api('ModuleSuspend', ['serviceid' => $sid, 'suspendreason' => 'Overdue']);
[, $user] = opx('GET', "/users/$opxId");
[$code] = opx('POST', '/auth/login', ['username' => $svc['username'], 'password' => $pw], '');
check('Suspend: service suspended in WHMCS', (service($sid)['status'] ?? '') === 'Suspended');
check('Suspend: account cannot sign in', $code !== 200, "HTTP $code, disabled=" . json_encode($user['disabled'] ?? null) . ', suspended=' . json_encode($user['suspended'] ?? null));
$site = siteOf($domain);
check('Suspend: website suspended', $site !== null && ($site['status'] ?? '') === 'disabled', json_encode(array_intersect_key($site ?? [], array_flip(['status', 'suspended', 'disabled', 'enabled']))));

api('ModuleUnsuspend', ['serviceid' => $sid]);
[$code] = opx('POST', '/auth/login', ['username' => $svc['username'], 'password' => $pw], '');
check('Unsuspend: service active in WHMCS', (service($sid)['status'] ?? '') === 'Active');
check('Unsuspend: account signs in again', $code === 200, "HTTP $code");
$site = siteOf($domain);
$site = waitSite($domain, 'running');
check('Unsuspend: website back and running', $site !== null && ($site['status'] ?? '') === 'running', json_encode(array_intersect_key($site ?? [], array_flip(['status', 'suspended', 'disabled', 'enabled']))));

// --- Change package ----------------------------------------------------------
api('UpdateClientProduct', ['serviceid' => $sid, 'pid' => $big]);
api('ModuleChangePackage', ['serviceid' => $sid]);
[, $user] = opx('GET', "/users/$opxId");
check('Change package: new limits', ($user['max_domains'] ?? 0) === 5 && ($user['max_databases'] ?? 0) === 3 && ($user['max_db_size_mb'] ?? 0) === 500,
    json_encode(array_intersect_key($user ?? [], array_flip(['max_domains', 'max_databases', 'max_db_size_mb']))));

// --- Change password ---------------------------------------------------------
$newPw = 'NewPass' . random_int(100000, 999999) . 'x';
api('UpdateClientProduct', ['serviceid' => $sid, 'servicepassword' => $newPw]);
api('ModuleChangePw', ['serviceid' => $sid]);
[$code] = opx('POST', '/auth/login', ['username' => $svc['username'], 'password' => $newPw], '');
check('Change password: the new password signs in', $code === 200, "HTTP $code");
[$code] = opx('POST', '/auth/login', ['username' => $svc['username'], 'password' => $pw], '');
check('Change password: the old one no longer does', $code !== 200, "HTTP $code");
$short = 'short1';
api('UpdateClientProduct', ['serviceid' => $sid, 'servicepassword' => $short]);
$r = api('ModuleChangePw', ['serviceid' => $sid]);
check('Change password: a short password is refused', ($r['result'] ?? '') !== 'success', $r['message'] ?? '');
api('UpdateClientProduct', ['serviceid' => $sid, 'servicepassword' => $newPw]);

// --- Single sign-on (client area and admin) ---------------------------------
foreach (['ServiceSingleSignOn' => 'client', 'AdminSingleSignOn' => 'admin'] as $fn => $who) {
    $m = new \WHMCS\Module\Server();
    $m->loadByServiceID($sid);
    $r = $m->call($fn);
    $url = (string) ($r['redirectTo'] ?? '');
    check("Single sign-on ($who): link returned", ($r['success'] ?? false) === true && strpos($url, '/login/?link=') !== false, $r['errorMsg'] ?? preg_replace('/link=.*/', 'link=…', $url));
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    [$code, $tok] = opx('POST', '/auth/link', ['token' => $q['link'] ?? ''], '');
    check("Single sign-on ($who): the link signs in as the customer", $code === 200, "HTTP $code");
}

// --- Terminate ---------------------------------------------------------------
api('ModuleTerminate', ['serviceid' => $sid]);
[$code] = opx('GET', "/users/$opxId");
check('Terminate: service terminated in WHMCS', (service($sid)['status'] ?? '') === 'Terminated');
check('Terminate: OpenPlex account deleted', $code === 404, "HTTP $code");
check('Terminate: website deleted', siteOf($domain) === null);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
