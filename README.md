# 📦 Laravel ERP Learning Project

A simplified **ERP (Enterprise Resource Planning)** backend built with Laravel.
Designed to teach junior developers core ERP concepts: sales flow, purchase flow,
inventory management, and product tracking — with real, runnable code.

---

## 🎯 What You'll Learn

| ERP Concept | What This Project Demonstrates |
|---|---|
| **Sales Flow** | Customer places order → inventory is deducted |
| **Purchase Flow** | Supplier delivers stock → inventory increases |
| **Inventory Tracking** | Real-time stock levels, automatic adjustments |
| **Product Management** | SKU, barcode, pricing, stock thresholds |
| **Customer Management** | Single customer table, simple profile |
| **Database Transactions** | Atomic inventory updates (no partial writes) |
| **Service Layer** | Business logic separated from controllers |

---

## 🏗️ Project Architecture

```
erp-laravel/
├── app/
│   ├── Http/Controllers/
│   │   ├── CustomerController.php       ← CRUD for customers
│   │   ├── ProductController.php        ← Product + barcode lookup
│   │   ├── InventoryController.php      ← Stock view & manual adjustments
│   │   ├── SalesOrderController.php     ← Sales flow
│   │   └── PurchaseOrderController.php  ← Purchase flow
│   ├── Models/
│   │   ├── Customer.php
│   │   ├── Product.php
│   │   ├── Inventory.php
│   │   ├── SalesOrder.php
│   │   ├── SalesOrderItem.php
│   │   ├── Supplier.php
│   │   ├── PurchaseOrder.php
│   │   └── PurchaseOrderItem.php
│   └── Services/
│       ├── SalesOrderService.php        ← Sales business logic
│       ├── PurchaseOrderService.php     ← Purchase business logic
│       └── InventoryService.php         ← Inventory adjustment logic
├── database/
│   ├── migrations/                      ← All table definitions
│   └── seeders/                         ← Sample data
└── routes/api.php                       ← All REST endpoints
```

---

## ⚙️ Setup Instructions

### Requirements
- PHP 8.2+
- Composer
- MySQL 8.0+ or PostgreSQL 14+
- Laravel 11

### 1. Clone & Install

```bash
git clone https://github.com/yourname/erp-laravel.git
cd erp-laravel
composer install
cp .env.example .env
php artisan key:generate
```

### 2. Configure Database

Edit `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=erp_learning
DB_USERNAME=root
DB_PASSWORD=secret
```

### 3. Run Migrations & Seed

```bash
php artisan migrate
php artisan db:seed
```

### 4. Start Server

```bash
php artisan serve
# API available at http://localhost:8000/api
```

---

## 🔄 ERP Business Flows

### Sales Flow (Outbound)
```
Customer
  └── POST /api/sales-orders
        └── Validates stock availability
              └── Creates SalesOrder + SalesOrderItems
                    └── Deducts quantity from inventory
                          └── Marks order as "confirmed"
```

**Key concept:** When you sell, inventory goes DOWN. The system must check stock
before confirming, and use a database transaction so either everything succeeds
or nothing does.

### Purchase Flow (Inbound)
```
Supplier
  └── POST /api/purchase-orders
        └── Creates PurchaseOrder + PurchaseOrderItems
              └── POST /api/purchase-orders/{id}/receive
                    └── Increases inventory for each item received
                          └── Marks order as "received"
```

**Key concept:** When you buy from a supplier, inventory goes UP. The "receive"
step simulates the physical arrival of goods at the warehouse.

### Inventory Flow
```
Initial stock (from seeder)
  ↕ +/- Sales Orders (deduct)
  ↕ +/- Purchase Orders (add)
  ↕ +/- Manual Adjustments (correction)
  = Current Stock Level
```

---

## 📡 API Endpoint Reference

Base URL: `http://localhost:8000/api`

### Customers
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/customers` | List all customers |
| POST | `/customers` | Create a customer |
| GET | `/customers/{id}` | Get a customer |
| PUT | `/customers/{id}` | Update a customer |
| DELETE | `/customers/{id}` | Delete a customer |

### Products
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/products` | List all products |
| POST | `/products` | Create a product |
| GET | `/products/{id}` | Get a product |
| PUT | `/products/{id}` | Update a product |
| GET | `/products/barcode/{barcode}` | 🔍 Barcode lookup |
| GET | `/products/sku/{sku}` | SKU lookup |

### Inventory
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/inventory` | View all stock levels |
| GET | `/inventory/low-stock` | Products below reorder point |
| POST | `/inventory/{product_id}/adjust` | Manual stock adjustment |

### Sales Orders
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/sales-orders` | List all sales orders |
| POST | `/sales-orders` | Create sales order (deducts stock) |
| GET | `/sales-orders/{id}` | Get order with items |
| POST | `/sales-orders/{id}/cancel` | Cancel order (restores stock) |

### Purchase Orders
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/purchase-orders` | List all purchase orders |
| POST | `/purchase-orders` | Create purchase order |
| GET | `/purchase-orders/{id}` | Get order with items |
| POST | `/purchase-orders/{id}/receive` | Receive goods (adds stock) |

### Suppliers
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/suppliers` | List all suppliers |
| POST | `/suppliers` | Create a supplier |

---

## 📋 Example API Requests

### Create a Customer
```http
POST /api/customers
Content-Type: application/json

{
  "name": "Juan dela Cruz",
  "email": "juan@example.com",
  "phone": "09171234567",
  "address": "123 Rizal St, Manila"
}
```
**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Juan dela Cruz",
    "email": "juan@example.com",
    "phone": "09171234567",
    "address": "123 Rizal St, Manila",
    "created_at": "2025-01-15T08:00:00Z"
  }
}
```

---

### Barcode Lookup (POS simulation)
```http
GET /api/products/barcode/8901234567890
```
**Response:**
```json
{
  "success": true,
  "data": {
    "id": 3,
    "name": "Wireless Mouse",
    "sku": "MOUSE-001",
    "barcode": "8901234567890",
    "price": 850.00,
    "unit": "piece",
    "current_stock": 45,
    "reorder_point": 10
  }
}
```

---

### Create a Sales Order (deducts inventory)
```http
POST /api/sales-orders
Content-Type: application/json

{
  "customer_id": 1,
  "notes": "Rush delivery",
  "items": [
    { "product_id": 3, "quantity": 2, "unit_price": 850.00 },
    { "product_id": 5, "quantity": 1, "unit_price": 1200.00 }
  ]
}
```
**Response:**
```json
{
  "success": true,
  "message": "Sales order created and inventory updated",
  "data": {
    "id": 1,
    "order_number": "SO-20250115-001",
    "status": "confirmed",
    "customer": { "id": 1, "name": "Juan dela Cruz" },
    "total_amount": 2900.00,
    "items": [
      {
        "product_id": 3,
        "product_name": "Wireless Mouse",
        "quantity": 2,
        "unit_price": 850.00,
        "subtotal": 1700.00
      }
    ],
    "inventory_updated": true
  }
}
```

---

### Create a Purchase Order (increases inventory on receive)
```http
POST /api/purchase-orders
Content-Type: application/json

{
  "supplier_id": 1,
  "notes": "Monthly restock",
  "items": [
    { "product_id": 3, "quantity": 100, "unit_cost": 500.00 },
    { "product_id": 5, "quantity": 50, "unit_cost": 750.00 }
  ]
}
```

### Receive a Purchase Order (adds to inventory)
```http
POST /api/purchase-orders/1/receive
```
**Response:**
```json
{
  "success": true,
  "message": "Purchase order received. Inventory has been updated.",
  "data": {
    "id": 1,
    "order_number": "PO-20250115-001",
    "status": "received",
    "received_at": "2025-01-15T10:30:00Z",
    "inventory_updated": true
  }
}
```

---

### Manual Inventory Adjustment
```http
POST /api/inventory/3/adjust
Content-Type: application/json

{
  "quantity_change": -5,
  "reason": "Damaged goods written off"
}
```

---

## 🧠 Key Learning Points

### 1. Database Transactions
When creating a sales order, we use `DB::transaction()` to ensure that if anything
fails (e.g., one product is out of stock), NO changes are committed. This prevents
partial inventory deductions.

```php
DB::transaction(function () use ($data) {
    $order = SalesOrder::create([...]);
    foreach ($data['items'] as $item) {
        // If this throws, the whole transaction rolls back
        $this->inventoryService->deductStock($item['product_id'], $item['quantity']);
    }
});
```

### 2. Service Layer Pattern
Business logic lives in Services, not Controllers. Controllers just handle HTTP
concerns (parsing request, returning response). Services handle ERP rules.

### 3. Order Status Machine
```
Sales Order:  pending → confirmed → shipped → delivered
                                  ↘ cancelled
Purchase Order: pending → ordered → received
```

### 4. Inventory as a Ledger
Think of inventory like a bank account: every sale is a debit, every purchase
receipt is a credit. The current stock is always the running balance.

---

## 🗄️ Database Schema Overview

```
customers          products           inventory
─────────────      ──────────         ─────────────
id                 id                 id
name               name               product_id (FK)
email              sku                quantity
phone              barcode            reorder_point
address            description        updated_at
created_at         price
updated_at         unit
                   created_at

sales_orders       sales_order_items
─────────────      ─────────────────
id                 id
order_number       sales_order_id (FK)
customer_id (FK)   product_id (FK)
status             quantity
total_amount       unit_price
notes              subtotal
created_at

suppliers          purchase_orders    purchase_order_items
─────────────      ───────────────    ────────────────────
id                 id                 id
name               order_number       purchase_order_id (FK)
email              supplier_id (FK)   product_id (FK)
phone              status             quantity
address            total_cost         unit_cost
created_at         notes              subtotal
                   received_at
```
