-- Payment orchestration schema.
--
-- Written for MySQL 8 and also valid on PostgreSQL; SQLite accepts it too except
-- where a comment says otherwise (SQLite has no ENUM and no real BOOLEAN, so those
-- columns are declared VARCHAR/INTEGER there by the test harness).
--
-- Conventions that matter for money:
--   * every amount is BIGINT in ISO minor units (ADR 0001). Never a float, never a
--     provider's major-unit value.
--   * every timestamp is UTC. Storing local time is how reconciliation reports end up
--     disagreeing with the provider.
--   * a version column on each aggregate root, so a concurrent write fails loudly
--     instead of overwriting.

CREATE TABLE paystart_payments (
    id                  VARCHAR(64)  NOT NULL,
    merchant_id         VARCHAR(64)  NOT NULL,
    -- ISO 4217 alphabetic code.
    currency            CHAR(3)      NOT NULL,
    -- ISO minor units of `currency`.
    amount_minor        BIGINT       NOT NULL,
    method_kind         VARCHAR(32)  NOT NULL,
    method_channel      VARCHAR(32)  NULL,
    customer_ref        VARCHAR(255) NULL,
    description         VARCHAR(512) NULL,
    status              VARCHAR(32)  NOT NULL,
    version             INT          NOT NULL,
    created_at          DATETIME     NOT NULL COMMENT 'UTC',
    updated_at          DATETIME     NOT NULL COMMENT 'UTC',
    PRIMARY KEY (id),
    -- Reporting and merchant-scoped listing both filter on this pair.
    INDEX idx_payments_merchant_created (merchant_id, created_at),
    INDEX idx_payments_status (status)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_as_cs;

CREATE TABLE paystart_payment_attempts (
    id                  VARCHAR(64)  NOT NULL,
    payment_id          VARCHAR(64)  NOT NULL,
    -- Ordering within the aggregate; attempts are appended, never reordered.
    position            INT          NOT NULL,
    method_kind         VARCHAR(32)  NOT NULL,
    method_channel      VARCHAR(32)  NULL,
    status              VARCHAR(32)  NOT NULL,
    gateway_reference   VARCHAR(255) NULL,
    failure_reason      VARCHAR(512) NULL,
    started_at          DATETIME     NOT NULL COMMENT 'UTC',
    PRIMARY KEY (id),
    -- Two attempts cannot claim the same slot, whichever worker got there first.
    UNIQUE KEY uq_attempts_payment_position (payment_id, position),
    FOREIGN KEY (payment_id) REFERENCES paystart_payments (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_as_cs;

-- Instructions are immutable and stored with their payload, because a webhook can
-- arrive days later and the audit trail must still show what the payer was told.
CREATE TABLE paystart_payment_instructions (
    id                  VARCHAR(64)  NOT NULL,
    attempt_id          VARCHAR(64)  NOT NULL,
    position            INT          NOT NULL,
    type                VARCHAR(32)  NOT NULL,
    is_static           TINYINT(1)   NOT NULL DEFAULT 0,
    expires_at          DATETIME     NULL COMMENT 'UTC; null when is_static = 1',
    gateway_reference   VARCHAR(255) NULL,
    raw_provider_type   VARCHAR(64)  NULL,
    -- Serialised payload. Contains bearer-like values (QR content, VA number), so this
    -- column must not be replicated to a search index or read replica used for reporting.
    payload             JSON         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_instructions_attempt_position (attempt_id, position),
    FOREIGN KEY (attempt_id) REFERENCES paystart_payment_attempts (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_as_cs;

CREATE TABLE paystart_payment_refunds (
    id                  VARCHAR(64)  NOT NULL,
    payment_id          VARCHAR(64)  NOT NULL,
    position            INT          NOT NULL,
    currency            CHAR(3)      NOT NULL,
    amount_minor        BIGINT       NOT NULL,
    status              VARCHAR(32)  NOT NULL,
    idempotency_key     VARCHAR(255) NOT NULL,
    reason              VARCHAR(512) NULL,
    gateway_reference   VARCHAR(255) NULL,
    created_at          DATETIME     NOT NULL COMMENT 'UTC',
    PRIMARY KEY (id),
    UNIQUE KEY uq_refunds_payment_position (payment_id, position),
    -- The database, not the handler, is what stops a retried refund from being
    -- recorded twice. A read-then-write check in PHP races across workers.
    UNIQUE KEY uq_refunds_payment_idempotency (payment_id, idempotency_key),
    FOREIGN KEY (payment_id) REFERENCES paystart_payments (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_as_cs;

-- The webhook inbox. `claim` relies on the unique key below to be atomic: an insert
-- that violates it means another delivery already won, and the handler must not apply
-- the event a second time.
CREATE TABLE paystart_webhook_events (
    id                  VARCHAR(64)  NOT NULL,
    provider            VARCHAR(32)  NOT NULL,
    -- Provider's own event id where one exists; otherwise our derived identity.
    external_event_id   VARCHAR(255) NOT NULL,
    -- provider:externalId, hashed if over the index limit. This is the uniqueness
    -- that makes duplicate delivery a no-op rather than a double application.
    deduplication_key   VARCHAR(191) NOT NULL,
    event_type          VARCHAR(64)  NOT NULL,
    gateway_reference   VARCHAR(255) NULL,
    payload             JSON         NOT NULL,
    status              VARCHAR(16)  NOT NULL,
    failure_reason      VARCHAR(1024) NULL,
    received_at         DATETIME     NOT NULL COMMENT 'UTC',
    PRIMARY KEY (id),
    -- Scoped by provider as well as key. The key is meant to embed the provider
    -- already, so this is defence in depth: a caller that builds a key without the
    -- provider must not be able to collide with another provider's event.
    UNIQUE KEY uq_webhook_events_dedup (provider, deduplication_key),
    INDEX idx_webhook_events_reference (gateway_reference),
    INDEX idx_webhook_events_status (status)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_as_cs;

-- Transactional outbox. Rows are written in the same transaction as the state change,
-- then drained by a publisher after commit.
CREATE TABLE paystart_outbox (
    sequence            BIGINT       NOT NULL AUTO_INCREMENT,
    event_id            VARCHAR(64)  NOT NULL,
    event_name          VARCHAR(64)  NOT NULL,
    aggregate_id        VARCHAR(64)  NOT NULL,
    merchant_id         VARCHAR(64)  NULL,
    correlation_id      VARCHAR(255) NULL,
    payload             JSON         NOT NULL,
    occurred_at         DATETIME     NOT NULL COMMENT 'UTC',
    published_at        DATETIME     NULL COMMENT 'UTC; null until a publisher drains it',
    PRIMARY KEY (sequence),
    -- A publisher that crashed after sending can retry; the consumer deduplicates on
    -- event_id, so at-least-once is the contract.
    UNIQUE KEY uq_outbox_event_id (event_id),
    INDEX idx_outbox_unpublished (published_at, sequence)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_as_cs;

-- Append-only audit log. There is deliberately no UPDATE or DELETE path in code, and
-- the application user should be granted only INSERT and SELECT on this table.
CREATE TABLE paystart_audit_entries (
    id                  BIGINT       NOT NULL AUTO_INCREMENT,
    action              VARCHAR(64)  NOT NULL,
    occurred_at         DATETIME     NOT NULL COMMENT 'UTC',
    merchant_id         VARCHAR(64)  NULL,
    payment_id          VARCHAR(64)  NULL,
    actor_type          VARCHAR(32)  NULL,
    actor_id            VARCHAR(64)  NULL,
    context             JSON         NOT NULL,
    correlation_id      VARCHAR(255) NULL,
    corrects_entry_id   VARCHAR(64)  NULL,
    PRIMARY KEY (id),
    INDEX idx_audit_payment_occurred (payment_id, occurred_at),
    INDEX idx_audit_merchant_occurred (merchant_id, occurred_at),
    INDEX idx_audit_action (action)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_as_cs;
