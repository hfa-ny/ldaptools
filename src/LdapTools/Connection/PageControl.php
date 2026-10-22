<?php
/**
 * This file is part of the LdapTools package.
 *
 * (c) Chad Sikorra <Chad.Sikorra@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace LdapTools\Connection;

use LdapTools\Enums\LdapControlOid;
use LdapTools\Exception\LdapConnectionException;

/**
 * Handles paging control for the LDAP connection.
 *
 * @author Chad Sikorra <Chad.Sikorra@gmail.com>
 */
class PageControl
{
    /**
     * @var LdapConnectionInterface
     */
    protected $connection;

    /**
     * @var null|string
     */
    protected $cookie = null;

    /**
     * @var int The page size for the paging operation.
     */
    protected $pageSize = 0;

    /**
     * @var int The size limit for the paging operation.
     */
    protected $sizeLimit = 0;

    /**
     * @var int The result set number the paging operation is currently on.
     */
    protected $resultNumber = 0;

    /**
     * @var bool Whether or not paging is actually active for the connection.
     */
    protected $isEnabled = true;

    /**
     * @var array|null Server controls to restore after paging.
     */
    protected $originalControls = null;

    /**
     * @param LdapConnectionInterface $connection
     */
    public function __construct(LdapConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Start a paging operation by setting up the cookie and the page size. Optionally set a size limit.
     *
     * @param int $pageSize
     * @param int $sizeLimit
     * @throws LdapConnectionException
     */
    public function start($pageSize, $sizeLimit = 0)
    {
        if ($this->isEnabled) {
            if ($pageSize <= 0 || $sizeLimit < 0) {
                throw new LdapConnectionException('The page size must be positive and the size limit cannot be negative.');
            }
            $this->cookie = '';
            $this->resultNumber = 0;
            $this->pageSize = ($sizeLimit && $sizeLimit < $pageSize) ? $sizeLimit : $pageSize;
            $this->sizeLimit = $sizeLimit;
        }
    }

    /**
     * End a paging operation.
     *
     * @throws LdapConnectionException
     */
    public function end()
    {
        if ($this->isEnabled) {
            try {
                $this->resetPagingControl();
            } finally {
                $this->cookie = null;
                $this->resultNumber = 0;
                $this->originalControls = null;
            }
        }
    }

    /**
     * Signifies to the connection to expect the next paged result with the current cookie and page size.
     *
     * @throws LdapConnectionException
     */
    public function next()
    {
        if (!$this->isEnabled) {
            return;
        }
        // If the size limit exceeds the page size, and the next page would exceed the limit, reduce the page size...
        if ($this->sizeLimit && ($this->resultNumber + $this->pageSize) > $this->sizeLimit) {
            $this->pageSize = $this->sizeLimit - $this->resultNumber;
        }
        $resource = $this->connection->getResource();
        if (!($resource instanceof \LDAP\Connection || is_resource($resource))) {
            throw new LdapConnectionException('Cannot enable paging without a valid LDAP connection.');
        }
        if ($this->originalControls === null) {
            $controls = null;
            $read = @ldap_get_option($resource, LDAP_OPT_SERVER_CONTROLS, $controls);
            // OpenLDAP returns false/null when no server controls have been configured.
            if (!$read && $controls !== null && $controls !== []) {
                throw new LdapConnectionException('Unable to read LDAP server controls before paging.');
            }
            $this->originalControls = $controls ?: [];
        }
        $controls = array_values(array_filter($this->originalControls, function (array $control) {
            return $control['oid'] !== LdapControlOid::PagedResults;
        }));
        $controls[] = [
            'oid' => LdapControlOid::PagedResults,
            'iscritical' => true,
            'value' => ['size' => $this->pageSize, 'cookie' => $this->cookie],
        ];
        $result = @ldap_set_option($resource, LDAP_OPT_SERVER_CONTROLS, $controls);
        if (!$result) {
            throw new LdapConnectionException(sprintf(
                'Unable to enable paged results (%s): %s',
                $this->connection->getLastErrorNumber(),
                $this->connection->getLastError()
            ), (int) $this->connection->getLastErrorNumber());
        }
    }

    /**
     * Updating the paging operation based on the result resource returned from a query.
     *
     * @param resource|\LDAP\Result $result
     * @throws LdapConnectionException
     */
    public function update($result)
    {
        if (!$this->isEnabled) {
            return;
        }
        $errcode = $dn = $errmsg = $refs = null;
        $controls = [];
        $resource = $this->connection->getResource();
        $response = (($resource instanceof \LDAP\Connection || is_resource($resource)) && ($result instanceof \LDAP\Result || is_resource($result)))
            ? @ldap_parse_result($resource, $result, $errcode, $dn, $errmsg, $refs, $controls)
            : false;
        if (!$response) {
            throw new LdapConnectionException(
                sprintf('Unable to set paged results response: %s', $this->connection->getLastError())
            );
        }
        if ($errcode !== 0) {
            throw new LdapConnectionException(sprintf('LDAP paged search failed (%s): %s', $errcode, $errmsg), (int) $errcode);
        }
        $cookie = $controls[LdapControlOid::PagedResults]['value']['cookie'] ?? null;
        if (!is_string($cookie)) {
            throw new LdapConnectionException('The LDAP server did not return a valid paged-results cookie.');
        }
        $this->cookie = $cookie;
        $count = ldap_count_entries($resource, $result);
        if ($count < 0) {
            throw new LdapConnectionException('Unable to count the LDAP paged-search entries.');
        }
        $this->resultNumber += $count;
    }

    /**
     * Resets the paging control so that read operations work after a paging operation is used.
     *
     * @throws LdapConnectionException
     */
    public function resetPagingControl()
    {
        if ($this->originalControls === null) {
            return;
        }
        $resource = $this->connection->getResource();
        $result = ($resource instanceof \LDAP\Connection || is_resource($resource))
            ? @ldap_set_option($resource, LDAP_OPT_SERVER_CONTROLS, $this->originalControls)
            : false;
        if (!$result) {
            throw new LdapConnectionException(sprintf(
                'Unable to reset paged results control for read operation: %s',
                $this->connection->getLastError()
            ));
        }
    }

    /**
     * Returns whether or not a paging operation is active based on the status of the paging cookie (omnomnom).
     *
     * @return bool
     */
    public function isActive()
    {
        $active = ($this->cookie !== null && $this->cookie !== '');
        
        if ($this->sizeLimit && $this->resultNumber >= $this->sizeLimit) {
            $active = false;
        }
        
        return $active;
    }

    /**
     * Release an unfinished server-side search using the last successful cookie (RFC 2696).
     */
    public function abandon(callable $search)
    {
        if (!$this->isEnabled || $this->cookie === null || $this->cookie === '') {
            return;
        }
        $this->pageSize = 0;
        $this->next();
        $result = $search();
        if (!($result instanceof \LDAP\Result || is_resource($result))) {
            throw new LdapConnectionException('Unable to abandon the LDAP paged search.');
        }
        try {
            $this->update($result);
            if ($this->cookie !== '') {
                throw new LdapConnectionException('The LDAP server did not finish abandoning the paged search.');
            }
        } finally {
            ldap_free_result($result);
        }
    }

    /**
     * Check whether or not paging is active. If it is not active, the controls methods will not actually do anything.
     *
     * @return bool
     */
    public function isEnabled()
    {
        return $this->isEnabled;
    }

    /**
     * Set whether or not paging is active and should be used.
     *
     * @param bool $enabled
     */
    public function setIsEnabled($enabled)
    {
        $this->isEnabled = (bool) $enabled;
    }
}
