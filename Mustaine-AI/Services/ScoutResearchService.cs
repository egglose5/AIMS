using System.Data;
using System.Net;
using System.Text.RegularExpressions;
using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public sealed record ScoutResearchScore(double VendorSentiment,double Tourism,double BuyerMatch,double NotebookScore,double ModuleScore,double HandmadeFit,double AiCompatibility,double AcceptanceOdds,double RevenuePotential)
{
    public double Overall => Math.Round((VendorSentiment+Tourism+BuyerMatch+NotebookScore+ModuleScore+HandmadeFit+AiCompatibility+AcceptanceOdds+RevenuePotential)/9.0,1);
}
public sealed record ScoutEvidenceSource(string Url,string Kind,bool Fetched,string Note);
public sealed record ScoutResearchResult(long LeadId,string Title,string Url,string ResearchStatus,int Confidence,string Recommendation,ScoutResearchScore Score,string EvidenceSummary,string VerifiedFacts,string MissingEvidence,IReadOnlyList<ScoutEvidenceSource> Sources,int ResearchPass,DateTimeOffset ResearchedAt);

public interface IScoutResearchService
{
    Task EnsureStorageAsync(CancellationToken ct=default);
    Task<List<ScoutResearchResult>> GetResultsAsync(CancellationToken ct=default);
    Task<ScoutResearchResult> ResearchAsync(long leadId,CancellationToken ct=default);
}

public sealed class ScoutResearchService : IScoutResearchService
{
    private readonly ApplicationDbContext _db; private readonly IHttpClientFactory _http;
    private static readonly Regex Tags=new("<[^>]+>",RegexOptions.Compiled|RegexOptions.Singleline);
    private static readonly Regex Space=new(@"\s+",RegexOptions.Compiled);
    private static readonly Regex Href=new("href\\s*=\\s*[\\\"'](?<u>[^\\\"'#>]+)",RegexOptions.IgnoreCase|RegexOptions.Compiled);
    private static readonly Regex Money=new(@"\$\s?(?<n>\d{1,4}(?:[.,]\d{2})?)",RegexOptions.Compiled);
    private static readonly Regex AttendanceNumber=new(@"\b(?<n>\d{1,3}(?:,\d{3})+|\d{4,6})\b",RegexOptions.Compiled);

    public ScoutResearchService(ApplicationDbContext db,IHttpClientFactory http){_db=db;_http=http;}

    public async Task EnsureStorageAsync(CancellationToken ct=default){
      await _db.Database.ExecuteSqlRawAsync("""
      CREATE TABLE IF NOT EXISTS "ScoutResearchResults"(
        "LeadId" bigint PRIMARY KEY,"Title" text NOT NULL,"Url" text NOT NULL,"ResearchStatus" text NOT NULL,
        "Confidence" integer NOT NULL,"Recommendation" text NOT NULL,
        "VendorSentiment" double precision NOT NULL,"Tourism" double precision NOT NULL,"BuyerMatch" double precision NOT NULL,
        "NotebookScore" double precision NOT NULL,"ModuleScore" double precision NOT NULL,"HandmadeFit" double precision NOT NULL,
        "AiCompatibility" double precision NOT NULL,"AcceptanceOdds" double precision NOT NULL,"RevenuePotential" double precision NOT NULL,
        "EvidenceSummary" text NOT NULL,"ResearchedAt" timestamptz NOT NULL);
      """,ct);
      await _db.Database.ExecuteSqlRawAsync("""
      ALTER TABLE "ScoutResearchResults" ADD COLUMN IF NOT EXISTS "VerifiedFacts" text NOT NULL DEFAULT '';
      ALTER TABLE "ScoutResearchResults" ADD COLUMN IF NOT EXISTS "MissingEvidence" text NOT NULL DEFAULT '';
      ALTER TABLE "ScoutResearchResults" ADD COLUMN IF NOT EXISTS "EvidenceSources" text NOT NULL DEFAULT '';
      ALTER TABLE "ScoutResearchResults" ADD COLUMN IF NOT EXISTS "ResearchPass" integer NOT NULL DEFAULT 1;
      """,ct);
    }

    public async Task<List<ScoutResearchResult>> GetResultsAsync(CancellationToken ct=default){
      await EnsureStorageAsync(ct); var list=new List<ScoutResearchResult>(); var c=_db.Database.GetDbConnection();
      if(c.State!=ConnectionState.Open) await c.OpenAsync(ct); await using var cmd=c.CreateCommand();
      cmd.CommandText="""
      SELECT "LeadId","Title","Url","ResearchStatus","Confidence","Recommendation",
             "VendorSentiment","Tourism","BuyerMatch","NotebookScore","ModuleScore","HandmadeFit",
             "AiCompatibility","AcceptanceOdds","RevenuePotential","EvidenceSummary","VerifiedFacts","MissingEvidence","EvidenceSources","ResearchPass","ResearchedAt"
      FROM "ScoutResearchResults" ORDER BY "ResearchedAt" DESC
      """;
      await using var r=await cmd.ExecuteReaderAsync(ct);
      while(await r.ReadAsync(ct)){var s=new ScoutResearchScore(r.GetDouble(6),r.GetDouble(7),r.GetDouble(8),r.GetDouble(9),r.GetDouble(10),r.GetDouble(11),r.GetDouble(12),r.GetDouble(13),r.GetDouble(14));
        list.Add(new(r.GetInt64(0),r.GetString(1),r.GetString(2),r.GetString(3),r.GetInt32(4),r.GetString(5),s,r.GetString(15),r.GetString(16),r.GetString(17),DecodeSources(r.GetString(18)),r.GetInt32(19),r.GetFieldValue<DateTimeOffset>(20)));} return list;
    }

    public async Task<ScoutResearchResult> ResearchAsync(long id,CancellationToken ct=default){
      await EnsureStorageAsync(ct);
      var lead=await _db.ShowDiscoveryLeads.AsNoTracking().FirstOrDefaultAsync(x=>x.Id==id&&x.Status=="SCOUT_ACCEPTED"&&x.SearchQuery!=null&&x.SearchQuery.StartsWith(OperationalBoundaryRules.ScoutLeadPrefix),ct)??throw new InvalidOperationException("Lead is not in Phase 2.");
      var prior=(await GetResultsAsync(ct)).FirstOrDefault(x=>x.LeadId==id);
      int pass=(prior?.ResearchPass??0)+1;

      var client=_http.CreateClient(); client.Timeout=TimeSpan.FromSeconds(18); client.DefaultRequestHeaders.UserAgent.ParseAdd("Mozilla/5.0 AncientInnovationsScout/2.3");
      var newSources=new List<ScoutEvidenceSource>(); var pages=new List<string>();
      var priorUrls=new HashSet<string>((prior?.Sources??Array.Empty<ScoutEvidenceSource>()).Select(x=>NormalizeUrl(x.Url)),StringComparer.OrdinalIgnoreCase);

      // Always refresh the discovery source. On later passes, widen first rather than simply replaying the same child pages.
      string first=await Fetch(client,lead.Url,newSources,"Discovery source",ct); if(first.Length>0)pages.Add(first);

      var linked=ExtractResearchLinks(lead.Url,first)
        .Where(u=>pass==1||!priorUrls.Contains(NormalizeUrl(u)))
        .OrderByDescending(LinkPriority)
        .Take(pass==1?5:8)
        .ToList();
      foreach(var u in linked){var h=await Fetch(client,u,newSources,ClassifyLink(u),ct);if(h.Length>0)pages.Add(h);}

      // S2.3 gap search: later passes deliberately search for missing evidence and independent domains.
      var gapQueries=BuildGapQueries(lead.Title,prior?.MissingEvidence,pass).Take(pass==1?3:6).ToList();
      foreach(var q in gapQueries){
        var searchUrl="https://www.bing.com/search?q="+Uri.EscapeDataString(q);
        var searchHtml=await Fetch(client,searchUrl,newSources,"Gap search: "+q,ct);
        if(searchHtml.Length==0)continue;
        foreach(var u in ExtractSearchLinks(searchHtml)
          .Where(u=>!priorUrls.Contains(NormalizeUrl(u)))
          .Where(u=>IsUsefulExternalEvidence(u,lead.Url))
          .Take(pass==1?2:4)){
            var h=await Fetch(client,u,newSources,ClassifyExternal(u),ct);
            if(h.Length>0)pages.Add(h);
        }
      }

      var allSources=MergeSources(prior?.Sources,newSources);
      var priorFacts=prior?.VerifiedFacts??"";
      var corpus=Clean(WebUtility.HtmlDecode($"{lead.Title} {lead.Snippet} {priorFacts} {string.Join(' ',pages)}")).ToLowerInvariant();
      bool Has(params string[] t)=>t.Any(corpus.Contains); int Count(params string[] t)=>t.Count(corpus.Contains); double C(double v)=>Math.Round(Math.Clamp(v,0,10),1);

      var score=new ScoutResearchScore(
        C(4.2+Count("vendor review","vendor feedback","artist review","returning vendor","sold out","great sales","strong sales","profitable")*.75-Count("poor sales","bad sales","not worth","low traffic")*.8),
        C(3.5+Count("tourism","visitors","downtown","festival","historic","destination","travel","regional draw")*.55),
        C(4.2+Count("gift","family","fantasy","game","book","art","celtic","renaissance","holiday","shopping")*.55),
        C(4.2+Count("art","book","writer","gift","historic","holiday","artisan","stationery")*.55),
        C(3.8+Count("game","gaming","family","rpg","fantasy","renaissance","celtic")*.7),
        C(3.8+Count("handmade","artisan","artist","craft","juried","maker","original work","handcrafted")*.8),
        Has("no ai","ai prohibited","artificial intelligence prohibited")?1:Has("handmade only","original work")?5.5:7,
        C(4.3+Count("vendor application","apply","application","vendors","exhibitor","jury","acceptance")*.62-(Has("fine art only")?1.5:0)),
        C(4+Count("attendance","visitors","thousands","annual","festival","market","holiday","downtown","crowd","shopping")*.48));

      var facts=new List<string>();
      AddFact(facts,Has("vendor","exhibitor"),"Vendor/exhibitor participation is referenced");
      AddFact(facts,Has("application","apply"),"Application language is present");
      AddFact(facts,Has("deadline","applications close","apply by"),"Application/deadline language is present");
      AddFact(facts,Has("handmade","artisan","juried","maker","original work","handcrafted"),"Handmade/artisan/juried language is present");
      AddFact(facts,Has("attendance","visitors","crowd"),"Attendance/visitor language is present");
      AddFact(facts,Has("annual","established","returning"),"Recurring/established-event language is present");
      AddFact(facts,Has("booth fee","vendor fee","jury fee","application fee"),"Fee language is present");
      AddFact(facts,Has("eventeny","zapplication","zapp","artcall","entrythingy","showsubmit"),"Application platform is referenced");
      AddFact(facts,Has("vendor review","vendor feedback","artist review","returning vendor"),"Independent/vendor sentiment language is present");
      AddFact(facts,Has("load in","load-in","setup","set up","parking"),"Load-in/setup logistics are referenced");

      var missing=new List<string>();
      if(!Has("attendance","visitors","crowd"))missing.Add("credible attendance evidence");
      if(!Has("booth fee","vendor fee","jury fee","application fee"))missing.Add("booth/jury fee evidence");
      if(!Has("handmade","artisan","juried","maker","original work","handcrafted"))missing.Add("handmade/jury standards");
      if(!Has("application","apply")||!Has("deadline","applications close","apply by"))missing.Add("application/deadline evidence");
      if(!Has("vendor review","vendor feedback","artist review","returning vendor"))missing.Add("independent vendor sentiment");
      if(!Has("load in","load-in","setup","set up"))missing.Add("load-in/setup evidence");

      var money=Money.Matches(corpus).Select(m=>m.Groups["n"].Value).Distinct().Take(6).ToList();
      if(money.Count>2)missing.Add("multiple fee amounts found; verify which fee applies to this event/year");
      var attendanceValues=AttendanceNumber.Matches(corpus).Select(m=>m.Groups["n"].Value).Distinct().Take(8).ToList();
      if(attendanceValues.Count>4)missing.Add("multiple attendance-sized numbers found; verify event-specific attendance");

      int fetched=allSources.Count(x=>x.Fetched);
      int domains=allSources.Where(x=>x.Fetched).Select(x=>Domain(x.Url)).Where(x=>x.Length>0&&!x.Contains("bing.com")).Distinct(StringComparer.OrdinalIgnoreCase).Count();
      bool official=allSources.Any(x=>x.Fetched&&SameDomain(x.Url,lead.Url));
      bool independent=allSources.Any(x=>x.Fetched&&!SameDomain(x.Url,lead.Url)&&!Domain(x.Url).Contains("bing.com"));
      int conf=Math.Clamp(18+facts.Count*5+Math.Min(fetched,8)*4+Math.Max(0,domains-1)*7+(official?5:0)+(independent?6:0)-missing.Count*2,15,95);
      string status=(official&&independent&&domains>=2&&facts.Count>=5)?"Cross-checked evidence":domains>=2?"Multi-source evidence":"Needs Verification";
      string rec=score.Overall>=8&&conf>=70&&official&&independent&&domains>=2?"Apply":score.Overall>=6&&conf>=50?"Research More":"Pass for Now";
      string ev=$"S2.3 research pass {pass}: {newSources.Count(x=>x.Fetched)}/{newSources.Count} new fetches; cumulative {fetched} fetched source(s) across {domains} non-search domain(s). Official source: {(official?"yes":"no")}; independent source: {(independent?"yes":"no")}. No Show Arm action was taken.";
      var result=new ScoutResearchResult(lead.Id,lead.Title,lead.Url,status,conf,rec,score,ev,string.Join("; ",facts.DefaultIfEmpty("No research facts confirmed yet")),string.Join("; ",missing.DefaultIfEmpty("No major evidence gaps detected by automated pass")),allSources,pass,DateTimeOffset.UtcNow);
      await Save(result,ct); return result;
    }

    private static IEnumerable<string> BuildGapQueries(string title,string? missing,int pass){
      var q=Quote(title); var m=(missing??"").ToLowerInvariant();
      yield return $"{q} vendor application booth fee";
      yield return $"{q} attendance visitors";
      yield return $"{q} vendor review artist review";
      if(pass>1||m.Contains("deadline"))yield return $"{q} application deadline 2027";
      if(pass>1||m.Contains("handmade"))yield return $"{q} handmade artisan vendor rules";
      if(pass>1||m.Contains("load-in"))yield return $"{q} vendor load in setup parking";
      if(pass>1)yield return $"{q} Facebook vendor review";
      if(pass>1)yield return $"{q} Reddit festival vendor";
    }
    private static string Quote(string s)=>"\""+(s??"").Replace("\"","").Trim()+"\"";
    private static void AddFact(List<string>x,bool ok,string text){if(ok&&!x.Contains(text))x.Add(text);}
    private static string Domain(string u){try{return new Uri(u).Host.Replace("www.","");}catch{return "";}}
    private static bool SameDomain(string a,string b){var da=Domain(a);var db=Domain(b);return da.Length>0&&db.Length>0&&(da==db||da.EndsWith("."+db)||db.EndsWith("."+da));}
    private static string NormalizeUrl(string u){try{var x=new Uri(u);return x.GetLeftPart(UriPartial.Path).TrimEnd('/').ToLowerInvariant();}catch{return (u??"").Trim().ToLowerInvariant();}}
    private static int LinkPriority(string u){var s=u.ToLowerInvariant();if(s.Contains("vendor")||s.Contains("exhibitor")||s.Contains("apply")||s.Contains("application"))return 5;if(s.Contains("fee")||s.Contains("rules")||s.Contains("packet"))return 4;if(s.Contains("about")||s.Contains("faq"))return 3;if(s.Contains("artist")||s.Contains("craft"))return 2;return 1;}
    private static string ClassifyLink(string u){var s=u.ToLowerInvariant();if(s.Contains("apply")||s.Contains("vendor")||s.Contains("exhibitor"))return "Official vendor/application evidence";if(s.Contains("fee")||s.Contains("rules")||s.Contains("packet"))return "Official rules/fee evidence";if(s.Contains("about"))return "Organizer/about evidence";if(s.Contains("faq"))return "FAQ evidence";return "Related event evidence";}
    private static string ClassifyExternal(string u){var d=Domain(u);if(d.Contains("reddit.com"))return "Independent community evidence";if(d.Contains("facebook.com"))return "Community/vendor evidence";if(d.Contains("festivalnet.com")||d.Contains("fairsandfestivals.net"))return "Independent directory evidence";if(d.Contains("eventeny.com")||d.Contains("zapplication.org"))return "Application-platform evidence";return "Independent web evidence";}
    private static bool IsUsefulExternalEvidence(string u,string officialUrl){var d=Domain(u);if(d.Length==0||d.Contains("bing.com")||d.Contains("microsoft.com"))return false;if(SameDomain(u,officialUrl))return true;string[] bad={"google.com","youtube.com","pinterest.com","instagram.com","tiktok.com","amazon.com","wikipedia.org"};return !bad.Any(d.Contains);}
    private static IEnumerable<string> ExtractResearchLinks(string baseUrl,string html){if(string.IsNullOrWhiteSpace(html))yield break;Uri? b=null;try{b=new Uri(baseUrl);}catch{}if(b is null)yield break;var seen=new HashSet<string>(StringComparer.OrdinalIgnoreCase);foreach(Match m in Href.Matches(html)){var raw=WebUtility.HtmlDecode(m.Groups["u"].Value).Trim();Uri? u=null;try{u=new Uri(b,raw);}catch{}if(u is null||!(u.Scheme=="http"||u.Scheme=="https"))continue;var s=u.ToString();var l=s.ToLowerInvariant();if(!(l.Contains("vendor")||l.Contains("exhibitor")||l.Contains("apply")||l.Contains("application")||l.Contains("about")||l.Contains("faq")||l.Contains("artist")||l.Contains("craft")||l.Contains("fee")||l.Contains("rules")||l.Contains("packet")))continue;if(seen.Add(s))yield return s;}}
    private static IEnumerable<string> ExtractSearchLinks(string html){var seen=new HashSet<string>(StringComparer.OrdinalIgnoreCase);foreach(Match m in Href.Matches(html??"")){var raw=WebUtility.HtmlDecode(m.Groups["u"].Value).Trim();if(!Uri.TryCreate(raw,UriKind.Absolute,out var u)||!(u.Scheme=="http"||u.Scheme=="https"))continue;var s=u.ToString();if(seen.Add(s))yield return s;}}
    private static async Task<string> Fetch(HttpClient client,string url,List<ScoutEvidenceSource> sources,string kind,CancellationToken ct){try{var html=await client.GetStringAsync(url,ct);sources.Add(new(url,kind,true,$"Fetched {html.Length:N0} chars"));return html;}catch(Exception ex){sources.Add(new(url,kind,false,ex.GetBaseException().Message));return "";}}
    private static IReadOnlyList<ScoutEvidenceSource> MergeSources(IReadOnlyList<ScoutEvidenceSource>? prior,IReadOnlyList<ScoutEvidenceSource> current){var map=new Dictionary<string,ScoutEvidenceSource>(StringComparer.OrdinalIgnoreCase);foreach(var x in prior??Array.Empty<ScoutEvidenceSource>())map[NormalizeUrl(x.Url)]=x;foreach(var x in current)map[NormalizeUrl(x.Url)]=x;return map.Values.OrderByDescending(x=>x.Fetched).ThenBy(x=>x.Kind).ToList();}
    private static string EncodeSources(IReadOnlyList<ScoutEvidenceSource> xs)=>string.Join("\n",xs.Select(x=>$"{(x.Fetched?"1":"0")}|{x.Kind.Replace("|","/")}|{x.Url.Replace("|","%7C")}|{x.Note.Replace("|","/").Replace("\n"," ")}"));
    private static IReadOnlyList<ScoutEvidenceSource> DecodeSources(string s){var list=new List<ScoutEvidenceSource>();foreach(var line in (s??"").Split('\n',StringSplitOptions.RemoveEmptyEntries)){var p=line.Split('|',4);if(p.Length==4)list.Add(new(p[2].Replace("%7C","|"),p[1],p[0]=="1",p[3]));}return list;}
    private async Task Save(ScoutResearchResult x,CancellationToken ct){var c=_db.Database.GetDbConnection();if(c.State!=ConnectionState.Open)await c.OpenAsync(ct);await using var cmd=c.CreateCommand();cmd.CommandText="""
      INSERT INTO "ScoutResearchResults" ("LeadId","Title","Url","ResearchStatus","Confidence","Recommendation","VendorSentiment","Tourism","BuyerMatch","NotebookScore","ModuleScore","HandmadeFit","AiCompatibility","AcceptanceOdds","RevenuePotential","EvidenceSummary","VerifiedFacts","MissingEvidence","EvidenceSources","ResearchPass","ResearchedAt")
      VALUES (@id,@t,@u,@st,@cf,@rec,@a,@b,@c,@d,@e,@f,@g,@h,@i,@ev,@vf,@me,@es,@rp,@at)
      ON CONFLICT ("LeadId") DO UPDATE SET "Title"=EXCLUDED."Title","Url"=EXCLUDED."Url","ResearchStatus"=EXCLUDED."ResearchStatus","Confidence"=EXCLUDED."Confidence","Recommendation"=EXCLUDED."Recommendation","VendorSentiment"=EXCLUDED."VendorSentiment","Tourism"=EXCLUDED."Tourism","BuyerMatch"=EXCLUDED."BuyerMatch","NotebookScore"=EXCLUDED."NotebookScore","ModuleScore"=EXCLUDED."ModuleScore","HandmadeFit"=EXCLUDED."HandmadeFit","AiCompatibility"=EXCLUDED."AiCompatibility","AcceptanceOdds"=EXCLUDED."AcceptanceOdds","RevenuePotential"=EXCLUDED."RevenuePotential","EvidenceSummary"=EXCLUDED."EvidenceSummary","VerifiedFacts"=EXCLUDED."VerifiedFacts","MissingEvidence"=EXCLUDED."MissingEvidence","EvidenceSources"=EXCLUDED."EvidenceSources","ResearchPass"=EXCLUDED."ResearchPass","ResearchedAt"=EXCLUDED."ResearchedAt";
      """;void P(string n,object v){var p=cmd.CreateParameter();p.ParameterName=n;p.Value=v;cmd.Parameters.Add(p);}P("@id",x.LeadId);P("@t",x.Title);P("@u",x.Url);P("@st",x.ResearchStatus);P("@cf",x.Confidence);P("@rec",x.Recommendation);P("@a",x.Score.VendorSentiment);P("@b",x.Score.Tourism);P("@c",x.Score.BuyerMatch);P("@d",x.Score.NotebookScore);P("@e",x.Score.ModuleScore);P("@f",x.Score.HandmadeFit);P("@g",x.Score.AiCompatibility);P("@h",x.Score.AcceptanceOdds);P("@i",x.Score.RevenuePotential);P("@ev",x.EvidenceSummary);P("@vf",x.VerifiedFacts);P("@me",x.MissingEvidence);P("@es",EncodeSources(x.Sources));P("@rp",x.ResearchPass);P("@at",x.ResearchedAt);await cmd.ExecuteNonQueryAsync(ct);}
    private static string Clean(string s)=>Space.Replace(Tags.Replace(s??""," ")," ").Trim();
}
