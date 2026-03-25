# US-12: Observability & processing metrics

## User Story

As a platform operator,
I want clear, measurable metrics and simple dashboards for the processing pipeline,
so that I can reliably observe throughput, failures, retries, and latency and act quickly when problems appear.

---

## Context

Operators need actionable measurements to triage processing problems, detect slowdowns, and validate system health. This story defines the minimal set of processing metrics, a simple dashboard view, and alerting guidance operators can use to keep the pipeline healthy.

---

## Acceptance Criteria

### Metric coverage

- The system exposes counters for: total processed items, successful items, failed items, and replayed items.
- The system exposes retry counters and the current queue depth for pending work.
- The system exposes latency measurements for processing (average and 95th percentile) and per-stage durations when applicable.

### Operator views and queries

- A simple dashboard or view shows: throughput (items/sec), success/failure rates, retry rate, queue depth trend, and processing latency percentiles for the last 1m/5m/1h windows.
- The dashboard supports time-range filtering and basic paging for long time ranges.

### Alerting and triage

- Clear, documented alert thresholds exist for: sustained elevated failure rate, queue depth above a configured threshold, and median latency above a configured threshold.
- For an alerted condition, operators can link to the failed-job list and replay an individual job for investigation.

### Observability non-functional

- Metrics are produced with stable names and units so they can be consumed by standard tooling.
- Instrumentation has minimal performance overhead and degrades gracefully if the metrics backend is unavailable.

---

## Definition of Done

- [ ] Implementation complete
- [ ] PHPUnit unit tests pass where applicable
- [ ] Metrics documented (names, units, and intended use) in repository docs
- [ ] Dashboard screenshot or configuration saved in docs/architecture/observability.md
- [ ] Architect has reviewed and approved metric design
- [ ] QA engineer has validated metrics in a test run
- [ ] Security reviewer has reviewed any exported data for sensitive content

Priority: High
Size: M

Out-of-scope

- Procurement or setup of external monitoring services and hosted dashboards.
- Complex historical analytics beyond basic retention windows and percentile summaries.

[EPIC-04: API Consistency](https://github.com/hsc00/dam-api/issues/17)
