using ModelContextProtocol.Client;
using System.Net.Http.Headers;
using System.Text;

var endpoint = args.Length > 0
    ? args[0]
    : Environment.GetEnvironmentVariable("RESEARCH_MCP_ENDPOINT") ?? "http://127.0.0.1:8081/mcp";

var endpointUri = new Uri(endpoint);
var healthUri = new Uri(endpointUri, "/healthz");

using var httpClient = new HttpClient
{
    Timeout = TimeSpan.FromSeconds(15)
};

var healthResponse = await httpClient.GetAsync(healthUri);
healthResponse.EnsureSuccessStatusCode();
Console.WriteLine($"HealthCheck: {(int)healthResponse.StatusCode} {healthResponse.ReasonPhrase}");
Console.WriteLine($"HealthBody: {await healthResponse.Content.ReadAsStringAsync()}");

var initializePayload =
    """
    {"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-05","capabilities":{},"clientInfo":{"name":"research-mcp-legacy-initialize-check","version":"0.1.0"}}}
    """;

using var initializeRequest = new HttpRequestMessage(HttpMethod.Post, endpointUri)
{
    Content = new StringContent(initializePayload, Encoding.UTF8, "application/json")
};
initializeRequest.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
initializeRequest.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("text/event-stream"));

using var initializeResponse = await httpClient.SendAsync(initializeRequest);
var initializeBody = await initializeResponse.Content.ReadAsStringAsync();

Console.WriteLine($"LegacyInitialize: {(int)initializeResponse.StatusCode} {initializeResponse.ReasonPhrase}");
Console.WriteLine($"LegacyInitializeSessionId: {string.Join(",", initializeResponse.Headers.TryGetValues("Mcp-Session-Id", out var sessionIds) ? sessionIds : [])}");
Console.WriteLine($"LegacyInitializeBody: {initializeBody}");

if (!initializeResponse.IsSuccessStatusCode || !initializeBody.Contains("\"result\"", StringComparison.Ordinal))
{
    throw new InvalidOperationException("Legacy initialize request did not return a successful MCP result.");
}

var transport = new HttpClientTransport(new HttpClientTransportOptions
{
    Endpoint = endpointUri,
    TransportMode = HttpTransportMode.StreamableHttp,
    ConnectionTimeout = TimeSpan.FromSeconds(15)
});

await using var client = await McpClient.CreateAsync(
    transport,
    new McpClientOptions
    {
        ClientInfo = new()
        {
            Name = "research-mcp-handshake-verifier",
            Version = "0.1.0"
        }
    });

Console.WriteLine($"Connected to {endpoint}");
Console.WriteLine($"SessionId: {client.SessionId ?? "(none)"}");

try
{
    var tools = await client.ListToolsAsync();
    Console.WriteLine($"ToolCount: {tools.Count}");
}
catch (HttpRequestException exception) when (exception.Message.Contains("tools/list", StringComparison.Ordinal))
{
    Console.WriteLine("ToolCount: unsupported (blank server exposes no tools yet)");
}
