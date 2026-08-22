using MustaineAI.Data;

namespace MustaineAI.Services;

public static class FulfillmentWorkflowRules
{
    public static void ApplyLegacyStatus(FulfillmentOrderLineEntity shared, string status)
    {
        var targetProduction = status switch
        {
            "NEEDS_PRODUCTION" => "NEEDS_PRODUCTION",
            "READY_TO_SHIP" or "SHIPPED" or "COMPLETE" => "PRODUCTION_COMPLETE",
            _ => shared.ProductionStatus
        };

        var targetFulfillment = status switch
        {
            "READY_TO_SHIP" => "READY_TO_SHIP",
            "SHIPPED" => "SHIPPED",
            "COMPLETE" => "COMPLETE",
            _ => "OPEN"
        };

        shared.ProductionStatus = HigherProductionStatus(shared.ProductionStatus, targetProduction);
        shared.FulfillmentStatus = HigherFulfillmentStatus(shared.FulfillmentStatus, targetFulfillment);

        if (status == "SHIPPED" && shared.ShippedAt is null)
        {
            shared.ShippedAt = DateTimeOffset.UtcNow;
        }
    }

    public static void MarkReadyToFulfill(FulfillmentOrderLineEntity shared)
    {
        shared.ProductionStatus = HigherProductionStatus(shared.ProductionStatus, "PRODUCTION_COMPLETE");
        shared.FulfillmentStatus = HigherFulfillmentStatus(shared.FulfillmentStatus, "READY_TO_FULFILL");
    }

    public static bool IsReleasedToFulfillment(FulfillmentOrderLineEntity shared)
        => ProductionRank(shared.ProductionStatus) >= ProductionRank("PRODUCTION_COMPLETE")
           && FulfillmentRank(shared.FulfillmentStatus) >= FulfillmentRank("READY_TO_FULFILL");

    private static string HigherProductionStatus(string? current, string target)
        => ProductionRank(current) >= ProductionRank(target) ? NormalizeProduction(current) : target;

    private static string HigherFulfillmentStatus(string? current, string target)
        => FulfillmentRank(current) >= FulfillmentRank(target) ? NormalizeFulfillment(current) : target;

    private static int ProductionRank(string? status) => NormalizeProduction(status) switch
    {
        "UNASSESSED" => 0,
        "OPEN" => 0,
        "NEEDS_PRODUCTION" => 1,
        "PRODUCTION_COMPLETE" => 2,
        _ => 0
    };

    private static int FulfillmentRank(string? status) => NormalizeFulfillment(status) switch
    {
        "OPEN" => 0,
        "READY_TO_FULFILL" => 1,
        "PACKING" => 2,
        "READY_TO_SHIP" => 3,
        "SHIPPED" => 4,
        "COMPLETE" => 5,
        _ => 0
    };

    private static string NormalizeProduction(string? status)
        => string.IsNullOrWhiteSpace(status) ? "UNASSESSED" : status.Trim().ToUpperInvariant();

    private static string NormalizeFulfillment(string? status)
        => string.IsNullOrWhiteSpace(status) ? "OPEN" : status.Trim().ToUpperInvariant();
}
