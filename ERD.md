# ERD — Inventory Management System

```mermaid
erDiagram
  USERS ||--|| USER_PROFILES : has
  USERS ||--o{ STOCK_MOVEMENTS : records
  CATEGORIES ||--o{ PRODUCTS : contains
  PRODUCTS ||--o{ STOCK_MOVEMENTS : has
  PRODUCTS ||--o{ PRODUCT_SUPPLIERS : supplied_by
  SUPPLIERS ||--o{ PRODUCT_SUPPLIERS : supplies
```

- `users` ke `user_profiles`: one-to-one, `user_profiles.user_id` UNIQUE.
- `categories` ke `products`: one-to-many.
- `products` ke `suppliers`: many-to-many lewat `product_suppliers`.
- `products` dan `users` ke `stock_movements`: histori transaksi.
