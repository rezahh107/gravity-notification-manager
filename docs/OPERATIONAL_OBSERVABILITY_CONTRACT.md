# Operational Observability Contract Snapshot

Status: implementation evidence for the GNM-001 operational observability batch.

## Current Contract Snapshot — 2026-09-14

- Repository base: `main@b6ce57ccf817a2b41c9b86e62c351f28719293a2` (merge of PR #22).
- WordPress baseline declared by package: 7.0+; repository integration matrix remains authoritative for exact CI qualification.
- PHP requirement: >=8.2.
- Gravity Forms / Gravity Flow ownership remains unchanged: Feed is the logical Rule; Gravity Flow owns workflow topology; Entry Meta owns delivery state and Retry eligibility.
- SMS provider administration remains owned by Provider Manager; `SmsProviderInterface` remains delivery-only.
- Bale remains a separate synchronous channel.

## Observability boundary

Operational logging is observational evidence only. It must never decide whether delivery succeeded, whether Retry is allowed, whether duplicate suppression applies, or whether workflow may continue.

The greenfield subsystem uses a dedicated bounded table, `gravity_notify_operational_events`, rather than reviving the historical `GFSMS\\Logging\\Logger` lifecycle/table. Logging failures are swallowed at the observational boundary so they cannot turn a confirmed provider result into uncertainty or trigger duplicate delivery behavior.

One logical send operation owns one UUIDv4 trace identifier. Provider fallback attempts share that trace with increasing attempt indexes. Manual Retry creates a distinct Retry trace. Explicit provider/channel TEST sends are recorded as TEST and remain outside Feed processing and Entry Meta delivery history.

Persisted fields are whitelist-only and include safe source/runtime evidence where present. Message bodies, credentials, authorization data and unrestricted raw provider bodies are not part of the model. Destinations are masked before persistence. Retention keeps the newest 1000 rows deterministically.

## Admin / diagnostics

A WordPress-native Operational Log surface presents SMS and Bale as separate views over the same subsystem. Failed and ambiguous rows expose a deterministic `gnm-llm-debug-v1` report containing only safe evidence actually available; absent evidence is omitted rather than invented.

## Legacy differential review

Legacy asset reviewed after greenfield implementation: `includes/Logging/Logger.php` and its lifecycle references. Useful finding: the historical product had a dedicated log table lifecycle. Rejected behavior: reviving legacy logging/runtime coupling, raw provider payload persistence, or making that table part of delivery authority. The target uses an independent greenfield table and leaves the retired sender/queue runtime unreachable from the production entrypoint.
