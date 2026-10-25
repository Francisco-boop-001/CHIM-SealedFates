# Packaging lessons

## CHIM automatic synchronization

- Mistake: the bundle writer and its verifier agreed on a descriptive download basename, while CHIM interpreted that whole filename as the version. Internal consistency hid an invalid consumer contract.
- Rule: inside `CHIM/server-plugins/<package>/`, use `<declared-version>.dwpkg`. Keep descriptive standalone download names separate.
- Prevention: inspect the produced ZIP and compare its package folder and filename stem with the embedded manifest, independently of the writer's path-building expression; exercise the actual package validator offline before claiming installation compatibility.

## Read-only installation diagnosis

- Identify the active MO2 profile, enabled mod, installed package and sync-log result before asking the user which download they installed. A download or metadata label alone does not establish the running server version.
- While the user is playing, read-only inspection does not authorize renaming a package, triggering sync, restarting anything or using the reserved test server.
