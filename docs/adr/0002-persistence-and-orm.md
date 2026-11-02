# ADR 0002 — Persistence and ORM strategy

- Status: Accepted
- Deciders: library maintainers
- Constraint: library floor is PHP 8.2

## Context

Repositories are domain ports; persistence is infrastructure. The library cannot
impose an ORM: a Laravel application will not accept Doctrine, a Symfony
application will not accept Eloquent, and a standalone consumer may have no ORM.

The aggregates are small and aggregate-oriented (`Payment` with `Attempt`s and
`PaymentInstruction`s, `Journal` with postings, plus inbox/outbox/audit rows), so
no adapter needs lazy-loading graph traversal inside the domain.

## Decision

Repositories are the only persistence port, one per aggregate root, and no ORM
type appears in `core`. Four concrete implementations:

- **`bridge-laravel`** — Eloquent models in an infrastructure namespace, mapped
  to domain aggregates by an explicit `PaymentMapper`. Eloquent models are never
  handed to the application layer.
- **`bridge-symfony`** — Doctrine ORM, mapped in XML or via attributes on
  infrastructure entity classes, never on domain classes.
- **`bridge-standalone`** — PDO repositories with explicit SQL and versioned
  migrations.
- **`testing`** — in-memory repositories implementing the same interfaces.

Cross-cutting rules:

- Optimistic concurrency via a `version` column on aggregate roots; a double
  capture or concurrent refund fails rather than silently overwriting.
- The transaction boundary belongs to the application handler through
  `TransactionManagerPort`, never to repositories.
- Outbox rows are written in the same transaction as the state change.
- No lazy loading of aggregates; a repository returns a fully hydrated aggregate.

## Consequences

- Persistence is implemented three times. That is a real cost, accepted because
  the alternative is forcing one ORM on every host.
- The domain is testable with in-memory repositories.
- The schema becomes a shared contract each bridge must satisfy. A cross-bridge
  persistence contract suite in `testing` enforces identical semantics, so
  "works on Laravel" cannot silently diverge from "works on Symfony".

## Alternatives rejected

- **Doctrine in `core`** — framework imposition, and Doctrine entities in the
  domain leak persistence concerns into invariants.
- **One custom query-builder abstraction over all three** — leaky, and
  re-implements what Eloquent and Doctrine already do correctly.
- **Active Record domain objects** — violates the dependency rule outright.

## Verification

Design-level decision; no external claim depends on it. Version ranges live in
ADR 0003 and the root `composer.json`, and were read from `repo.packagist.org`.
