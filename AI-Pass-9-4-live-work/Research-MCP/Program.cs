using ModelContextProtocol.AspNetCore;

var builder = WebApplication.CreateBuilder(args);

var configuredUrls = Environment.GetEnvironmentVariable("ASPNETCORE_URLS");
if (!string.IsNullOrWhiteSpace(configuredUrls))
{
    builder.WebHost.UseUrls(configuredUrls);
}
else
{
    var runningInContainer = string.Equals(
        Environment.GetEnvironmentVariable("DOTNET_RUNNING_IN_CONTAINER"),
        "true",
        StringComparison.OrdinalIgnoreCase);

    if (!runningInContainer)
    {
        builder.WebHost.UseUrls("http://127.0.0.1:5298");
    }
}

builder.Services
    .AddMcpServer()
    .WithHttpTransport(options =>
    {
        // Support current stateless HTTP clients while still answering legacy initialize
        // handshakes on the same endpoint during the blank-foundation phase.
        options.SessionMode = HttpServerSessionMode.StatefulForInitializeClients;
    });

var app = builder.Build();

app.MapGet("/healthz", () => Results.Ok(new
{
    status = "ok",
    service = "research-mcp"
}));

app.MapMcp("/mcp");

app.Logger.LogInformation("Research MCP blank foundation listening with MCP endpoint at /mcp");

await app.RunAsync();
