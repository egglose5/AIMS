# Control-App Deployment Notes

## Full Deployment With Docker Compose

Deploy database + app together:

```bash
docker compose up -d --build
```

The app will be reachable at `http://localhost:8080`.

## Persistence

The live compose stack bind-mounts persistent runtime data under:

```text
${HOME}/.mustaine-ai/postgres-data
```

It also bind-mounts:

```text
${HOME}/.mustaine-ai/dp-keys
${HOME}/.mustaine-ai/show-maps
${HOME}/.mustaine-ai/show-vendor-files
${HOME}/.mustaine-ai/brain-email-attachments
```

These mounts keep the database, authentication keys, show uploads, and brain-email attachments stable across restarts.

## Migrations (Optional Manual Step)

The app applies EF Core migrations on startup. If you want to run them manually from a .NET SDK environment:

```bash
dotnet ef database update --project Mustaine-AI/Mustaine-AI.csproj --startup-project Mustaine-AI/Mustaine-AI.csproj
```

## Local Secrets

Do not commit real environment files.

- `.env.ai-brain` is optional local email configuration loaded by `docker compose`.
- `.env.brain-b5` can be created locally by `./configure-brain-provider-b5.sh`.
- `docker-compose.override.yml` is treated as local-only secret injection and is ignored by Git.
