# 📦 Laravel ERP + Odoo Integration — Learning Project

A simplified **ERP (Enterprise Resource Planning)** backend built with Laravel,
integrated with **Odoo Sales** via JSON-RPC. Designed to teach junior developers
how a real ERP system works — from local inventory management to syncing data
with an external ERP platform like Odoo.

---

## 🎯 What This Project Covers

| Concept | Description |
|---|---|
| **Sales Flow** | Customer places order → inventory is deducted automatically |
| **Purchase Flow** | Supplier delivers stock → inventory increases on receive |
| **Inventory Tracking** | Real-time stock levels with automatic adjustments |
| **Product Management** | SKU, barcode, pricing, and stock thresholds |
| **Odoo Integration** | Products and sales orders sync to Odoo Sales via JSON-RPC |
| **Service Layer Pattern** | Business logic separated from controllers |
| **Database Transactions** | Atomic updates — either everything succeeds or nothing does |

---

## 🏗️ Project Architecture

```
erp-laravel/
├── app/
│   ├── Http/Controllers/
│   │   ├── CustomerController.php
│   │   ├── ProductController.php        ← Supports ?push_to_odoo=true
│   │   ├── InventoryController.php
│   │   ├── SalesOrderController.php
│   │   └── PurchaseOrderController.php
│   ├── Models/
│   │   ├── Customer.php
│   │   ├── Product.php                  ← Has odoo_id column for sync tracking
│   │   ├── Inventory.php
│   │   ├── SalesOrder.php               ← Has odoo_id column for sync tracking
│   │   └── ...
│   └── Services/
│       ├── OdooService.php              ← JSON-RPC transport layer
│       ├── OdooSalesService.php         ← Odoo business logic (products, orders)
│       ├── SalesOrderService.php        ← Local sales logic + Odoo push
│       ├── PurchaseOrderService.php
│       └── InventoryService.php
├── database/migrations/
└── routes/api.php
```

---

## ⚙️ Setup

### Requirements
- PHP 8.2+
- Composer
- Laravel 11
- SQLite (default) or MySQL/PostgreSQL
- An Odoo instance (Odoo.com free trial or self-hosted)

### 1. Clone & Install

```bash
git clone https://github.com/yourname/erp-laravel.git
cd erp-laravel
composer install
cp .env.example .env
php artisan key:generate
```

### 2. Configure Database & Odoo

Edit `.env`:

```env
# Database
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite

# Odoo Integration
ODOO_URL=https://yourcompany.odoo.com
ODOO_DB=yourcompany
ODOO_USERNAME=your@email.com
ODOO_API_KEY=your_odoo_login_password
```

> **Note:** `ODOO_API_KEY` should be your Odoo **login password**, not the API token.
> The integration uses JSON-RPC session authentication which requires your actual credentials.

### 3. Migrate & Seed

```bash
php artisan migrate
php artisan db:seed
```

### 4. Start Server

```bash
php artisan serve
# API available at http://localhost:8000/api
```

### 5. Verify Odoo Connection

```bash
curl http://localhost:8000/api/test/odoo
```

A successful response looks like:
```json
{
  "success": true,
  "message": "Odoo connection is working!",
  "data": {
    "authenticated_uid": 2,
    "total_products": 0,
    "sample_customers": []
  }
}
```

---

## 🔄 ERP Business Flows

### Sales Flow (Outbound — stock goes DOWN)

```
Customer submits order
  └── POST /api/sales-orders
        └── Validates stock is available for each item
              └── Creates SalesOrder + SalesOrderItems in SQLite
                    └── Deducts quantity from local inventory
                          └── Pushes confirmed order to Odoo Sales (sale.order)
```

The key principle here is **atomicity** — if stock validation fails for even one item,
nothing is saved. Laravel's `DB::transaction()` ensures no partial writes ever happen.
The Odoo sync happens after the local transaction succeeds. If Odoo is unreachable,
the local order is still saved and the sync failure is logged for retry.

---

### Purchase Flow (Inbound — stock goes UP)

```
Supplier delivers goods
  └── POST /api/purchase-orders         ← Creates the PO (no stock change yet)
        └── POST /api/purchase-orders/{id}/receive
              └── Increases inventory for each item received
                    └── Marks order as "received"
```

The two-step design mirrors real warehouse operations — a purchase order is created
when you place the order with a supplier, but stock only increases when goods
physically arrive and are confirmed received.

---

### Inventory Flow

```
Initial stock (from seeder)
  ↕  Sales Orders   → deduct stock
  ↕  Purchase Orders → add stock on receive
  ↕  Manual Adjustments → corrections, write-offs
  =  Current Stock Level
```

Think of inventory like a bank ledger — every sale is a debit, every received
purchase is a credit, and the current stock is the running balance.

---

## 🔗 Odoo Integration

This project connects to Odoo via **JSON-RPC** (not XML-RPC, which is blocked on
Odoo.com free instances). The integration uses two Odoo endpoints:

| Endpoint | Purpose |
|---|---|
| `/web/session/authenticate` | Login and get a session cookie |
| `/web/dataset/call_kw` | Call any Odoo model method (create, read, write, etc.) |

### How Syncing Works

Each synced record stores the Odoo ID locally in an `odoo_id` column. This acts
as a foreign key between your Laravel database and Odoo — before creating a
duplicate, the service checks if `odoo_id` is already set.

```
Laravel Product (id: 14)
  └── odoo_id: 42  ← points to product.template record 42 in Odoo
```

### What Gets Synced

| Laravel | Odoo Model | Triggered By |
|---|---|---|
| `Product` | `product.template` | `POST /api/products?push_to_odoo=true` |
| `Customer` | `res.partner` | Automatically when pushing a sales order |
| `SalesOrder` | `sale.order` | `POST /api/sales-orders` (auto) |

### Syncing a Product to Odoo

Add `push_to_odoo=true` as a query parameter when creating or updating a product:

```bash
# Create product and sync to Odoo
curl -X POST http://localhost:8000/api/products \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Wireless Mouse",
    "sku": "MOUSE-001",
    "price": 850.00,
    "cost": 500.00,
    "unit": "piece",
    "initial_stock": 50,
    "push_to_odoo": true
  }'

# Sync an existing product to Odoo
curl -X PUT http://localhost:8000/api/products/1 \
  -H "Content-Type: application/json" \
  -d '{ "push_to_odoo": true }'
```

A successful sync returns `"odoo_synced": true` and an `odoo_id` in the response.

### Creating a Sales Order (auto-syncs to Odoo)

```bash
curl -X POST http://localhost:8000/api/sales-orders \
  -H "Content-Type: application/json" \
  -d '{
    "customer_id": 1,
    "notes": "Rush delivery",
    "items": [
      { "product_id": 1, "quantity": 2, "unit_price": 850.00 }
    ]
  }'
```

This creates the order locally, deducts stock, and pushes to Odoo in one call.
The customer and product are auto-synced to Odoo if they haven't been already.

---

## 🧠 Key Concepts for Junior Developers

### 1. Why JSON-RPC instead of XML-RPC?
Odoo supports both protocols, but Odoo.com free instances block `/xmlrpc/2/*`
endpoints at the network level. JSON-RPC via `/web/dataset/call_kw` uses the
same authentication layer as the Odoo web browser interface — so it's never blocked.

### 2. Idempotency in sync
The `syncProductToOdoo()` and `syncCustomerToOdoo()` methods always check for
an existing `odoo_id` before creating. This prevents duplicate records in Odoo
if the same sync is triggered multiple times.

### 3. Graceful degradation
Odoo sync is always wrapped in a `try/catch`. If Odoo is down or returns an error,
the local Laravel operation (order creation, product save) still succeeds. The
error is logged and can be retried later via a job queue.

### 4. Service Layer
Business logic lives in `Services/`, not `Controllers/`. Controllers only handle
HTTP input/output. This makes the ERP logic testable and reusable independently
of the HTTP layer.

### 5. Odoo domain filters
When querying Odoo, filters follow a domain syntax: `[['field', 'operator', 'value']]`.
Multiple conditions are ANDed by default. Example: `[['state', '=', 'sale'], ['amount_total', '>', 1000]]`.

---

## 📡 API Quick Reference

Base URL: `http://localhost:8000/api`

| Module | Endpoints |
|---|---|
| Customers | `GET/POST /customers`, `GET/PUT/DELETE /customers/{id}` |
| Suppliers | `GET/POST /suppliers` |
| Products | `GET/POST /products`, `GET/PUT /products/{id}`, barcode & SKU lookup |
| Inventory | `GET /inventory`, `GET /inventory/low-stock`, `POST /inventory/{id}/adjust` |
| Sales Orders | `GET/POST /sales-orders`, `GET /sales-orders/{id}`, `POST /sales-orders/{id}/cancel` |
| Purchase Orders | `GET/POST /purchase-orders`, `GET /purchase-orders/{id}`, `POST /purchase-orders/{id}/receive` |
| Odoo Test | `GET /test/odoo` |