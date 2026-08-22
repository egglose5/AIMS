using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

/// <summary>
/// Narrow synchronization boundary between local Arms/Brain and the cloud Show Arm.
/// The Show Arm receives only the values it needs (for example a completed show's Square
/// gross or an expense reference); it never receives direct access to Production, Inventory,
/// Square credentials or the Tax database.
/// </summary>
public interface IShowArmGatewayService
{
    Task SyncSalesAsync(ShowSalesSyncRequest request, CancellationToken cancellationToken = default);
    Task SyncExpenseReferenceAsync(ShowExpenseSyncRequest request, CancellationToken cancellationToken = default);
}

public sealed record ShowSalesSyncRequest(long ShowEditionId, long ShowVendorProfileId, decimal GrossSquareSales, string? Source = null);
public sealed record ShowExpenseSyncRequest(long ShowEditionId, long? ShowVendorProfileId, string Category, decimal Amount, string ExternalKey, string? Description = null, bool Reimbursable = false);

public sealed class ShowArmGatewayService(ShowArmDbContext db) : IShowArmGatewayService
{
    public async Task SyncSalesAsync(ShowSalesSyncRequest request, CancellationToken cancellationToken = default)
    {
        var result = await db.ShowResults
            .FirstOrDefaultAsync(x => x.ShowEditionId == request.ShowEditionId && x.ShowVendorProfileId == request.ShowVendorProfileId, cancellationToken);

        if (result is null)
        {
            result = new ShowResultEntity
            {
                ShowEditionId = request.ShowEditionId,
                ShowVendorProfileId = request.ShowVendorProfileId,
                GrossSquareSales = request.GrossSquareSales,
                RecordedAt = DateTimeOffset.UtcNow,
            };
            db.ShowResults.Add(result);
        }
        else
        {
            result.GrossSquareSales = request.GrossSquareSales;
            result.RecordedAt = DateTimeOffset.UtcNow;
        }

        var closeout = await db.ShowVendorCloseouts
            .FirstOrDefaultAsync(x => x.ShowEditionId == request.ShowEditionId && x.ShowVendorProfileId == request.ShowVendorProfileId, cancellationToken);
        if (closeout is null)
        {
            closeout = new ShowVendorCloseoutEntity
            {
                ShowEditionId = request.ShowEditionId,
                ShowVendorProfileId = request.ShowVendorProfileId,
                SystemSquareSales = request.GrossSquareSales,
                CommissionRate = .25m,
            };
            db.ShowVendorCloseouts.Add(closeout);
        }
        else
        {
            closeout.SystemSquareSales = request.GrossSquareSales;
        }

        closeout.CommissionEarned = (closeout.SystemSquareSales ?? closeout.VendorTrackedSales ?? 0) * (closeout.CommissionRate ?? 0);
        await db.SaveChangesAsync(cancellationToken);
    }

    public async Task SyncExpenseReferenceAsync(ShowExpenseSyncRequest request, CancellationToken cancellationToken = default)
    {
        // ExternalKey makes retries idempotent. The canonical expense remains in the local Tax Arm.
        var existing = await db.ShowFinancialReferences
            .FirstOrDefaultAsync(x => x.TaxArmExternalKey == request.ExternalKey, cancellationToken);
        if (existing is null)
        {
            db.ShowFinancialReferences.Add(new ShowFinancialReferenceEntity
            {
                ShowEditionId = request.ShowEditionId,
                ShowVendorProfileId = request.ShowVendorProfileId,
                Kind = "EXPENSE",
                Category = string.IsNullOrWhiteSpace(request.Category) ? "OTHER" : request.Category.Trim().ToUpperInvariant(),
                Amount = request.Amount,
                Reimbursable = request.Reimbursable,
                Description = request.Description,
                TaxArmExternalKey = request.ExternalKey,
                RecordedAt = DateTimeOffset.UtcNow,
            });
        }
        else
        {
            existing.Amount = request.Amount;
            existing.Category = string.IsNullOrWhiteSpace(request.Category) ? existing.Category : request.Category.Trim().ToUpperInvariant();
            existing.Description = request.Description;
            existing.Reimbursable = request.Reimbursable;
        }

        await db.SaveChangesAsync(cancellationToken);
    }
}

public static class ShowArmGatewayEndpoints
{
    /// <summary>
    /// Registers the local-Brain-to-Ops synchronization API. The API is disabled unless
    /// SHOW_ARM_GATEWAY_KEY (or ShowArm:GatewayKey) is configured. This prevents accidentally
    /// exposing a write endpoint during local-only testing.
    /// </summary>
    public static IEndpointRouteBuilder MapShowArmGatewayEndpoints(this IEndpointRouteBuilder endpoints)
    {
        var group = endpoints.MapGroup("/api/show-arm/gateway");

        group.MapPost("/sales", async (HttpRequest http, ShowSalesSyncRequest request, IShowArmGatewayService gateway, IConfiguration config, CancellationToken ct) =>
        {
            if (!Authorized(http, config)) return Results.Unauthorized();
            await gateway.SyncSalesAsync(request, ct);
            return Results.Ok(new { status = "synced" });
        });

        group.MapPost("/expense", async (HttpRequest http, ShowExpenseSyncRequest request, IShowArmGatewayService gateway, IConfiguration config, CancellationToken ct) =>
        {
            if (!Authorized(http, config)) return Results.Unauthorized();
            await gateway.SyncExpenseReferenceAsync(request, ct);
            return Results.Ok(new { status = "synced" });
        });

        return endpoints;
    }

    private static bool Authorized(HttpRequest request, IConfiguration configuration)
    {
        var expected = configuration["ShowArm:GatewayKey"] ?? Environment.GetEnvironmentVariable("SHOW_ARM_GATEWAY_KEY");
        if (string.IsNullOrWhiteSpace(expected)) return false;
        return request.Headers.TryGetValue("X-AI-Brain-Key", out var supplied)
            && string.Equals(supplied.ToString(), expected, StringComparison.Ordinal);
    }
}
