---
title: "Frontend Agent Guide"
description: "Concise guidance for AI agents and developers building clients for sp-laravel-api using official client SDKs (Flutter/Dart and TypeScript/npm)."
keywords:
  - frontend
  - ai agent
  - flutter
  - dart
  - pub.dev
  - typescript
  - npm
  - api client
  - filtering
  - relationships
  - pagination
---

# Frontend Agent Guide

Official client packages are available for client applications:
- **Flutter / Dart (pub.dev):** [`sp_laravel_api`](https://pub.dev/packages/sp_laravel_api) — `flutter pub add sp_laravel_api`
- **JavaScript / TypeScript (npm):** [`@sokpheak/sp-laravel-api`](https://www.npmjs.com/package/@sokpheak/sp-laravel-api) — `npm i @sokpheak/sp-laravel-api`

For bootstrapping a new frontend/mobile client project, use the copy-paste
[Frontend Setup Prompt](/agents_init/frontend-setup-prompt).

## Client SDK Quick Reference

### Dart / Flutter
```bash
flutter pub add sp_laravel_api
```
```dart
import 'package:sp_laravel_api/sp_laravel_api.dart';

final api = SpLaravelApiClient(
  baseUrl: 'https://api.example.com',
  bearerToken: token,
  tenantId: tenantId,
);

final result = await api.records('products').get(
  filter: {'category': 'electronics'},
  select: ['brand', 'reviews'],
);
```

### TypeScript / JavaScript (Node / Web)
```bash
npm install @sokpheak/sp-laravel-api
```
```typescript
import { SpLaravelApiClient } from '@sokpheak/sp-laravel-api';

const api = new SpLaravelApiClient({
  baseUrl: 'https://api.example.com',
  token: token,
  tenantId: tenantId,
});

const { data, meta } = await api.records('products').list({
  filter: { category: 'electronics' },
  select: ['brand', 'reviews'],
});
```

## What the Client Should Assume

- All responses use the envelope `{ success, error_code, data, meta }`.
- List endpoints support filtering + pagination (`page`, `per_page`, `cursor`).
- Multi-tenancy is sent via the `X-Tenant-ID` header.
- Relationship loading is requested via `select=` syntax (see docs).

## Docs Entry Points

- [Filter Operators Reference](/guide/api-filter-operators)
- [Relationships](/core-concepts/relationships)
- [Standard CRUD](/guide/api-crud-operations)
- [Pagination Module](/guide/module-pagination)
- [Error Responses + Security](/guide/api-errors-rate-security)
