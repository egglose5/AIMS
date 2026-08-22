using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Design;
using Microsoft.Extensions.Configuration;
using Npgsql;
using System.Net.Sockets;

namespace MustaineAI.Data;

public sealed class ApplicationDbContextFactory : IDesignTimeDbContextFactory<ApplicationDbContext>
{
    public ApplicationDbContext CreateDbContext(string[] args)
    {
        var environmentName = Environment.GetEnvironmentVariable("ASPNETCORE_ENVIRONMENT")
            ?? Environment.GetEnvironmentVariable("DOTNET_ENVIRONMENT")
            ?? "Development";

        var configuration = new ConfigurationBuilder()
            .SetBasePath(Directory.GetCurrentDirectory())
            .AddJsonFile("appsettings.json", optional: true)
            .AddJsonFile($"appsettings.{environmentName}.json", optional: true)
            .AddEnvironmentVariables()
            .Build();

        var postgresHost = Environment.GetEnvironmentVariable("POSTGRES_HOST")
            ?? Environment.GetEnvironmentVariable("POSTGRES_PORT_5432_TCP_ADDR")
            ?? Environment.GetEnvironmentVariable("DB_HOST");
        var postgresPort = Environment.GetEnvironmentVariable("POSTGRES_PORT")
            ?? Environment.GetEnvironmentVariable("POSTGRES_PORT_5432_TCP_PORT")
            ?? Environment.GetEnvironmentVariable("DB_PORT");
        var postgresDb = Environment.GetEnvironmentVariable("POSTGRES_DB")
            ?? Environment.GetEnvironmentVariable("DB_NAME");
        var postgresUser = Environment.GetEnvironmentVariable("POSTGRES_USER")
            ?? Environment.GetEnvironmentVariable("DB_USER");
        var postgresPassword = Environment.GetEnvironmentVariable("POSTGRES_PASSWORD")
            ?? Environment.GetEnvironmentVariable("DB_PASSWORD");

        var connectionString = configuration.GetConnectionString("DefaultConnection")
            ?? Environment.GetEnvironmentVariable("ConnectionStrings__DefaultConnection");

        if (string.IsNullOrWhiteSpace(connectionString))
        {
            connectionString = $"Host={postgresHost ?? "localhost"};Port={postgresPort ?? "5432"};Database={postgresDb ?? "MustaineAI"};Username={postgresUser ?? "postgres"};Password={postgresPassword ?? "postgres"}";
        }

        var commandTimeoutSeconds = configuration.GetValue("Database:CommandTimeoutSeconds", 30);
        var connectionBuilder = new NpgsqlConnectionStringBuilder(connectionString)
        {
            Timeout = 10,
        };

        ApplyLocalPortFallback(connectionBuilder, environmentName);

        if (commandTimeoutSeconds > 0)
        {
            connectionBuilder.CommandTimeout = commandTimeoutSeconds;
        }

        var optionsBuilder = new DbContextOptionsBuilder<ApplicationDbContext>();
        optionsBuilder.UseNpgsql(
            connectionBuilder.ConnectionString,
            npgsqlOptions =>
            {
                npgsqlOptions.CommandTimeout(commandTimeoutSeconds);
                npgsqlOptions.EnableRetryOnFailure(
                    maxRetryCount: 2,
                    maxRetryDelay: TimeSpan.FromSeconds(2),
                    errorCodesToAdd: null);
            });

        return new ApplicationDbContext(optionsBuilder.Options);
    }

    private static void ApplyLocalPortFallback(NpgsqlConnectionStringBuilder connectionBuilder, string environmentName)
    {
        // This fallback is only for local development tooling (dotnet ef).
        if (!string.Equals(environmentName, "Development", StringComparison.OrdinalIgnoreCase))
        {
            return;
        }

        var host = string.IsNullOrWhiteSpace(connectionBuilder.Host) ? "localhost" : connectionBuilder.Host;
        connectionBuilder.Host = host;

        if (!IsLocalHost(host))
        {
            return;
        }

        var explicitConnectionString = Environment.GetEnvironmentVariable("ConnectionStrings__DefaultConnection");
        if (!string.IsNullOrWhiteSpace(explicitConnectionString))
        {
            return;
        }

        if (CanConnect(host, connectionBuilder.Port))
        {
            return;
        }

        // Prefer the standard local PostgreSQL port for design-time operations.
        foreach (var candidatePort in new[] { 5432 })
        {
            if (candidatePort == connectionBuilder.Port)
            {
                continue;
            }

            if (CanConnect(host, candidatePort))
            {
                connectionBuilder.Port = candidatePort;
                return;
            }
        }

        throw new InvalidOperationException(
            "Unable to find a reachable PostgreSQL endpoint for design-time EF operations. " +
            "Checked localhost port 5432. " +
            "Start a local PostgreSQL instance on 5432.");
    }

    private static bool IsLocalHost(string? host) =>
        string.Equals(host, "localhost", StringComparison.OrdinalIgnoreCase)
        || string.Equals(host, "127.0.0.1", StringComparison.OrdinalIgnoreCase)
        || string.Equals(host, "::1", StringComparison.OrdinalIgnoreCase);

    private static bool CanConnect(string host, int port)
    {
        try
        {
            using var tcpClient = new TcpClient();
            var connectTask = tcpClient.ConnectAsync(host, port);
            var timeoutTask = Task.Delay(350);

            var completedTask = Task.WhenAny(connectTask, timeoutTask).GetAwaiter().GetResult();
            if (completedTask != connectTask)
            {
                return false;
            }

            connectTask.GetAwaiter().GetResult();
            return tcpClient.Connected;
        }
        catch
        {
            return false;
        }
    }
}
