-- SQLite variant of 0001_init.sql, used only by the test harness.
--
-- This file exists because SQLite is not MySQL: no ENGINE clause, no inline INDEX
-- declarations, no real BOOLEAN, no JSON type. Rather than auto-translate the shipped
-- MySQL schema (which would let tests pass against a schema that is not the one
-- deployed), this variant is maintained by hand and SchemaParityTest asserts that the
-- two declare the same tables and columns.

CREATE TABLE paystart_payments (
    id                  VARCHAR(64)  NOT NULL PRIMARY KEY,
    merchant_id         VARCHAR(64)  NOT NULL,
    currency            CHAR(3)      NOT NULL,
    amount_minor        INTEGER      NOT NULL,
    method_kind         VARCHAR(32)  NOT NULL,
    method_channel      VARCHAR(32)  NULL,
    customer_ref        VARCHAR(255) NULL,
    description         VARCHAR(512) NULL,
    status              VARCHAR(32)  NOT NULL,
    version             INTEGER      NOT NULL,
    created_at          TEXT         NOT NULL,
    updated_at          TEXT         NOT NULL
);

CREATE TABLE paystart_payment_attempts (
    id                  VARCHAR(64)  NOT NULL PRIMARY KEY,
    payment_id          VARCHAR(64)  NOT NULL REFERENCES paystart_payments (id),
    position            INTEGER      NOT NULL,
    method_kind         VARCHAR(32)  NOT NULL,
    method_channel      VARCHAR(32)  NULL,
    status              VARCHAR(32)  NOT NULL,
    gateway_reference   VARCHAR(255) NULL,
    failure_reason      VARCHAR(512) NULL,
    started_at          TEXT         NOT NULL
);

CREATE UNIQUE INDEX uq_attempts_payment_position ON paystart_payment_attempts (payment_id, position);

CREATE TABLE paystart_payment_instructions (
    id                  VARCHAR(64)  NOT NULL PRIMARY KEY,
    attempt_id          VARCHAR(64)  NOT NULL REFERENCES paystart_payment_attempts (id),
    position            INTEGER      NOT NULL,
    type                VARCHAR(32)  NOT NULL,
    is_static           INTEGER      NOT NULL DEFAULT 0,
    expires_at          TEXT         NULL,
    gateway_reference   VARCHAR(255) NULL,
    raw_provider_type   VARCHAR(64)  NULL,
    payload             TEXT         NOT NULL
);

CREATE UNIQUE INDEX uq_instructions_attempt_position ON paystart_payment_instructions (attempt_id, position);

CREATE TABLE paystart_payment_refunds (
    id                  VARCHAR(64)  NOT NULL PRIMARY KEY,
    payment_id          VARCHAR(64)  NOT NULL REFERENCES paystart_payments (id),
    position            INTEGER      NOT NULL,
    currency            CHAR(3)      NOT NULL,
    amount_minor        INTEGER      NOT NULL,
    status              VARCHAR(32)  NOT NULL,
    idempotency_key     VARCHAR(255) NOT NULL,
    reason              VARCHAR(512) NULL,
    gateway_reference   VARCHAR(255) NULL,
    created_at          TEXT         NOT NULL
);

CREATE UNIQUE INDEX uq_refunds_payment_position ON paystart_payment_refunds (payment_id, position);
CREATE UNIQUE INDEX uq_refunds_payment_idempotency ON paystart_payment_refunds (payment_id, idempotency_key);

CREATE TABLE paystart_webhook_events (
    id                  VARCHAR(64)  NOT NULL PRIMARY KEY,
    provider            VARCHAR(32)  NOT NULL,
    external_event_id   VARCHAR(255) NOT NULL,
    deduplication_key   VARCHAR(191) NOT NULL,
    event_type          VARCHAR(64)  NOT NULL,
    gateway_reference   VARCHAR(255) NULL,
    payload             TEXT         NOT NULL,
    status              VARCHAR(16)  NOT NULL,
    failure_reason      VARCHAR(1024) NULL,
    received_at         TEXT         NOT NULL
);

CREATE UNIQUE INDEX uq_webhook_events_dedup ON paystart_webhook_events (provider, deduplication_key);

CREATE TABLE paystart_outbox (
    sequence            INTEGER      NOT NULL PRIMARY KEY AUTOINCREMENT,
    event_id            VARCHAR(64)  NOT NULL,
    event_name          VARCHAR(64)  NOT NULL,
    aggregate_id        VARCHAR(64)  NOT NULL,
    merchant_id         VARCHAR(64)  NULL,
    correlation_id      VARCHAR(255) NULL,
    payload             TEXT         NOT NULL,
    occurred_at         TEXT         NOT NULL,
    published_at        TEXT         NULL
);

CREATE UNIQUE INDEX uq_outbox_event_id ON paystart_outbox (event_id);

CREATE TABLE paystart_audit_entries (
    id                  INTEGER      NOT NULL PRIMARY KEY AUTOINCREMENT,
    action              VARCHAR(64)  NOT NULL,
    occurred_at         TEXT         NOT NULL,
    merchant_id         VARCHAR(64)  NULL,
    payment_id          VARCHAR(64)  NULL,
    actor_type          VARCHAR(32)  NULL,
    actor_id            VARCHAR(64)  NULL,
    context             TEXT         NOT NULL,
    correlation_id      VARCHAR(255) NULL,
    corrects_entry_id   VARCHAR(64)  NULL
);
