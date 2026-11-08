# Contributing to NexoraIPAM

Thank you for helping improve NexoraIPAM. Contributions go through the official repository: open an issue or a pull request that describes the problem, the proposed change and how you checked it.

## Reporting a bug

Include the application version (shown on the **About** page), what you did, what you expected and what happened. Add logs or screenshots when they help, but **never include secrets or private infrastructure data**: application keys, passwords, SNMP communities, real IP plans or host names.

For SNMP issues, the output of `php artisan nexora:snmp-dump <device>` is the most useful attachment. It contains no credentials, but review it before sharing and mask anything you consider sensitive.

## Proposing a change

1. Read the project rules in [`AGENTS.md`](AGENTS.md). In short:
   - every visible text goes through Laravel translations, with the French key and its English translation in `lang/en.json`;
   - every change to data or security state is recorded in the audit log;
   - reuse the existing Blade and Livewire components and the Tom Select dropdowns.
2. Set up the project as described in the [README](README.md#local-development).
3. Add or update tests for your change, then run:

   ```sh
   vendor/bin/pint --dirty
   vendor/bin/phpunit
   npm run build
   ```

4. Keep pull requests focused on one topic and describe what you verified.

## Contribution terms

Contributions are governed by section 5 of the [NexoraIPAM Source Available License v1.0](LICENSE). That section is authoritative; in summary:

- You keep ownership of your original contributions.
- By submitting a contribution, you grant the Licensor a perpetual, worldwide, non-exclusive, royalty-free license to use, reproduce, modify, distribute, sublicense and commercially license that contribution as part of NexoraIPAM and related products.
- You must have the rights needed to submit the contribution and must not knowingly introduce code that cannot lawfully be distributed under the project's licensing arrangements. Mention any third-party components and their licenses.
- A separate Contributor License Agreement may be required before a contribution is accepted.

Section 4 of the license restricts redistribution and the publication of forks. If your contribution would require a public fork, agree on an authorized way to contribute with the maintainer first.
