<?php

require __DIR__.'/../vendor/autoload.php';

use LdapTools\Connection\LdapConnection;
use LdapTools\Connection\LdapControl;
use LdapTools\Connection\PageControl;
use LdapTools\Enums\LdapControlOid;

// ldap_connect only initializes a handle. This test never binds or sends a search.
$connection = new class extends LdapConnection {
    public function __construct()
    {
        $this->connection = ldap_connect('ldap://127.0.0.1:1');
        $this->isBound = true;
    }
};
$handle = $connection->getResource();
$pager = new PageControl($connection);

foreach ([[], [['oid' => '1.2.3', 'iscritical' => true, 'value' => 'unchanged']]] as $original) {
    if (!ldap_set_option($handle, LDAP_OPT_SERVER_CONTROLS, $original)) {
        throw new RuntimeException('Cannot initialize test controls.');
    }
    $pager->start(1000);
    $pager->next();
    $controls = null;
    if (!ldap_get_option($handle, LDAP_OPT_SERVER_CONTROLS, $controls)) {
        throw new RuntimeException('Cannot retrieve paging controls.');
    }
    if (!isset($controls[LdapControlOid::PagedResults]) || count($controls) !== count($original) + 1) {
        throw new RuntimeException('Native paging control round trip failed.');
    }
    if ($original && $controls['1.2.3'] !== $original[0]) {
        throw new RuntimeException('Native non-paging control changed.');
    }
    $pager->end();
    $restored = null;
    ldap_get_option($handle, LDAP_OPT_SERVER_CONTROLS, $restored);
    if (array_values($restored ?: []) !== $original) {
        throw new RuntimeException('Native controls were not restored.');
    }
}

$connection->setControl(new LdapControl('1.2.3', true, 'first'));
$connection->setControl(new LdapControl('1.2.4', true, 'second'));
$connection->setControl(new LdapControl('1.2.3', true, 'updated'));
$controls = null;
ldap_get_option($handle, LDAP_OPT_SERVER_CONTROLS, $controls);
if (count($controls) !== 2 || $controls['1.2.3']['value'] !== 'updated' || $controls['1.2.4']['value'] !== 'second') {
    throw new RuntimeException('Setting one control overwrote or duplicated another control.');
}

echo 'PASS native LDAP control round trips (no bind/search/network requests).'.PHP_EOL;
