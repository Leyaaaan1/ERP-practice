<?php

namespace App\Http\Controllers;

use App\Services\OdooService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class TestOdooConnection extends Controller
{
    public function __construct(
        private readonly OdooService $odooService
    ) {}

    /**
     * Test Odoo connection
     */
    public function testConnection(): JsonResponse
    {
        try {
            // Step 1: Test authentication
            $uid = $this->odooService->authenticate();

            if (!$uid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication failed - no UID returned',
                ], 400);
            }

            // Step 2: Test search operation
            $products = $this->odooService->search('product.product', []);

            // Step 3: Test read operation
            $customers = $this->odooService->searchRead('res.partner', [['customer_rank', '>', 0]], [], 5);

            return response()->json([
                'success' => true,
                'message' => 'Odoo connection is working!',
                'data' => [
                    'authenticated_uid' => $uid,
                    'total_products' => count($products),
                    'sample_customers' => $customers,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Odoo connection failed',
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}