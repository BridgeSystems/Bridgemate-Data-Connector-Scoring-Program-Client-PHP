# Contributing

Thank you for your interest. This repository is maintained by Bridge Systems BV.

- **Issues are welcome** — bug reports, documentation gaps, wire-format mismatches.
- **Pull requests by prior agreement only** — please open an issue or a discussion first.
- **Never edit `src/Dto/` or `tests/fixtures/` by hand**: they are generated from the
  [.NET client repository](https://github.com/BridgeSystems/Bridgemate-Data-Connector-Scoring-Program-Client)
  (its `tools/DtoGenerator`), which is the single source of truth for the wire format. Changes
  there flow here through regeneration; hand edits would be overwritten and can silently break
  wire compatibility.
