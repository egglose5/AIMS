# Control-App Deployment Notes

## Full Deployment With Docker Compose

Deploy database + app together:

```bash
docker compose up -d --build
```

The app will be reachable at `http://localhost:8080`.

## Persistence

PostgreSQL data is bind-mounted to:

```text
./.persist/postgres-data
```

This keeps data across restarts and redeployments on the same machine.

ASP.NET Data Protection keys are also bind-mounted to:

```text
./.persist/dp-keys
```

This keeps authentication/session cookie encryption keys stable across web container restarts.

## Migrations (Optional Manual Step)

The app applies migrations on startup. If you want to run them manually:

```bash
dotnet ef database update --project Mustaine-AI/Mustaine-AI.csproj --startup-project Mustaine-AI/Mustaine-AI.csproj
```
