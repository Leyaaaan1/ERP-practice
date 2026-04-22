<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OdooService — XML-RPC wrapper for Odoo API
 *
 * LEARNING NOTE:
 * Odoo's XML-RPC works in 2 phases:
 *   1. Authenticate → get a user ID (uid)
 *   2. Use that uid to call methods on Odoo models
 */
class OdooService
{
    private string $url;
    private string $db;
    private string $username;
    private string $apiKey;
    private ?int $uid = null;

    public function __construct()
    {
        $this->url      = rtrim(config('services.odoo.url'), '/');
        $this->db       = config('services.odoo.db');
        $this->username = config('services.odoo.username');
        $this->apiKey   = config('services.odoo.api_key');
    }


    public function execute(string $model, string $method, array $args, array $kwargs = []): mixed
    {
        $uid = $this->authenticate();

        return $this->xmlRpcCall('/xmlrpc/2/object', 'execute_kw', [
            $this->db, $uid, $this->apiKey,
            $model, $method,
            $args,
            $kwargs,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // AUTHENTICATION
    // ──────────────────────────────────────────────────────────────

    /**
     * Authenticate with Odoo and return the user ID (uid).
     * The uid is required for all subsequent API calls.
     */
    public function authenticate(): int
    {
        if ($this->uid !== null) {
            return $this->uid; // Already authenticated this request
        }

        $response = $this->xmlRpcCall('/xmlrpc/2/common', 'authenticate', [
            $this->db,
            $this->username,
            $this->apiKey,
            [],
        ]);

        if (!$response || !is_int($response)) {
            throw new Exception('Odoo authentication failed. Check your credentials.');
        }

        $this->uid = $response;
        return $this->uid;
    }

    // ──────────────────────────────────────────────────────────────
    // CORE CRUD METHODS
    // ──────────────────────────────────────────────────────────────

    /**
     * Search for records matching a domain filter.
     *
     * @param  string  $model   e.g. 'sale.order', 'res.partner', 'product.product'
     * @param  array   $domain  e.g. [['state', '=', 'sale']]
     * @return array   Array of record IDs
     */
    public function search(string $model, array $domain = []): array
    {
        $uid = $this->authenticate();

        return $this->xmlRpcCall('/xmlrpc/2/object', 'execute_kw', [
            $this->db, $uid, $this->apiKey,
            $model, 'search',
            [$domain],
        ]);
    }

    /**
     * Read specific fields from records by IDs.
     *
     * @param  string  $model   Odoo model name
     * @param  array   $ids     Record IDs from search()
     * @param  array   $fields  Fields to retrieve, e.g. ['name', 'state', 'amount_total']
     * @return array   Array of records as associative arrays
     */
    public function read(string $model, array $ids, array $fields = []): array
    {
        $uid = $this->authenticate();

        return $this->xmlRpcCall('/xmlrpc/2/object', 'execute_kw', [
            $this->db, $uid, $this->apiKey,
            $model, 'read',
            [$ids],
            ['fields' => $fields],
        ]);
    }

    /**
     * Search AND read in one call (most efficient).
     *
     * @param  string  $model
     * @param  array   $domain  Filter conditions
     * @param  array   $fields  Fields to return
     * @param  int     $limit   Max records (0 = no limit)
     * @return array
     */
    public function searchRead(string $model, array $domain = [], array $fields = [], int $limit = 0): array
    {
        $uid = $this->authenticate();

        $kwargs = ['fields' => $fields];
        if ($limit > 0) {
            $kwargs['limit'] = $limit;
        }

        return $this->xmlRpcCall('/xmlrpc/2/object', 'execute_kw', [
            $this->db, $uid, $this->apiKey,
            $model, 'search_read',
            [$domain],
            $kwargs,
        ]);
    }

    /**
     * Create a new record in Odoo.
     *
     * @param  string  $model   Odoo model name
     * @param  array   $values  Field values for the new record
     * @return int     The new record's ID
     */
    public function create(string $model, array $values): int
    {
        $uid = $this->authenticate();

        return $this->xmlRpcCall('/xmlrpc/2/object', 'execute_kw', [
            $this->db, $uid, $this->apiKey,
            $model, 'create',
            [$values],
        ]);
    }

    /**
     * Update existing records.
     *
     * @param  string  $model   Odoo model name
     * @param  array   $ids     Record IDs to update
     * @param  array   $values  Fields to update
     * @return bool
     */
    public function write(string $model, array $ids, array $values): bool
    {
        $uid = $this->authenticate();

        return $this->xmlRpcCall('/xmlrpc/2/object', 'execute_kw', [
            $this->db, $uid, $this->apiKey,
            $model, 'write',
            [$ids, $values],
        ]);
    }

    /**
     * Delete records.
     *
     * @param  string  $model
     * @param  array   $ids
     * @return bool
     */
    public function unlink(string $model, array $ids): bool
    {
        $uid = $this->authenticate();

        return $this->xmlRpcCall('/xmlrpc/2/object', 'execute_kw', [
            $this->db, $uid, $this->apiKey,
            $model, 'unlink',
            [$ids],
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // XML-RPC LOW-LEVEL TRANSPORT
    // ──────────────────────────────────────────────────────────────

    /**
     * Make a raw XML-RPC call to Odoo.
     *
     * LEARNING NOTE:
     * XML-RPC is just HTTP POST with an XML body.
     * The request says: "call this method with these params"
     * The response says: "here's what it returned"
     */
    private function xmlRpcCall(string $endpoint, string $method, array $params): mixed
    {
        $xml = xmlrpc_encode_request($method, $params, ['encoding' => 'UTF-8']);

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml',
        ])->withBody($xml, 'text/xml')
            ->post($this->url . $endpoint);

        if ($response->failed()) {
            throw new Exception("Odoo XML-RPC HTTP error: " . $response->status());
        }

        $result = xmlrpc_decode($response->body(), 'UTF-8');

        if (is_array($result) && xmlrpc_is_fault($result)) {
            throw new Exception("Odoo XML-RPC fault [{$result['faultCode']}]: {$result['faultString']}");
        }

        return $result;
    }
}