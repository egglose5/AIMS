namespace MustaineAI.Data;

// Show Arm foundation. These entities intentionally do not mutate Production Arm data.
public sealed class ShowEventEntity
{
    public long Id { get; set; }
    public string Name { get; set; } = string.Empty;
    public string? PromoterName { get; set; }
    public string? City { get; set; }
    public string? State { get; set; }
    public string? EventType { get; set; }
    public string? WebsiteUrl { get; set; }
    public bool IsHardExcluded { get; set; }
    public string? ExclusionReason { get; set; }
    public string? Notes { get; set; }
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
    public ICollection<ShowEditionEntity> Editions { get; set; } = new List<ShowEditionEntity>();
}

public sealed class ShowEditionEntity
{
    public long Id { get; set; }
    public long ShowEventId { get; set; }
    public ShowEventEntity ShowEvent { get; set; } = null!;
    public int Year { get; set; }
    public DateOnly? StartDate { get; set; }
    public DateOnly? EndDate { get; set; }
    public string Status { get; set; } = "RESEARCHING";
    // Lead/research queue metadata. A manually-added show is a lead, not an endorsement.
    public string LeadSource { get; set; } = "MANUAL";
    public string ResearchStatus { get; set; } = "NEEDS_RESEARCH";
    public string Recommendation { get; set; } = "UNDECIDED";
    public string ResearchPriority { get; set; } = "NORMAL";
    public string? LeadUrl { get; set; }
    public string? LeadNote { get; set; }
    public DateTimeOffset? ResearchStartedAt { get; set; }
    public DateTimeOffset? ResearchCompletedAt { get; set; }
    public decimal? JuryFee { get; set; }
    public decimal? BoothFee { get; set; }
    public int? ClaimedAttendance { get; set; }
    public int? VerifiedAttendance { get; set; }
    public int? VendorCount { get; set; }
    public int? HandmadeVendorCount { get; set; }
    public DateOnly? ApplicationOpenDate { get; set; }
    public DateOnly? ApplicationDeadline { get; set; }
    public DateOnly? AcceptanceDate { get; set; }
    public DateOnly? BoothPaymentDeadline { get; set; }
    public string? ShowHours { get; set; }
    public string? SetupDetails { get; set; }
    public string? BoothOptions { get; set; }
    public string? SecurityDetails { get; set; }
    public string? LoadInDetails { get; set; }
    public string? ParkingDetails { get; set; }
    public string? CampingDetails { get; set; }
    public string? MapUrl { get; set; }
    public string? LocationIntel { get; set; }
    public string? MarketingIntel { get; set; }
    public string? WeatherIntel { get; set; }
    public string? AudienceOrigin { get; set; }
    public string? LocalSpendingPower { get; set; }
    public string? DestinationTourism { get; set; }
    public string? ShoppingIntent { get; set; }
    public string? EventHealth { get; set; }
    public string? MarketplaceQuality { get; set; }
    public string? VendorSaturation { get; set; }
    public string? CannibalizationRisk { get; set; }
    public string? CurrentDisruptions { get; set; }
    public string? FutureLocationRequest { get; set; }
    public string? Notes { get; set; }
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class ShowResearchEvidenceEntity
{
    public long Id { get; set; }
    public long ShowEditionId { get; set; }
    public ShowEditionEntity ShowEdition { get; set; } = null!;
    public string EvidenceType { get; set; } = "GENERAL";
    public string? SourceName { get; set; }
    public string? SourceUrl { get; set; }
    public DateOnly? SourceDate { get; set; }
    public int? AppliesToYear { get; set; }
    public string Finding { get; set; } = string.Empty;
    public string Reliability { get; set; } = "UNRATED";
    public string Sentiment { get; set; } = "NEUTRAL";
    public DateTimeOffset ResearchedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class ShowVendorProfileEntity
{
    public long Id { get; set; }
    public string VendorName { get; set; } = string.Empty;
    public string? ApplicationUserId { get; set; }
    public ApplicationUser? ApplicationUser { get; set; }
    public string? HomeCity { get; set; }
    public string? HomeState { get; set; }
    public decimal? MaxTravelHours { get; set; }
    public int? TargetShowsPerMonth { get; set; }
    public int? MaxShowsPerMonth { get; set; }
    public decimal? TargetGrossSales { get; set; }
    public bool CanCamp { get; set; }
    public bool UsuallySolo { get; set; }
    public bool IsFullTimeVendor { get; set; }
    public bool IsShowAdmin { get; set; }
    public string? SchedulingRequirements { get; set; }
    public string? SeasonalRequirements { get; set; }
    public string? Notes { get; set; }
    public bool IsActive { get; set; } = true;
}

public sealed class ShowOpportunityEntity
{
    public long Id { get; set; }
    public long ShowEditionId { get; set; }
    public ShowEditionEntity ShowEdition { get; set; } = null!;
    public long ShowVendorProfileId { get; set; }
    public ShowVendorProfileEntity ShowVendorProfile { get; set; } = null!;
    public string Priority { get; set; } = "A";
    public string Status { get; set; } = "CANDIDATE";
    public decimal? ForecastLow { get; set; }
    public decimal? ForecastHigh { get; set; }
    public string ForecastConfidence { get; set; } = "UNRATED";
    public string? FitRationale { get; set; }
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class ShowAssignmentEntity
{
    public long Id { get; set; }
    public long ShowEditionId { get; set; }
    public ShowEditionEntity ShowEdition { get; set; } = null!;
    public long ShowVendorProfileId { get; set; }
    public ShowVendorProfileEntity ShowVendorProfile { get; set; } = null!;
    public string Status { get; set; } = "OFFERED";
    public DateTimeOffset OfferedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset? RespondedAt { get; set; }
    public string? DeclineReason { get; set; }
    public DateTimeOffset? CommittedAt { get; set; }
}

public sealed class ShowApplicationEntity
{
    public long Id { get; set; }
    public long ShowEditionId { get; set; }
    public ShowEditionEntity ShowEdition { get; set; } = null!;
    public long? ShowVendorProfileId { get; set; }
    public ShowVendorProfileEntity? ShowVendorProfile { get; set; }
    public string Status { get; set; } = "READY";
    public DateTimeOffset? AppliedAt { get; set; }
    public DateTimeOffset? DecisionAt { get; set; }
    public decimal? JuryFeePaid { get; set; }
    public decimal? BoothFeePaid { get; set; }
    public string? LocationPreferenceRecommended { get; set; }
    public string? LocationPreferenceRequested { get; set; }
    public string? AssignedLocation { get; set; }
    // External application-platform intelligence. No credentials are stored here.
    public string? Platform { get; set; }
    public string? ApplicationUrl { get; set; }
    public string? ExternalApplicationId { get; set; }
    public string? ExternalStatus { get; set; }
    public string? NextAction { get; set; }
    public DateTimeOffset? LastCheckedAt { get; set; }
    public string? Notes { get; set; }
}

public sealed class ShowForecastEntity
{
    public long Id { get; set; }
    public long ShowEditionId { get; set; }
    public ShowEditionEntity ShowEdition { get; set; } = null!;
    public long? ShowVendorProfileId { get; set; }
    public ShowVendorProfileEntity? ShowVendorProfile { get; set; }
    public decimal ForecastLow { get; set; }
    public decimal ForecastHigh { get; set; }
    public decimal? ExpectedGross { get; set; }
    public decimal? ProductionTarget { get; set; }
    public string? TenKPotential { get; set; }
    public string? StockoutRisk { get; set; }
    public string? PositiveFactors { get; set; }
    public string? NegativeFactors { get; set; }
    public string? Unknowns { get; set; }
    public string? Assumptions { get; set; }
    public string Confidence { get; set; } = "UNRATED";
    public string? EvidenceSummary { get; set; }
    public DateTimeOffset FrozenAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class ShowResultEntity
{
    public long Id { get; set; }
    public long ShowEditionId { get; set; }
    public ShowEditionEntity ShowEdition { get; set; } = null!;
    public long ShowVendorProfileId { get; set; }
    public ShowVendorProfileEntity ShowVendorProfile { get; set; } = null!;
    public decimal GrossSquareSales { get; set; }
    public decimal? BoothExpense { get; set; }
    public decimal? HotelExpense { get; set; }
    public decimal? TravelExpense { get; set; }
    public decimal? OtherExpense { get; set; }
    public decimal? VendorCommission { get; set; }
    public decimal? SellingHours { get; set; }
    public decimal? RevenuePerHour { get; set; }
    public string? InventoryBrought { get; set; }
    public string? Stockouts { get; set; }
    public string? PlacementGrade { get; set; }
    public string? ReturningCustomerNotes { get; set; }
    public string? ActualWeather { get; set; }
    public string? ActualBoothLocation { get; set; }
    public string? CrowdNotes { get; set; }
    public string? Problems { get; set; }
    public bool? WouldReturn { get; set; }
    public string? ReturnReason { get; set; }
    public DateTimeOffset RecordedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class ShowTourEntity
{
    public long Id { get; set; }
    public string Name { get; set; } = string.Empty;
    public DateOnly? StartDate { get; set; }
    public DateOnly? EndDate { get; set; }
    public string? Region { get; set; }
    public string Status { get; set; } = "PLANNING";
    public string? Notes { get; set; }
}

public sealed class ShowTourStopEntity
{
    public long Id { get; set; }
    public long ShowTourId { get; set; }
    public ShowTourEntity ShowTour { get; set; } = null!;
    public long ShowEditionId { get; set; }
    public ShowEditionEntity ShowEdition { get; set; } = null!;
    public int Sequence { get; set; }
    public bool IsAnchor { get; set; }
}

public sealed class ShowLocationEntity
{
    public long Id { get; set; }
    public long ShowEventId { get; set; }
    public ShowEventEntity ShowEvent { get; set; } = null!;
    public string Name { get; set; } = string.Empty;
    public string? ParentLocationName { get; set; }
    public string? MarketplaceQuality { get; set; }
    public string? HandmadeConcentration { get; set; }
    public string? QualifiedTraffic { get; set; }
    public string? RouteCompletion { get; set; }
    public string? WalletPosition { get; set; }
    public string? PreferredPlacement { get; set; }
    public string? AvoidPlacement { get; set; }
    public string? Notes { get; set; }
}

public sealed class ShowMapEntity
{
    public long Id { get; set; }
    public long ShowEditionId { get; set; }
    public ShowEditionEntity ShowEdition { get; set; } = null!;
    public int Year { get; set; }
    public string Title { get; set; } = string.Empty;
    public string MapType { get; set; } = "VENDOR_MAP";
    public string? OriginalFileName { get; set; }
    public string? StoredFileName { get; set; }
    public string? SourceUrl { get; set; }
    public string? LocationName { get; set; }
    public string? ZoneName { get; set; }
    public string? BoothNumber { get; set; }
    public string? PlacementGrade { get; set; }
    public string? QualifiedTraffic { get; set; }
    public string? RouteCompletion { get; set; }
    public string? WalletPosition { get; set; }
    public string? Entrances { get; set; }
    public string? PrimaryRoute { get; set; }
    public string? Attractions { get; set; }
    public string? Obstructions { get; set; }
    public string? PreferredAreas { get; set; }
    public string? AvoidAreas { get; set; }
    public string? Notes { get; set; }
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class ShowLearningEntity
{
    public long Id { get; set; }
    public long ShowEditionId { get; set; }
    public ShowEditionEntity ShowEdition { get; set; } = null!;
    public long? ShowForecastId { get; set; }
    public decimal? ActualGross { get; set; }
    public decimal? DollarVariance { get; set; }
    public decimal? PercentVariance { get; set; }
    public bool? InsideForecastRange { get; set; }
    public string? Conditions { get; set; }
    public string? VarianceExplanation { get; set; }
    public string? Lesson { get; set; }
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
}

// Historical calibration records preserve owner-known outcomes without inventing an exact year when it is unknown.
public sealed class ShowCalibrationRecordEntity
{
    public long Id { get; set; }
    public long ShowEventId { get; set; }
    public ShowEventEntity ShowEvent { get; set; } = null!;
    public int? Year { get; set; }
    public string PeriodLabel { get; set; } = "HISTORICAL";
    public string? VendorName { get; set; }
    public decimal? ActualGross { get; set; }
    public decimal? GrossLow { get; set; }
    public decimal? GrossHigh { get; set; }
    public string? Placement { get; set; }
    public string? Conditions { get; set; }
    public string? Lesson { get; set; }
    public bool IsDoNotReturn { get; set; }
    public string SourceType { get; set; } = "OWNER_INTELLIGENCE";
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
}

// Candidate-discovery staging keeps noisy web search results out of the real show database until an admin accepts them.
public sealed class ShowDiscoveryLeadEntity
{
    public long Id { get; set; }
    public long? ShowVendorProfileId { get; set; }
    public ShowVendorProfileEntity? ShowVendorProfile { get; set; }
    public int TargetYear { get; set; } = 2027;
    public int? TargetMonth { get; set; }
    public string Title { get; set; } = string.Empty;
    public string Url { get; set; } = string.Empty;
    public string? Snippet { get; set; }
    public string? SearchQuery { get; set; }
    public string Status { get; set; } = "NEW";
    public DateTimeOffset DiscoveredAt { get; set; } = DateTimeOffset.UtcNow;
}

// Pass 9.4: vendor operational portal + document/email intake. Expense records are references to the future Tax Arm,
// not a second accounting system.
public sealed class ShowVendorCloseoutEntity
{
    public long Id { get; set; }
    public long ShowEditionId { get; set; }
    public long ShowVendorProfileId { get; set; }
    public decimal? VendorTrackedSales { get; set; }
    public decimal? SystemSquareSales { get; set; }
    public decimal? CommissionRate { get; set; }
    public decimal? CommissionEarned { get; set; }
    public decimal? CommissionPaid { get; set; }
    public DateOnly? CommissionPaidDate { get; set; }
    public decimal? MileageReported { get; set; }
    public string? VendorNotes { get; set; }
    public string? CloseoutStatus { get; set; } = "OPEN";
    public DateTimeOffset? ClosedAt { get; set; }
    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class ShowFinancialReferenceEntity
{
    public long Id { get; set; }
    public long ShowEditionId { get; set; }
    public long? ShowVendorProfileId { get; set; }
    public string Kind { get; set; } = "EXPENSE"; // Future Tax Arm owns the canonical financial record.
    public string Category { get; set; } = "OTHER";
    public decimal Amount { get; set; }
    public bool Reimbursable { get; set; }
    public decimal? ReimbursedAmount { get; set; }
    public DateOnly? ReimbursedDate { get; set; }
    public string? Description { get; set; }
    public string? ReceiptPath { get; set; }
    public string? TaxArmExternalKey { get; set; }
    public DateTimeOffset RecordedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class ShowDocumentEntity
{
    public long Id { get; set; }
    public long? ShowEditionId { get; set; }
    public long? ShowVendorProfileId { get; set; }
    public string DocumentType { get; set; } = "OTHER";
    public string Title { get; set; } = string.Empty;
    public string? StoredPath { get; set; }
    public string? SourceUrl { get; set; }
    public int? AppliesToYear { get; set; }
    public string? Notes { get; set; }
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class ShowNoteEntity
{
    public long Id { get; set; }
    public long ShowEditionId { get; set; }
    public ShowEditionEntity ShowEdition { get; set; } = null!;
    public long? ShowVendorProfileId { get; set; }
    public ShowVendorProfileEntity? ShowVendorProfile { get; set; }
    public string NoteType { get; set; } = "VENDOR";
    public string NoteText { get; set; } = string.Empty;
    public bool UseForShowArm { get; set; } = true;
    public bool UseForMarketing { get; set; }
    public string? CreatedBy { get; set; }
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class ShowEmailIntakeEntity
{
    public long Id { get; set; }
    public long? ShowEditionId { get; set; }
    public string? ExternalMessageId { get; set; }
    public string? ToAddress { get; set; }
    public string? FromAddress { get; set; }
    public string? Subject { get; set; }
    public string? BodyText { get; set; }
    public DateTimeOffset? MessageDate { get; set; }
    public string Route { get; set; } = "UNKNOWN";
    public string Status { get; set; } = "NEEDS_REVIEW";
    public string? MatchNotes { get; set; }
    public string? AttachmentSummary { get; set; }
    public string? MailboxAddress { get; set; }
    public string? BrainSummary { get; set; }
    public string? ActionSummary { get; set; }
    public bool IsProtectedSender { get; set; }
    public string? UnsubscribeUrl { get; set; }
    public bool UnsubscribeRecommended { get; set; }
    public DateTimeOffset ReceivedAt { get; set; } = DateTimeOffset.UtcNow;
}
