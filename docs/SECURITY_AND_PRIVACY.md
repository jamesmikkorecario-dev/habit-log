# Security and privacy

Use Laravel session authentication, password hashing, CSRF middleware, validation, output escaping, and authenticated route middleware without weakening defaults. Authorize ownership and visibility server-side via Policies/Gates; hidden controls are never sufficient.

New habits default private. Journals, mood, and habit notes are private. Private-account follower requests require approval. Public/follower-visible activity never exposes private notes. Avoid logging passwords, tokens, journal content, mood, or private notes.

Keep secrets only in ignored environment files and document variable names—not real credentials—in `.env.example`. Use a distinct testing database, validate its name before destructive migrations, and never point tests at the development database. Permanent deletion later requires explicit confirmation and complete related-data removal.
