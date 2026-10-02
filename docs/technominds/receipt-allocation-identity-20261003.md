# Native allocation identity correction — 2026-10-03

A real isolated native HTTP acceptance committed its financial effects, but its persisted receipt contained an empty allocation_id. Paymentable inherits Laravel Pivot’s non-incrementing default; the SQL row received an ID that the new model did not retain.

Enable incrementing on this service’s new Paymentable instance before its existing save. Native model events and the encompassing financial/audit transaction remain unchanged. The regression verifies the positive receipt ID points to the actual paymentable, matches the persisted original receipt, and survives authorized readback and exact replay without additional effects.

Validation against task-owned MariaDB/PHP 8.3: regression failed before the correction (empty allocation_id); all 13 allocation integration tests pass afterward (79 assertions). No browser acceptance is claimed by this receipt.

Historical malformed receipts are unchanged. The original committed fixture and retained operation remain intact; no money replay or read-endpoint repair was performed. Parent activation remains disabled; no deployment or callback worker was started.
