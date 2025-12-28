# Use Cases: SaaS ERP & E-commerce

The `sp-laravel-api` package is architected specifically to solve the common challenges found in **SaaS ERP systems** and **Headless E-commerce** platforms.

## 🏢 SaaS ERP Systems

ERP systems require strict data isolation, comprehensive auditing, and the ability to rapidly develop new modules.

### 1. Multi-Tenancy (Native Support)
The package handles tenant isolation automatically, preventing data leaks between customers.
- **Auto-Injection**: The `tenant_id` is automatically injected into all queries (Create, Read, Update, Delete) based on the authenticated user.
- **Configuration**: Simply enable `has_tenant_id` in your table config.
- **Safety**: Developers cannot accidentally forget to add `where('tenant_id', $id)` clauses, reducing security risks.

### 2. Regulatory Compliance (Audit Logs)
ERPs often require strict audit trails (who changed what, when, and old vs new values).
- **Zero Config**: Audit logging works out of the box for all CRUD operations.
- **Field-Level Tracking**: Logs exactly which fields changed (e.g., `credit_limit` changed from 1000 to 2000).
- **Performance**: As noted in the [Performance Guide](performance.md), logs can be queued to prevent slowing down user operations.

### 3. Rapid Module Development
ERPs have hundreds of entities (Invoices, POs, Inventory, Customers).
- **No Boilerplate**: You don't need to write Controllers or Models for every new entity. Just define the schema in `config/record.php`.
- **Consistency**: All modules automatically get Sorting, Filtering, Pagination, and Validation.

---

## 🛒 E-commerce & High-Volume Transactional Systems

E-commerce platforms demand high performance, standardized APIs for frontends, and efficient bulk processing.

### 1. Headless Architecture
Perfect for React, Vue, or Mobile App frontends.
- **Standardized JSON**: Returns consistent `data`, `meta` (pagination), and `message` structures.
- **Deep Relationships**: Fetch a Product with its Variants, Images, and Reviews in a single request using `?with=variants,images,reviews`.

### 2. High-Performance Catalog
- **Read Caching**: Integrated `QueryCacheService` can cache product listings and search results.
- **Octane Ready**: Designed to work with Laravel Octane for sub-millisecond response times during high traffic events (Black Friday).

### 3. Inventory & Price Management
- **Bulk Operations**: Update thousands of product prices or inventory levels in a single API call using the `/bulk/update` endpoints.
- **Concurrency Safety**: Database transactions ensure that half-finished updates don't corrupt your catalog.

### 4. Order Processing Triggers
Use the `RecordTableTriggerType` to hook into the order lifecycle without cluttering controllers.
- **beforeCreate**: Validate stock levels.
- **afterCreate**: Trigger payment gateway and confirmation emails.
- **afterUpdate**: Trigger shipping workflows when status changes to "shipped".

## Comparison

| Feature | SaaS ERP Needs | E-commerce Needs | Package Solution |
| :--- | :--- | :--- | :--- |
| **Data Isolation** | Critical (Tenant) | N/A (usually single tenant) | Native `tenant_id` scoping |
| **Audit Trail** | Mandatory | Optional (Admin actions) | Built-in `AuditLogService` |
| **API Speed** | Medium | Critical | Caching & Octane support |
| **Development Speed** | High (Many modules) | Medium | Dynamic Controllers |
| **Complex Logic** | High | High | Lifecycle Triggers (`before/after`) |
