namespace MustaineAI.Services;

public static class OperationalBoundaryRules
{
    public const string ScoutLeadPrefix = "SCOUT:";

    public static bool IsScoutLead(string? searchQuery)
        => !string.IsNullOrWhiteSpace(searchQuery)
           && searchQuery.StartsWith(ScoutLeadPrefix, StringComparison.OrdinalIgnoreCase);

    public static bool IsShowFinderLead(string? searchQuery)
        => !IsScoutLead(searchQuery);
}
