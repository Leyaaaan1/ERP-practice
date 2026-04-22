<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


class OdooService
{
    private string $url;
    private string $db;
    private string $username;
    private string $apiKey;
    private ?int $uid = null;
    private ?string $sessionId = null;

    public function __construct()
    {
        $this->url      = rtrim(config('services.odoo.url'), '/');
        $this->db       = config('services.odoo.db');
        $this->username = config('services.odoo.username');
        $this->apiKey   = config('services.odoo.api_key');
    }

    // ──────────────────────────────────────────────────────────────
    // AUTHENTICATION
    // ──────────────────────────────────────────────────────────────

    public function authenticate(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])
            ->withoutVerifying()
            ->post($this->url . '/web/session/authenticate', [
                'jsonrpc' => '2.0',
                'method'  => 'call',
                'id'      => 1,
                'params'  => [
                    'db'       => $this->db,
                    'login'    => $this->username,
                    'password' => $this->apiKey,
                ],
            ]);

        $body = $response->json();

        if (isset($body['error'])) {
            $msg = $body['error']['data']['message'] ?? json_encode($body['error']);
            throw new Exception("Odoo authentication failed: {$msg}");
        }

        $uid = $body['result']['uid'] ?? null;

        if (!$uid) {
            throw new Exception(
                'Odoo authentication failed. Check ODOO_USERNAME and ODOO_API_KEY in your .env.'
            );
        }

        $this->uid = $uid;

        // Try body first, then fall back to Set-Cookie response header
        $this->sessionId = $body['result']['session_id'] ?? null;

        if (!$this->sessionId) {
            $setCookie = $response->header('Set-Cookie');
            if ($setCookie && preg_match('/session_id=([^;]+)/', $setCookie, $matches)) {
                $this->sessionId = $matches[1];
            }
        }

        Log::info('Odoo authenticated', [
            'uid'        => $uid,
            'session_id' => $this->sessionId ? 'found' : 'MISSING',
        ]);

        return $this->uid;
    }
    // ──────────────────────────────────────────────────────────────
    // CORE CRUD METHODS (same API as before — only transport changed)
    // ──────────────────────────────────────────────────────────────

    /**
     * Search for records matching a domain filter.
     * Returns array of matching record IDs.
     *
     * @param  string  $model   e.g. 'sale.order', 'res.partner', 'product.template'
     * @param  array   $domain  e.g. [['state', '=', 'sale']]
     * @return array   Array of integer IDs
     */
    public function search(string $model, array $domain = []): array
    {
        return $this->callKw($model, 'search', [$domain]);
    }

    /**
     * Read specific fields from records by IDs.
     *
     * @param  string  $model
     * @param  array   $ids     Record IDs from search()
     * @param  array   $fields  e.g. ['name', 'state', 'amount_total']
     * @return array
     */
    public function read(string $model, array $ids, array $fields = []): array
    {
        return $this->callKw($model, 'read', [$ids], ['fields' => $fields]);
    }

    /**
     * Search AND read in one call — most efficient for listing records.
     *
     * @param  string  $model
     * @param  array   $domain
     * @param  array   $fields
     * @param  int     $limit   0 = no limit
     * @return array
     */
    public function searchRead(string $model, array $domain = [], array $fields = [], int $limit = 0): array
    {
        $kwargs = ['fields' => $fields];

        if ($limit > 0) {
            $kwargs['limit'] = $limit;
        }

        return $this->callKw($model, 'search_read', [$domain], $kwargs);
    }

    /**
     * Create a new record. Returns the new record's ID.
     *
     * @param  string  $model
     * @param  array   $values  Field => value pairs
     * @return int
     */
    public function create(string $model, array $values): int
    {
        return $this->callKw($model, 'create', [$values]);
    }

    /**
     * Update existing records. Returns true on success.
     *
     * @param  string  $model
     * @param  array   $ids
     * @param  array   $values
     * @return bool
     */
    public function write(string $model, array $ids, array $values): bool
    {
        return $this->callKw($model, 'write', [$ids, $values]);
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
        return $this->callKw($model, 'unlink', [$ids]);
    }

    /**
     * Execute any Odoo method — used for workflow actions.
     * e.g. action_confirm, action_cancel, button_validate
     *
     * @param  string  $model
     * @param  string  $method
     * @param  array   $args
     * @param  array   $kwargs
     * @return mixed
     */
    public function execute(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        return $this->callKw($model, $method, $args, $kwargs);
    }

    // ──────────────────────────────────────────────────────────────
    // JSON-RPC TRANSPORT
    // ──────────────────────────────────────────────────────────────

    /**
     * Call any Odoo model method via /web/dataset/call_kw.
     *
     * LEARNING NOTE:
     * This is the single entry point for all Odoo operations after login.
     * Every CRUD call (search, read, create, write...) goes through here.
     *
     * The JSON-RPC body structure:
     * {
     *   "jsonrpc": "2.0",
     *   "method":  "call",
     *   "params": {
     *     "model":  "product.template",
     *     "method": "create",
     *     "args":   [{ "name": "Widget", "list_price": 100.0 }],
     *     "kwargs": {}
     *   }
     * }
     */
    private function callKw(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        if ($this->uid === null) {
            $this->authenticate();
        }

        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($this->sessionId) {
            $headers['Cookie'] = 'session_id=' . $this->sessionId;
        }

        $payload = [
            'jsonrpc' => '2.0',
            'method'  => 'call',
            'id'      => rand(1, 99999),
            'params'  => [
                'model'   => $model,
                'method'  => $method,
                'args'    => $args,
                'kwargs'  => (object) $kwargs,
            ],
        ];

        $response = Http::withHeaders($headers)
            ->withoutVerifying()
            ->post($this->url . '/web/dataset/call_kw', $payload);

        if ($response->failed()) {
            throw new Exception(
                "Odoo HTTP error {$response->status()} calling {$model}.{$method}"
            );
        }

        $body = $response->json();

        if (isset($body['error'])) {
            $errData    = $body['error']['data'] ?? [];
            $errMessage = $errData['message'] ?? $body['error']['message'] ?? json_encode($body['error']);
            $errName    = $errData['name'] ?? 'OdooError';

            Log::error("Odoo {$errName} on {$model}.{$method}", [
                'message' => $errMessage,
                'args'    => $args,
            ]);

            throw new Exception("Odoo error [{$errName}] on {$model}.{$method}: {$errMessage}");
        }

        return $body['result'] ?? null;
    }}