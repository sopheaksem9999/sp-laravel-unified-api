---
title: "Temp Attachment Cleanup Cron "
description: "Feature guide for scheduled cleanup command logic using temp_timeout and created_at fallback retention. Delete expired attachments."
keywords:
  - cleanup command
  - sp-laravel-api clean-temp-attachments
  - cron schedule
  - temp_timeout cleanup
  - created_at fallback
  - dry-run
  - force option
  - minutes option
---

# Attachment Temp Cleanup (Cron)

## Command

- `php artisan sp-laravel-api:clean-temp-attachments`

## Cleanup Logic

The command targets only `temp_private` and `temp_public`:

1. Expire-first: delete rows where `temp_timeout <= now()`.
2. Fallback: if `temp_timeout` is null, delete rows older than `created_at + attachments.temp_lifetime`.

## Suggested Schedule

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('sp-laravel-api:clean-temp-attachments')->daily();
}
```

## Safety Options

- `--dry-run`
- `--force`
- `--minutes=` override
