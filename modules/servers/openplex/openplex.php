<?php
/**
 * OpenPlex provisioning module for WHMCS.
 *
 * Creates, suspends, unsuspends and terminates OpenPlex accounts, changes
 * their limits and password, and signs customers in to the panel from the
 * client area. It talks to the OpenPlex REST API with an API key.
 *
 * @see https://github.com/avakiam/openplex-whmcs
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Client.php';

use OpenPlex\Whmcs\ApiError;
use OpenPlex\Whmcs\Client;

const OPENPLEX_ID_FIELD = 'OpenPlex account ID';

function openplex_MetaData()
{
    return [
        'DisplayName' => 'OpenPlex',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultSSLPort' => '443',
        'DefaultNonSSLPort' => '7080',
        'ServiceSingleSignOnLabel' => 'Log in to OpenPlex',
        'AdminSingleSignOnLabel' => 'Log in to OpenPlex as this customer',
    ];
}

/**
 * Product settings, read back in this order as configoption1…8.
 */
function openplex_ConfigOptions()
{
    return [
        'role' => [
            'FriendlyName' => 'Account type',
            'Type' => 'dropdown',
            'Options' => ['customer' => 'Customer', 'reseller' => 'Reseller'],
            'Default' => 'customer',
            'Description' => 'A reseller manages customers of its own',
        ],
        'max_domains' => [
            'FriendlyName' => 'Websites',
            'Type' => 'text',
            'Size' => '6',
            'Default' => '1',
            'Description' => '0 = no limit',
        ],
        'max_databases' => [
            'FriendlyName' => 'Databases',
            'Type' => 'text',
            'Size' => '6',
            'Default' => '1',
            'Description' => '0 = no limit',
        ],
        'max_db_size_mb' => [
            'FriendlyName' => 'Database size (MB)',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '0',
            'Description' => 'Default size limit of each database; 0 = no limit',
        ],
        'create_website' => [
            'FriendlyName' => 'Create the website',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => "Create a website for the order's domain",
        ],
        'runtime' => [
            'FriendlyName' => 'Website runtime',
            'Type' => 'dropdown',
            'Options' => ['php' => 'PHP', 'static' => 'Static', 'node' => 'Node.js', 'python' => 'Python', 'go' => 'Go'],
            'Default' => 'php',
        ],
        'runtime_version' => [
            'FriendlyName' => 'Runtime version',
            'Type' => 'text',
            'Size' => '8',
            'Description' => "For example 8.3; empty = the runtime's default",
        ],
        'locale' => [
            'FriendlyName' => 'Dashboard language',
            'Type' => 'dropdown',
            'Options' => ['' => "The customer's browser", 'en' => 'English', 'es' => 'Español', 'ca' => 'Català'],
            'Default' => '',
        ],
    ];
}

function openplex_TestConnection(array $params)
{
    try {
        $me = openplex_client($params)->get('/auth/me');
        if (!in_array($me['role'] ?? '', ['admin', 'reseller'], true)) {
            return ['success' => false, 'error' => "The API key belongs to a customer account: use an administrator's or a reseller's key"];
        }
        return ['success' => true, 'error' => ''];
    } catch (Exception $e) {
        openplex_log(__FUNCTION__, $params, $e);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function openplex_CreateAccount(array $params)
{
    try {
        $api = openplex_client($params);
        if (openplex_storedId($params) !== null) {
            return 'The service already has an OpenPlex account: terminate it first';
        }

        $username = openplex_username($params);
        $password = (string) ($params['password'] ?? '');
        if (!openplex_passwordOk($password)) {
            $password = openplex_generatePassword();
        }
        $email = (string) ($params['clientsdetails']['email'] ?? '');
        $body = openplex_limits($params) + [
            'password' => $password,
            'email' => $email,
            'role' => openplex_option($params, 1, 'customer'),
            'locale' => openplex_option($params, 8, ''),
        ];

        // The name the order suggested may be taken: try a few variations.
        $user = null;
        for ($try = 0; $user === null; $try++) {
            $candidate = $try === 0 ? $username : substr($username, 0, 28) . random_int(100, 999);
            try {
                $user = $api->post('/users', ['username' => $candidate] + $body);
            } catch (ApiError $e) {
                if ($e->status !== 409 || $try >= 4) {
                    throw $e;
                }
            }
        }

        $params['model']->serviceProperties->save([
            'Username' => $user['username'],
            'Password' => $password,
            OPENPLEX_ID_FIELD => (string) $user['id'],
        ]);

        $domain = strtolower(trim((string) ($params['domain'] ?? '')));
        if ($domain !== '' && openplex_option($params, 5, '') === 'on') {
            $site = ['name' => $domain, 'owner_id' => $user['id'], 'runtime' => openplex_option($params, 6, 'php')];
            $version = openplex_option($params, 7, '');
            if ($version !== '') {
                $site['runtime_version'] = $version;
            }
            try {
                $api->post('/domains', $site);
            } catch (ApiError $e) {
                // The account stays: the website can be added from the panel.
                return 'The account was created, but not its website: ' . $e->getMessage();
            }
        }
        return 'success';
    } catch (Exception $e) {
        openplex_log(__FUNCTION__, $params, $e);
        return $e->getMessage();
    }
}

function openplex_SuspendAccount(array $params)
{
    return openplex_userAction($params, __FUNCTION__, function (Client $api, $id) {
        $api->post("/users/$id/suspend");
    });
}

function openplex_UnsuspendAccount(array $params)
{
    return openplex_userAction($params, __FUNCTION__, function (Client $api, $id) {
        $api->post("/users/$id/unsuspend");
    });
}

function openplex_TerminateAccount(array $params)
{
    try {
        $api = openplex_client($params);
        $id = openplex_accountId($params, $api);
        if ($id === null) {
            return 'success'; // nothing left in the panel
        }
        try {
            $api->delete("/users/$id?purge=true");
        } catch (ApiError $e) {
            if ($e->status !== 404) { // 404: already deleted in the panel
                throw $e;
            }
        }
        $params['model']->serviceProperties->save([OPENPLEX_ID_FIELD => '']);
        return 'success';
    } catch (Exception $e) {
        openplex_log(__FUNCTION__, $params, $e);
        return $e->getMessage();
    }
}

function openplex_ChangePackage(array $params)
{
    return openplex_userAction($params, __FUNCTION__, function (Client $api, $id) use ($params) {
        $api->patch("/users/$id", openplex_limits($params) + ['role' => openplex_option($params, 1, 'customer')]);
    });
}

function openplex_ChangePassword(array $params)
{
    if (!openplex_passwordOk((string) $params['password'])) {
        return 'The password needs at least 10 characters with letters and digits';
    }
    return openplex_userAction($params, __FUNCTION__, function (Client $api, $id) use ($params) {
        $api->post("/users/$id/password", ['password' => (string) $params['password']]);
    });
}

function openplex_ServiceSingleSignOn(array $params)
{
    return openplex_singleSignOn($params, __FUNCTION__);
}

function openplex_AdminSingleSignOn(array $params)
{
    return openplex_singleSignOn($params, __FUNCTION__);
}

function openplex_AdminServicesTabFields(array $params)
{
    $id = openplex_storedId($params);
    return ['OpenPlex account' => $id === null ? 'none' : '#' . $id . ' (' . htmlspecialchars((string) $params['username']) . ')'];
}

// --- helpers -----------------------------------------------------------------

function openplex_singleSignOn(array $params, $action)
{
    try {
        $api = openplex_client($params);
        $id = openplex_accountId($params, $api);
        if ($id === null) {
            return ['success' => false, 'errorMsg' => 'The service has no OpenPlex account'];
        }
        $link = $api->post("/users/$id/login-link", ['expires_in_seconds' => 120]);
        $url = !empty($link['url']) ? $link['url'] : $api->panelUrl() . $link['path'];
        return ['success' => true, 'redirectTo' => $url];
    } catch (Exception $e) {
        openplex_log($action, $params, $e);
        return ['success' => false, 'errorMsg' => $e->getMessage()];
    }
}

/**
 * Runs $fn with the service's account ID; WHMCS gets 'success' or the error.
 */
function openplex_userAction(array $params, $action, callable $fn)
{
    try {
        $api = openplex_client($params);
        $id = openplex_accountId($params, $api);
        if ($id === null) {
            return 'The service has no OpenPlex account';
        }
        $fn($api, $id);
        return 'success';
    } catch (Exception $e) {
        openplex_log($action, $params, $e);
        return $e->getMessage();
    }
}

function openplex_client(array $params)
{
    $key = trim((string) ($params['serveraccesshash'] ?? ''));
    if ($key === '') {
        $key = trim((string) ($params['serverpassword'] ?? ''));
    }
    if ($key === '') {
        throw new RuntimeException('Put an OpenPlex API key in the server\'s Access Hash (or Password) field');
    }
    $host = trim((string) ($params['serverhostname'] ?? ''));
    if ($host === '') {
        $host = trim((string) ($params['serverip'] ?? ''));
    }
    if ($host === '') {
        throw new RuntimeException('The server has no hostname or IP address');
    }
    $secure = !empty($params['serversecure']);
    $port = (int) ($params['serverport'] ?? 0);
    if ($port === 0) {
        $port = $secure ? 443 : 7080;
    }
    return new Client($host, $port, $secure, $key, function ($request, $response) use ($params) {
        logModuleCall('openplex', $request['method'] . ' ' . $request['path'], $request['body'], $response, null, []);
    });
}

/**
 * The service's OpenPlex account ID: the one stored on creation or, for
 * services created another way (imported), the account with the service's
 * username and the client's e-mail address — the username alone could be
 * another customer's.
 */
function openplex_accountId(array $params, Client $api)
{
    $id = openplex_storedId($params);
    if ($id !== null) {
        return $id;
    }
    $username = (string) ($params['username'] ?? '');
    $email = strtolower(trim((string) ($params['clientsdetails']['email'] ?? '')));
    if ($username === '' || $email === '') {
        return null;
    }
    foreach ($api->get('/users') as $user) {
        if (($user['username'] ?? '') === $username && strtolower((string) ($user['email'] ?? '')) === $email) {
            $params['model']->serviceProperties->save([OPENPLEX_ID_FIELD => (string) $user['id']]);
            return (int) $user['id'];
        }
    }
    return null;
}

function openplex_storedId(array $params)
{
    $id = $params['customfields'][OPENPLEX_ID_FIELD] ?? '';
    if ($id === '' && isset($params['model'])) {
        $id = (string) $params['model']->serviceProperties->get(OPENPLEX_ID_FIELD);
    }
    return ctype_digit((string) $id) && (int) $id > 0 ? (int) $id : null;
}

function openplex_limits(array $params)
{
    return [
        'max_domains' => max(0, (int) openplex_option($params, 2, '0')),
        'max_databases' => max(0, (int) openplex_option($params, 3, '0')),
        'max_db_size_mb' => max(0, (int) openplex_option($params, 4, '0')),
    ];
}

function openplex_option(array $params, $n, $default)
{
    $v = $params['configoption' . $n] ?? '';
    return $v === '' || $v === null ? $default : (string) $v;
}

/**
 * A username OpenPlex accepts (3–32 characters: lowercase letters, digits,
 * "_", "." and "-", starting with a letter), from the one WHMCS suggests or
 * the domain.
 */
function openplex_username(array $params)
{
    foreach ([$params['username'] ?? '', explode('.', (string) ($params['domain'] ?? ''))[0]] as $base) {
        $u = preg_replace('/[^a-z0-9_.-]/', '', strtolower((string) $base));
        $u = ltrim((string) $u, '0123456789_.-');
        if (strlen($u) >= 3) {
            return substr($u, 0, 32);
        }
    }
    return 'user' . random_int(10000, 99999);
}

function openplex_passwordOk($p)
{
    return strlen($p) >= 10 && preg_match('/[A-Za-z]/', $p) && preg_match('/[0-9]/', $p);
}

function openplex_generatePassword()
{
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $p = '';
        for ($i = 0; $i < 16; $i++) {
            $p .= $chars[random_int(0, strlen($chars) - 1)];
        }
    } while (!openplex_passwordOk($p));
    return $p;
}

function openplex_log($action, array $params, Exception $e)
{
    logModuleCall('openplex', $action, ['serviceid' => $params['serviceid'] ?? null], $e->getMessage(), null, []);
}
