using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public sealed class ShowFinderBackgroundService : BackgroundService
{
    private readonly IServiceScopeFactory _scopeFactory;
    private readonly ILogger<ShowFinderBackgroundService> _logger;

    public ShowFinderBackgroundService(IServiceScopeFactory scopeFactory, ILogger<ShowFinderBackgroundService> logger)
    {
        _scopeFactory = scopeFactory;
        _logger = logger;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        await Task.Delay(TimeSpan.FromSeconds(10), stoppingToken);
        var nextDiscoverySweep = DateTimeOffset.UtcNow.AddHours(12);

        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                await ProcessOneResearchLeadAsync(stoppingToken);

                if (DateTimeOffset.UtcNow >= nextDiscoverySweep)
                {
                    await RunDiscoverySweepAsync(stoppingToken);
                    nextDiscoverySweep = DateTimeOffset.UtcNow.AddHours(12);
                }
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested) { }
            catch (Exception ex)
            {
                _logger.LogWarning(ex, "Show research queue worker failed one cycle; app remains available.");
            }

            await Task.Delay(TimeSpan.FromSeconds(3), stoppingToken);
        }
    }

    private async Task ProcessOneResearchLeadAsync(CancellationToken ct)
    {
        using var scope = _scopeFactory.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<ShowArmDbContext>();
        var placement = scope.ServiceProvider.GetRequiredService<IShowPlacementService>();

        var lead = await db.ShowDiscoveryLeads
            .Where(x => x.Status == "RESEARCH_QUEUED"
                     && (x.SearchQuery == null || !x.SearchQuery.StartsWith(OperationalBoundaryRules.ScoutLeadPrefix)))
            .OrderBy(x => x.DiscoveredAt)
            .FirstOrDefaultAsync(ct);

        if (lead is null) return;

        lead.Status = "RESEARCHING";
        await db.SaveChangesAsync(ct);

        try
        {
            await placement.ProcessResearchLeadAsync(lead.Id, ct);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
        catch (Exception ex)
        {
            using var failScope = _scopeFactory.CreateScope();
            var failDb = failScope.ServiceProvider.GetRequiredService<ShowArmDbContext>();
            var failed = await failDb.ShowDiscoveryLeads.FirstOrDefaultAsync(x => x.Id == lead.Id, ct);
            if (failed is not null)
            {
                failed.Status = "RESEARCH_ERROR";
                failed.Snippet = Limit($"{failed.Snippet} | Research error: {ex.Message}", 1500);
                await failDb.SaveChangesAsync(ct);
            }
            _logger.LogWarning(ex, "Research queue failed lead {LeadId}", lead.Id);
        }
    }

    private async Task RunDiscoverySweepAsync(CancellationToken ct)
    {
        using var scope = _scopeFactory.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<ShowArmDbContext>();
        var web = scope.ServiceProvider.GetRequiredService<IShowWebResearchService>();

        var vendors = await db.ShowVendorProfiles.AsNoTracking()
            .Where(x => x.IsActive && x.TargetShowsPerMonth != null && x.TargetShowsPerMonth > 0)
            .ToListAsync(ct);

        var today = DateTime.Today;
        foreach (var vendor in vendors)
        {
            for (var offset = 1; offset <= 4; offset++)
            {
                var d = new DateTime(today.Year, today.Month, 1).AddMonths(offset);
                var recent = await db.ShowDiscoveryLeads.AsNoTracking().AnyAsync(x =>
                    x.ShowVendorProfileId == vendor.Id &&
                    x.TargetYear == d.Year &&
                    x.TargetMonth == d.Month &&
                    (x.SearchQuery == null || !x.SearchQuery.StartsWith(OperationalBoundaryRules.ScoutLeadPrefix)) &&
                    x.DiscoveredAt >= DateTimeOffset.UtcNow.AddHours(-24), ct);

                if (!recent)
                {
                    try { await web.DiscoverCandidatesAsync(vendor.Id, d.Year, d.Month, ct); }
                    catch (Exception ex) { _logger.LogDebug(ex, "Background discovery skipped {Vendor}/{Month}", vendor.VendorName, d.Month); }
                }
            }
        }
    }

    private static string Limit(string? value, int length)
    {
        var s = value ?? "";
        return s.Length <= length ? s : s[..length];
    }
}
