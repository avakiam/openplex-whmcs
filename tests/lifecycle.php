<?php
// Runs the module's functions the way WHMCS calls them, against a real
// OpenPlex panel on this machine (127.0.0.1). It creates and deletes test
// accounts named test.user-1… and a website whmcs-test.lan.
//
//   OPX_KEY=opx_… OPX_PORT=7080 php tests/lifecycle.php
//
// OPX_KEY: an administrator's API key. Sign-in attempts count against the
// panel's limit (10 per 5 minutes per address): one run uses 7.
define('WHMCS', true);
$LOG = [];
function logModuleCall($module, $action, $request, $response, $processed, $hide)
{
    global $LOG;
    $LOG[] = [$action, json_encode($request), is_string($response) ? $response : json_encode($response)];
}

class Props
{
    public $v = [];
    public function save(array $a) { foreach ($a as $k => $x) { $this->v[$k] = $x; } }
    public function get($k) { return $this->v[$k] ?? ''; }
}
class Model
{
    public $serviceProperties;
    public function __construct() { $this->serviceProperties = new Props(); }
}

require __DIR__ . '/../modules/servers/openplex/openplex.php';

$key = getenv('OPX_KEY');
$port = getenv('OPX_PORT');
$fails = 0;
function check($what, $ok, $detail = '')
{
    global $fails;
    if (!$ok) { $fails++; }
    printf("%s %s%s\n", $ok ? 'ok  ' : 'FAIL', $what, $detail === '' ? '' : '  — ' . (is_string($detail) ? $detail : json_encode($detail)));
}

function service(array $over = [])
{
    global $key, $port;
    $m = new Model();
    return $over + [
        'serverhostname' => '127.0.0.1', 'serverip' => '', 'serversecure' => false, 'serverport' => $port,
        'serverusername' => '', 'serverpassword' => '', 'serveraccesshash' => $key,
        'serviceid' => 1, 'username' => 'Test.User-1', 'password' => 'short', 'domain' => 'whmcs-test.lan',
        'clientsdetails' => ['email' => 'whmcs-test@example.com'],
        'configoption1' => 'customer', 'configoption2' => '2', 'configoption3' => '3', 'configoption4' => '50',
        'configoption5' => 'on', 'configoption6' => 'static', 'configoption7' => '', 'configoption8' => 'es',
        'customfields' => [], 'model' => $m,
    ];
}
$api = new OpenPlex\Whmcs\Client('127.0.0.1', $port, false, $key);

// Settings and connection
check('ConfigOptions has 8 settings', count(openplex_ConfigOptions()) === 8);
check('TestConnection (admin key)', openplex_TestConnection(service())['success'] === true);
$bad = openplex_TestConnection(service(['serveraccesshash' => 'opx_nope_123']));
check('TestConnection refuses a wrong key', $bad['success'] === false, $bad['error']);
$down = openplex_TestConnection(service(['serverport' => 1]));
check('TestConnection reports an unreachable panel', $down['success'] === false, $down['error']);

// Create
$s = service();
$r = openplex_CreateAccount($s);
check('CreateAccount', $r === 'success', $r);
$p = $s['model']->serviceProperties->v;
$id = (int) ($p[OPENPLEX_ID_FIELD] ?? 0);
check('stores username, password and ID', $p['Username'] === 'test.user-1' && $id > 0 && openplex_passwordOk($p['Password']), [$p['Username'], $id]);
$s['username'] = $p['Username'];
$s['customfields'][OPENPLEX_ID_FIELD] = (string) $id;
$u = $api->get("/users/$id");
check('account as ordered', $u['role'] === 'customer' && $u['max_domains'] === 2 && $u['max_databases'] === 3 && $u['max_db_size_mb'] === 50 && $u['locale'] === 'es' && $u['email'] === 'whmcs-test@example.com', $u);
$sites = array_values(array_filter($api->get('/domains'), function ($d) use ($id) { return $d['owner_id'] === $id; }));
check('website created for the domain', count($sites) === 1 && $sites[0]['name'] === 'whmcs-test.lan' && $sites[0]['runtime'] === 'static', $sites);
$siteId = $sites[0]['id'] ?? 0;
check('CreateAccount twice is refused', openplex_CreateAccount($s) !== 'success');

// Sign in with the generated password
$login = function ($user, $pass) use ($port) {
    $ch = curl_init("http://127.0.0.1:$port/api/v1/auth/login");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['username' => $user, 'password' => $pass])]);
    curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $c;
};
check('signs in with the stored password', $login($p['Username'], $p['Password']) === 200);

// Suspend / unsuspend
sleep(3); // let the website deploy
$before = $api->get("/domains/$siteId")['status'];
check('SuspendAccount', ($r = openplex_SuspendAccount($s)) === 'success', $r);
check('suspended: account disabled, website disabled', $api->get("/users/$id")['disabled'] === true && $api->get("/domains/$siteId")['status'] === 'disabled');
check('suspended: no sign-in', $login($p['Username'], $p['Password']) !== 200);
$sso = openplex_ServiceSingleSignOn($s);
check('single sign-on refused while suspended', $sso['success'] === false, $sso['errorMsg'] ?? '');
check('UnsuspendAccount', ($r = openplex_UnsuspendAccount($s)) === 'success', $r);
$after = $api->get("/domains/$siteId")['status'];
check('unsuspended: account enabled, website status back', $api->get("/users/$id")['disabled'] === false && $after === $before, "$before → $after");

// Change package and password
$s['configoption2'] = '5';
$s['configoption3'] = '0';
check('ChangePackage', ($r = openplex_ChangePackage($s)) === 'success', $r);
$u = $api->get("/users/$id");
check('new limits', $u['max_domains'] === 5 && $u['max_databases'] === 0, $u);
$s['password'] = 'short1';
check('ChangePassword refuses a weak password', openplex_ChangePassword($s) !== 'success');
$s['password'] = 'NewPassw0rd2026';
check('ChangePassword', ($r = openplex_ChangePassword($s)) === 'success', $r);
check('signs in with the new password', $login($p['Username'], 'NewPassw0rd2026') === 200);

// Single sign-on
foreach (['openplex_ServiceSingleSignOn', 'openplex_AdminSingleSignOn'] as $fn) {
    $sso = $fn($s);
    check("$fn gives a link", $sso['success'] === true && strpos($sso['redirectTo'], '/login/?link=') !== false, preg_replace('/link=.*/', 'link=…', $sso['redirectTo'] ?? ''));
    $token = substr($sso['redirectTo'], strpos($sso['redirectTo'], 'link=') + 5);
    $sess = (new OpenPlex\Whmcs\Client('127.0.0.1', $port, false, ''))->post('/auth/link', ['token' => $token]);
    check('the link signs the customer in (sso)', $sess['purpose'] === 'sso' && $sess['user']['id'] === $id);
    try {
        (new OpenPlex\Whmcs\Client('127.0.0.1', $port, false, ''))->post('/auth/link', ['token' => $token]);
        check('the link works once', false);
    } catch (OpenPlex\Whmcs\ApiError $e) {
        check('the link works once', $e->status === 401);
    }
}

// A second order with the same username gets a variation; one without a
// stored ID is found by its username.
$s2 = service(['username' => 'test.user-1', 'domain' => '', 'password' => 'Another1Password']);
check('CreateAccount with a taken username', ($r = openplex_CreateAccount($s2)) === 'success', $r);
$p2 = $s2['model']->serviceProperties->v;
check('gets another username', $p2['Username'] !== 'test.user-1' && strpos($p2['Username'], 'test.user-1') === 0, $p2['Username']);
$other = service(['username' => $p2['Username'], 'clientsdetails' => ['email' => 'someone-else@example.com']]);
check("another client's service doesn't take the account", openplex_SuspendAccount($other) !== 'success' && empty($other['model']->serviceProperties->v));
$s3 = service(['username' => $p2['Username']]);
check('finds an account by username', openplex_SuspendAccount($s3) === 'success' && $s3['model']->serviceProperties->v[OPENPLEX_ID_FIELD] === $p2[OPENPLEX_ID_FIELD]);
check('Terminate the second', openplex_TerminateAccount($s3) === 'success');

// Terminate
check('TerminateAccount', ($r = openplex_TerminateAccount($s)) === 'success', $r);
try {
    $api->get("/users/$id");
    check('account gone', false);
} catch (OpenPlex\Whmcs\ApiError $e) {
    check('account gone', $e->status === 404);
}
check('website gone', count(array_filter($api->get('/domains'), function ($d) use ($id) { return $d['owner_id'] === $id; })) === 0);
check('TerminateAccount again (already gone)', openplex_TerminateAccount($s) === 'success');
check('Suspend without an account', openplex_SuspendAccount(service(['username' => 'nobody-here'])) !== 'success');

// Nothing secret in the module log
$all = json_encode($LOG);
check('the log has no key, passwords or link tokens', strpos($all, $key) === false && strpos($all, 'NewPassw0rd2026') === false && strpos($all, $p['Password']) === false && !preg_match('/link=[A-Za-z0-9_-]{8}/', $all), count($LOG) . ' entries');

echo $fails === 0 ? "ALL OK\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
