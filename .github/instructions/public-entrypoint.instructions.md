---
description: "Minimal conventions for PHP entrypoints under public/. Keeps always-loaded context small while preserving critical rules for front controllers."
applyTo: "public/**/*.php"
---

- Start every PHP entrypoint with `declare(strict_types=1);`
- Keep `public/` files thin. Delegate application behavior to classes under `src/`
- Do not place business logic, raw SQL, or direct GraphQL resolver logic in `public/`
- Access to superglobals is allowed only as part of request bootstrapping at the boundary
