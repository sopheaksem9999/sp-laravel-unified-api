---
title: "Recommended Folder Structure"
description: "Module-based folder structure options for organizing triggers, validators, and table configuration files."
keywords:
  - folder structure
  - module based architecture
  - table config organization
  - trigger class organization
  - validator class organization
---

## Recommended Folder Structure

When integrating the `sp-laravel-api` package into a client project, you can organize your application code in two main ways: **Layer-Based** (separated by type) or **Module-Based** (grouped by domain). Both are fully supported by the package.

### Option 1: Module-Based Structure (Separated by File)

This is the standard approach where files are grouped by their domain (e.g., User, Invoice), but separated by their technical responsibility (Triggers, Validators, Functions).

```text
project-root/
├── app/
│   └── Record/                      
│       ├── Invoice/                 # Invoice Module
│       │   ├── InvoiceFunctions.php # Custom RPC function classes
│       │   ├── InvoiceTriggers.php  # Lifecycle hooks
│       │   ├── InvoiceValidators.php# Custom validation logic
│       │   └── InvoiceService.php   # Business logic & helper methods
│       │
│       └── User/                    # User Module
│           ├── UserFunctions.php    # Custom RPC function classes
│           ├── UserTriggers.php     # Lifecycle hooks
│           └── UserValidators.php   # Custom validation logic
│           └── UserService.php    # Business logic & helper methods
```

**Pros:**
- Clear separation of concerns (validation logic doesn't mix with lifecycle hooks).
- Keeps individual classes smaller and more focused.
- High cohesion within the domain (everything related to "User" is in the `User/` folder).

**Cons:**
- You have to manage multiple files for a single domain.

---

### Option 2: Module-Based Structure (Single Handler Class)

If you prefer keeping all logic for a specific table/domain together in a single file, you can combine Triggers, Validators, and Functions into a single "Handler" class.

```text
project-root/
├── app/
│   └── Record/                      
│       ├── Invoice/                 
│       │   └── InvoiceHandler.php   # Contains Triggers, Validators, and Functions
│       └── User/                    
│           └── UserHandler.php      # Contains Triggers, Validators, and Functions
```

**Example of a combined Handler class:**

```php
namespace App\Record\User;

use Illuminate\Http\Request;
use Sopheak\Core\Attributes\RecordTrigger;
use Sopheak\Core\Attributes\RecordValidator;

class UserHandler
{
    // --- VALIDATORS ---
    #[RecordValidator('create')]
    public static function createValidator(): array
    {
        return [
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8',
        ];
    }

    // --- TRIGGERS ---
    #[RecordTrigger('beforeCreate')]
    public static function hashPassword(Request $request, string $table, array $context): array
    {
        $payload = $request->all();
        $payload['password'] = bcrypt($payload['password']);
        return $payload;
    }

    // --- FUNCTIONS (RPC) ---
    public static function resetPassword(Request $request, string $table, array $context): array
    {
        // Custom RPC logic...
        return ['status' => 'success'];
    }
}
```

**Registering the combined class in `config/records/tables/users.php`:**

```php
use Sopheak\Core\Types\RecordTableType;
use App\Record\User\UserHandler;

return new RecordTableType(
    // ...
    triggers: [
        UserHandler::class, // Auto-discovers both #[RecordTrigger] and #[RecordValidator] methods!
    ],
    functions: [
        // ... register RPC functions pointing to UserHandler::class
    ]
);
```

**Pros:**
- **High Cohesion:** Everything related to the "User" table is in one single file.
- **Faster Development:** No need to jump between multiple files when building a feature.
- **Easier to maintain:** If you delete the "User" feature, you just delete one file.

**Cons:**
- The class can become very large (a "God Class") if the table has complex validation, many triggers, and several RPC functions.
- Mixing validation arrays with complex business logic in the same file can feel cluttered to some developers.

---

### Directory Breakdown (For both approaches)

1. **`config/records/tables/`**: 
   Instead of putting all table configurations in the main `config/record.php` file, split them into individual files inside this directory. The package automatically discovers and merges them. Each file should return a single `RecordTableType` instance.

2. **`app/Record/{Domain}/`**:
   Store all your domain-specific logic here (e.g., `app/Record/User/`). Whether you split them into `UserTriggers.php` and `UserValidators.php` or combine them into `UserHandler.php`, keeping them grouped by domain makes the project much easier to navigate as it grows.

---

