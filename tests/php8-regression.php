<?php

namespace LdapTools\Tests {
    require __DIR__.'/../vendor/autoload.php';

    use LdapTools\Connection\LdapConnection;
    use LdapTools\Enums\LdapControlOid;
    use LdapTools\Exception\LdapConnectionException;
    use LdapTools\Operation\QueryOperation;

    class State
    {
        public static $controls = [];
        public static $requests = [];
        public static $pages = [];
        public static $results = [];
        public static $freed = 0;
        public static $parseFailure = false;
        public static $readFailure = false;
        public static $restoreFailure = false;

        public static function reset(array $pages = [])
        {
            self::$controls = [['oid' => '1.2.3', 'iscritical' => true, 'value' => 'original']];
            self::$requests = self::$results = [];
            self::$pages = $pages;
            self::$freed = 0;
            self::$parseFailure = self::$readFailure = self::$restoreFailure = false;
        }

        public static function check($condition, $message)
        {
            if (!$condition) {
                throw new \RuntimeException($message);
            }
        }

        public static function throws(callable $callback, $message)
        {
            try {
                $callback();
            } catch (LdapConnectionException $e) {
                self::check(str_contains($e->getMessage(), $message), $e->getMessage());
                return $e;
            }
            throw new \RuntimeException('Expected exception containing: '.$message);
        }
    }

    class Connection extends LdapConnection
    {
        public function __construct(public $testResource)
        {
        }

        public function getResource()
        {
            return $this->testResource;
        }

        public function getLastErrorNumber()
        {
            return 80;
        }

        public function getLastError()
        {
            return 'test LDAP error';
        }

        public function getDiagnosticMessage()
        {
            return 'test diagnostic';
        }
    }

    class Query extends QueryOperation
    {
        public function getLdapFunction()
        {
            return __NAMESPACE__.'\\search';
        }
    }

    function search($connection, ...$args)
    {
        State::$requests[] = State::$controls;
        $control = array_values(array_filter(State::$controls, function ($control) {
            return $control['oid'] === LdapControlOid::PagedResults;
        }))[0] ?? null;
        if ($control && $control['value']['size'] === 0) {
            $page = ['count' => 0, 'cookie' => ''];
        } else {
            $page = array_shift(State::$pages);
        }
        if ($page === false) {
            return false;
        }
        State::check(is_array($page), 'Unexpected search request.');
        $result = fopen('php://memory', 'r+');
        State::$results[(int) $result] = $page;
        return $result;
    }

    function parse($result, &$code, &$dn, &$message, &$refs, &$controls)
    {
        $page = State::$results[(int) $result];
        $code = $page['code'] ?? 0;
        $message = 'test result error';
        $controls = array_key_exists('cookie', $page)
            ? [LdapControlOid::PagedResults => ['value' => ['cookie' => $page['cookie']]]]
            : [];
        return !State::$parseFailure;
    }

    function free($result)
    {
        unset(State::$results[(int) $result]);
        fclose($result);
        State::$freed++;
        return true;
    }
}

namespace LdapTools\Connection {
    use LdapTools\Tests\State;

    function ldap_get_option($connection, $option, &$value)
    {
        $value = State::$controls;
        return true;
    }

    function ldap_set_option($connection, $option, $value)
    {
        if (State::$restoreFailure && count($value) === 1) {
            return false;
        }
        State::$controls = $value;
        return true;
    }

    function ldap_parse_result($connection, $result, &$code, &$dn, &$message, &$refs, &$controls)
    {
        return \LdapTools\Tests\parse($result, $code, $dn, $message, $refs, $controls);
    }

    function ldap_count_entries($connection, $result)
    {
        return State::$results[(int) $result]['count'];
    }

    function ldap_free_result($result)
    {
        return \LdapTools\Tests\free($result);
    }
}

namespace LdapTools\Operation\Handler {
    use LdapTools\Tests\State;

    function ldap_parse_result($connection, $result, &$code, &$dn, &$message, &$refs, &$controls)
    {
        return \LdapTools\Tests\parse($result, $code, $dn, $message, $refs, $controls);
    }

    function ldap_get_entries($connection, $result)
    {
        if (State::$readFailure) {
            return false;
        }
        $page = State::$results[(int) $result];
        $entries = ['count' => $page['count']];
        for ($i = 0; $i < $page['count']; $i++) {
            $entries[] = ['dn' => [($page['prefix'] ?? 'user').$i]];
        }
        return $entries;
    }

    function ldap_free_result($result)
    {
        return \LdapTools\Tests\free($result);
    }
}

namespace LdapTools\Tests {
    use LdapTools\Connection\PageControl;
    use LdapTools\Operation\AddOperation;
    use LdapTools\Operation\Handler\OperationHandler;
    use LdapTools\Operation\Handler\QueryOperationHandler;

    // LDAP calls are replaced only in this test process; no directory connection is opened.
    $resource = fopen('php://memory', 'r+');
    $connection = new Connection($resource);
    $pager = new PageControl($connection);
    $handler = new QueryOperationHandler($pager);
    $handler->setConnection($connection);
    $query = (new Query('(objectClass=*)'))->setBaseDn('dc=test')->setUsePaging(true)->setPageSize(1000);
    $tests = [];

    $tests['multiple pages preserve controls and complete results'] = function () use ($handler, $query) {
        State::reset([
            ['count' => 1000, 'cookie' => 'next', 'prefix' => 'first'],
            ['count' => 25, 'cookie' => '', 'prefix' => 'last'],
        ]);
        $original = State::$controls;
        $entries = $handler->execute($query);
        State::check($entries['count'] === 1025 && count($entries) === 1026, 'Incomplete result shape.');
        State::check(count(array_unique(array_column(array_slice($entries, 1), 'dn'), SORT_REGULAR)) === 1025, 'Duplicate entries.');
        State::check(State::$freed === 2 && State::$results === [], 'Page results leaked.');
        foreach (State::$requests as $request) {
            State::check($request[0] === $original[0] && $request[1]['iscritical'], 'Other controls overwritten.');
        }
        State::check(State::$controls === $original, 'Controls not restored.');
    };

    $tests['limits stop on partial final page and abandon cursor'] = function () use ($handler, $query) {
        State::reset([['count' => 1000, 'cookie' => 'a'], ['count' => 500, 'cookie' => 'b']]);
        $limited = clone $query;
        $limited->setSizeLimit(1500);
        $entries = $handler->execute($limited);
        State::check($entries['count'] === 1500, 'Result limit not respected.');
        $sizes = array_map(fn ($request) => $request[1]['value']['size'], State::$requests);
        State::check($sizes === [1000, 500, 0], 'Incorrect page sizes or missing cursor cancellation.');
        State::check(State::$freed === 3, 'Cancellation result leaked.');
    };

    $tests['empty results and subsequent queries reset pager state'] = function () use ($handler, $query) {
        State::reset([['count' => 0, 'cookie' => '']]);
        State::check($handler->execute($query) === ['count' => 0], 'Empty result shape changed.');
        State::reset([['count' => 1, 'cookie' => '']]);
        State::check($handler->execute($query)['count'] === 1, 'Pager was not reusable.');
    };

    $tests['binary cookies including zero are not treated as empty'] = function () use ($handler, $query) {
        State::reset([['count' => 1, 'cookie' => '0'], ['count' => 0, 'cookie' => '']]);
        $handler->execute($query);
        State::check(count(State::$requests) === 2 && State::$requests[1][1]['value']['cookie'] === '0', 'Cookie lost.');
    };

    $tests['missing response controls fail instead of truncating silently'] = function () use ($handler, $query) {
        State::reset([['count' => 1]]);
        $original = State::$controls;
        State::throws(fn () => $handler->execute($query), 'cookie');
        State::check(State::$controls === $original && State::$freed === 1, 'Failure cleanup missing.');
    };

    $tests['LDAP result errors and parse errors are surfaced'] = function () use ($handler, $query) {
        State::reset([['count' => 1, 'cookie' => '', 'code' => 4]]);
        State::throws(fn () => $handler->execute($query), 'failed (4)');
        State::reset([['count' => 1, 'cookie' => '']]);
        State::$parseFailure = true;
        State::throws(fn () => $handler->execute($query), 'response');
        State::check(State::$freed === 1, 'Parse failure leaked a result.');
    };

    $tests['failed subsequent search restores controls and abandons cursor'] = function () use ($handler, $query) {
        State::reset([['count' => 1000, 'cookie' => 'a'], false]);
        $original = State::$controls;
        State::throws(fn () => $handler->execute($query), 'LDAP search failed');
        State::check(State::$controls === $original && State::$freed === 2, 'Failed search cleanup missing.');
    };

    $tests['entry decoding failures throw and free results'] = function () use ($handler, $query) {
        State::reset([['count' => 1, 'cookie' => '']]);
        State::$readFailure = true;
        State::throws(fn () => $handler->execute($query), 'read the LDAP search entries');
        State::check(State::$freed === 1, 'Entry failure leaked a result.');
    };

    $tests['cleanup errors retain the original failure'] = function () use ($handler, $query) {
        State::reset([['count' => 1]]);
        State::$restoreFailure = true;
        $error = State::throws(fn () => $handler->execute($query), 'cleanup failed');
        State::check($error->getPrevious() instanceof \Throwable, 'Original error lost.');
        State::check(str_contains($error->getPrevious()->getMessage(), 'cookie'), 'Wrong original error.');
    };

    $tests['invalid connections cannot report a successful write'] = function () {
        $handler = new OperationHandler();
        $handler->setConnection(new Connection(null));
        State::throws(fn () => $handler->execute(new AddOperation('cn=test')), 'Operation Error');
    };

    $tests['invalid page configuration fails explicitly'] = function () use ($pager) {
        State::throws(fn () => $pager->start(0), 'page size');
        State::throws(fn () => $pager->start(1000, -1), 'size limit');
    };

    $tests['disabled paging does not alter server controls'] = function () use ($handler, $query) {
        State::reset([['count' => 1]]);
        $original = State::$controls;
        $unpaged = clone $query;
        $unpaged->setUsePaging(false);
        State::check($handler->execute($unpaged)['count'] === 1, 'Unpaged query failed.');
        State::check(State::$requests === [$original] && State::$controls === $original, 'Unpaged query changed controls.');
    };

    $tests['unpaged LDAP size-limit errors cannot appear successful'] = function () use ($handler, $query) {
        State::reset([['count' => 1000, 'code' => 4]]);
        $unpaged = clone $query;
        $unpaged->setUsePaging(false);
        State::throws(fn () => $handler->execute($unpaged), 'failed (4)');
        State::check(State::$freed === 1, 'Unpaged failure leaked its result.');
    };

    $tests['limits smaller than and equal to one page cancel correctly'] = function () use ($handler, $query) {
        foreach ([25, 1000] as $limit) {
            State::reset([['count' => $limit, 'cookie' => 'more']]);
            $limited = clone $query;
            $limited->setSizeLimit($limit);
            State::check($handler->execute($limited)['count'] === $limit, 'Incorrect limited result.');
            State::check(State::$requests[0][1]['value']['size'] === $limit, 'Initial page size ignores limit.');
            State::check(count(State::$requests) === 2 && State::$requests[1][1]['value']['size'] === 0, 'Limit cursor not cancelled.');
        }
    };

    $tests['short pages count actual entries toward a result limit'] = function () use ($handler, $query) {
        State::reset([
            ['count' => 500, 'cookie' => 'a'],
            ['count' => 500, 'cookie' => 'b'],
            ['count' => 500, 'cookie' => 'c'],
        ]);
        $limited = clone $query;
        $limited->setSizeLimit(1500);
        State::check($handler->execute($limited)['count'] === 1500, 'Short pages stopped the search prematurely.');
        $sizes = array_map(fn ($request) => $request[1]['value']['size'], State::$requests);
        State::check($sizes === [1000, 1000, 500, 0], 'Short-page limits calculated incorrectly.');
    };

    try {
        foreach ($tests as $name => $test) {
            $test();
            State::check(State::$results === [], 'Unreleased LDAP results in '.$name);
            echo 'PASS '.$name.PHP_EOL;
        }
        echo count($tests).' regression tests passed.'.PHP_EOL;
    } finally {
        fclose($resource);
    }
}
