# Research MCP Tool Contracts

## Architecture boundary

Research MCP is the research, retrieval, and evidence capability layer.

It may:

- fetch public sources
- discover related documents
- inspect documents
- extract candidate facts
- return evidence and provenance

It may not:

- make business decisions
- decide whether Ancient Innovations should apply to a show
- write canonical show or application data
- replace Mercury orchestration
- replace Lynks or Show Arm canonical storage

These contracts are derived from `PHASE2-SCOUT-CAPABILITY-AUDIT.md`.

## Why these first tools

The first two tools selected are:

1. `discover_documents`
2. `inspect_document`

They are the correct starting point because:

- Phase 2 identified PDF and document research as a foundational reusable capability.
- They are narrower and safer than a full `research_show` tool.
- They are directly useful for vendor packets, maps, rules, fees, deadlines, and setup information.
- They preserve the Research MCP boundary as retrieval and evidence, not business reasoning.
- They can be tested end-to-end without introducing Mercury decisions, Lynks writes, or a business database.

They are intentionally chosen ahead of `discover_shows` or `research_show` because those higher-level tools would otherwise force premature interface decisions about identity resolution, broader source comparison, and recommendation shaping.

## Contract conventions

- Every tool returns a top-level `contract_version`.
- Initial contract version: `2026-08-22.v1`
- Future additions should be backward-compatible and additive where possible.
- Required fields must always be present unless the whole call fails with an error.
- Optional fields may be omitted or returned as `null` where the schema permits it.

## Tool 1

### Name

`discover_documents`

### Description

Discover public document candidates related to a show, event, or event application process.

### Purpose

Find likely documents such as vendor packets, exhibitor kits, maps, rules PDFs, applications, load-in instructions, and related attachments from public sources.

### What it does

- uses supplied show context to search public sources
- follows relevant public pages as needed
- identifies likely document candidates
- classifies each candidate document type
- returns provenance for each candidate

### What it does not do

- does not decide which document is authoritative
- does not inspect full document contents beyond lightweight discovery metadata
- does not update Lynks, Show Arm, or Mercury state
- does not create a permanent document store
- does not make application or planning recommendations

### Required inputs

- `subject`

### Optional inputs

- `year`
- `location_hint`
- `aliases`
- `known_urls`
- `document_types`
- `max_results`
- `allowed_source_types`
- `include_html_documents`
- `request_id`

### Input JSON schema

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "additionalProperties": false,
  "required": ["subject"],
  "properties": {
    "subject": {
      "type": "string",
      "minLength": 1,
      "maxLength": 300,
      "description": "Human-readable show or event identity seed, such as an event name."
    },
    "year": {
      "type": "integer",
      "minimum": 2000,
      "maximum": 2100
    },
    "location_hint": {
      "type": "string",
      "maxLength": 200
    },
    "aliases": {
      "type": "array",
      "maxItems": 20,
      "items": {
        "type": "string",
        "minLength": 1,
        "maxLength": 300
      }
    },
    "known_urls": {
      "type": "array",
      "maxItems": 20,
      "items": {
        "type": "string",
        "format": "uri"
      }
    },
    "document_types": {
      "type": "array",
      "maxItems": 12,
      "items": {
        "type": "string",
        "enum": [
          "vendor_packet",
          "vendor_map",
          "rules",
          "application",
          "load_in",
          "parking",
          "setup",
          "faq",
          "cancellation_policy",
          "booth_info",
          "jury_info",
          "unknown"
        ]
      }
    },
    "max_results": {
      "type": "integer",
      "minimum": 1,
      "maximum": 50,
      "default": 10
    },
    "allowed_source_types": {
      "type": "array",
      "maxItems": 10,
      "items": {
        "type": "string",
        "enum": [
          "official_site",
          "application_platform",
          "directory",
          "community",
          "other"
        ]
      }
    },
    "include_html_documents": {
      "type": "boolean",
      "default": true
    },
    "request_id": {
      "type": "string",
      "maxLength": 100
    }
  }
}
```

### Outputs

Returns zero or more candidate documents with discovery provenance and classification.

### Output JSON schema

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "additionalProperties": false,
  "required": [
    "contract_version",
    "tool_name",
    "subject",
    "documents",
    "search_summary"
  ],
  "properties": {
    "contract_version": {
      "type": "string"
    },
    "tool_name": {
      "type": "string",
      "const": "discover_documents"
    },
    "subject": {
      "type": "string"
    },
    "year": {
      "type": ["integer", "null"]
    },
    "documents": {
      "type": "array",
      "items": {
        "type": "object",
        "additionalProperties": false,
        "required": [
          "document_id",
          "url",
          "document_type",
          "source_type",
          "discovery_confidence",
          "retrieved_at",
          "provenance"
        ],
        "properties": {
          "document_id": {
            "type": "string"
          },
          "url": {
            "type": "string",
            "format": "uri"
          },
          "title": {
            "type": ["string", "null"]
          },
          "document_type": {
            "type": "string"
          },
          "mime_type_hint": {
            "type": ["string", "null"]
          },
          "source_type": {
            "type": "string"
          },
          "source_title": {
            "type": ["string", "null"]
          },
          "source_url": {
            "type": ["string", "null"],
            "format": "uri"
          },
          "publisher_hint": {
            "type": ["string", "null"]
          },
          "year_hint": {
            "type": ["integer", "null"]
          },
          "discovery_confidence": {
            "type": "number",
            "minimum": 0,
            "maximum": 1
          },
          "retrieved_at": {
            "type": "string",
            "format": "date-time"
          },
          "notes": {
            "type": "array",
            "items": {
              "type": "string"
            }
          },
          "provenance": {
            "$ref": "#/$defs/evidence"
          }
        }
      }
    },
    "search_summary": {
      "type": "object",
      "additionalProperties": false,
      "required": [
        "search_performed",
        "pages_examined",
        "documents_considered",
        "documents_returned"
      ],
      "properties": {
        "search_performed": {
          "type": "boolean"
        },
        "pages_examined": {
          "type": "integer",
          "minimum": 0
        },
        "documents_considered": {
          "type": "integer",
          "minimum": 0
        },
        "documents_returned": {
          "type": "integer",
          "minimum": 0
        }
      }
    }
  },
  "$defs": {
    "evidence": {
      "type": "object",
      "additionalProperties": false,
      "required": [
        "evidence_id",
        "source_type",
        "source_url",
        "retrieved_at",
        "claim_type",
        "claim_value",
        "support_level"
      ],
      "properties": {
        "evidence_id": {
          "type": "string"
        },
        "source_type": {
          "type": "string"
        },
        "source_title": {
          "type": ["string", "null"]
        },
        "source_url": {
          "type": "string",
          "format": "uri"
        },
        "retrieved_at": {
          "type": "string",
          "format": "date-time"
        },
        "claim_type": {
          "type": "string"
        },
        "claim_value": {
          "type": ["string", "number", "boolean", "null"]
        },
        "excerpt": {
          "type": ["string", "null"]
        },
        "support_level": {
          "type": "string",
          "enum": [
            "explicit",
            "strong_signal",
            "weak_signal",
            "inferred"
          ]
        },
        "source_reliability": {
          "type": ["number", "null"],
          "minimum": 0,
          "maximum": 1
        },
        "conflict_status": {
          "type": "string",
          "enum": [
            "none",
            "possible_conflict",
            "conflict"
          ]
        }
      }
    }
  }
}
```

### Required output fields

- `contract_version`
- `tool_name`
- `subject`
- `documents`
- `search_summary`

For each document:

- `document_id`
- `url`
- `document_type`
- `source_type`
- `discovery_confidence`
- `retrieved_at`
- `provenance`

### Optional output fields

- `title`
- `mime_type_hint`
- `source_title`
- `source_url`
- `publisher_hint`
- `year_hint`
- `notes`

### Evidence

Every returned document candidate must include one evidence object describing why the candidate was returned.

### Error behavior

May return structured errors for:

- invalid input
- retrieval failure
- blocked source
- timeout
- no candidate sources reachable

The tool should not error simply because zero documents were found. Zero results is a successful empty result.

### Timeout considerations

- Intended for bounded network work.
- Recommended default timeout: 20 seconds.
- If partial results exist at timeout, the implementation may return partial successful results only if the response clearly indicates truncation in a future additive field. In v1, timeout should normally return an error instead of silently truncating.

### Evidence and provenance behavior

- Every candidate must identify the page or source that led to discovery.
- Discovery provenance is required even if the actual document was not yet deeply inspected.

### Confidence behavior

`discovery_confidence` means confidence that the discovered URL is relevant to the requested subject and likely belongs to the reported document type.

It does not mean the document is authoritative or factually correct.

### Determinism

- Expected to be mostly deterministic for the same source corpus.
- Results may vary over time as public pages change.
- No model-based business reasoning is expected in v1.

### External dependencies

- public internet sources
- public HTML pages
- public linked files

### Internet access

- yes

### Stored data access

- no required persistent business-data access
- future implementations may optionally use ephemeral cache only

### Modification behavior

- read-only
- no side effects

### Caller

- called directly by Mercury
- may also be called internally by future higher-level tools such as `research_show`

### Versioning

- stable tool name
- additive fields only for backward-compatible changes
- breaking changes require a new contract version and ideally a new tool name only if semantics materially change

## Tool 2

### Name

`inspect_document`

### Description

Retrieve and inspect a single document or document-like URL and return extracted candidate facts plus evidence.

### Purpose

Turn one document candidate into structured research output with provenance.

### What it does

- retrieves a document or document-like page
- identifies basic file metadata
- extracts text when supported
- identifies candidate facts
- returns excerpts and provenance
- reports ambiguity, missing information, and extraction limitations

### What it does not do

- does not decide whether extracted facts should become canonical business data
- does not merge facts into Lynks or Show Arm
- does not make recommendations
- does not perform multi-source conflict resolution beyond reporting conflicts within the inspected artifact

### Required inputs

- `document_url`

### Optional inputs

- `document_type_hint`
- `subject`
- `year`
- `fact_types`
- `max_excerpt_length`
- `include_raw_text`
- `request_id`

### Input JSON schema

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "additionalProperties": false,
  "required": ["document_url"],
  "properties": {
    "document_url": {
      "type": "string",
      "format": "uri"
    },
    "document_type_hint": {
      "type": "string",
      "enum": [
        "vendor_packet",
        "vendor_map",
        "rules",
        "application",
        "load_in",
        "parking",
        "setup",
        "faq",
        "cancellation_policy",
        "booth_info",
        "jury_info",
        "unknown"
      ]
    },
    "subject": {
      "type": "string",
      "maxLength": 300
    },
    "year": {
      "type": "integer",
      "minimum": 2000,
      "maximum": 2100
    },
    "fact_types": {
      "type": "array",
      "maxItems": 20,
      "items": {
        "type": "string",
        "enum": [
          "event_dates",
          "application_deadline",
          "application_open_date",
          "booth_fee",
          "jury_fee",
          "commission_rate",
          "application_url",
          "application_method",
          "vendor_packet_url",
          "vendor_map_url",
          "load_in_instructions",
          "parking_info",
          "setup_info",
          "cancellation_policy",
          "contact_name",
          "contact_email",
          "contact_phone",
          "handmade_rule",
          "resale_rule",
          "unknown"
        ]
      }
    },
    "max_excerpt_length": {
      "type": "integer",
      "minimum": 50,
      "maximum": 2000,
      "default": 400
    },
    "include_raw_text": {
      "type": "boolean",
      "default": false
    },
    "request_id": {
      "type": "string",
      "maxLength": 100
    }
  }
}
```

### Outputs

Returns extraction status, file metadata, candidate facts, evidence, and extraction limitations.

### Output JSON schema

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "additionalProperties": false,
  "required": [
    "contract_version",
    "tool_name",
    "document",
    "extraction_status",
    "candidate_facts",
    "evidence"
  ],
  "properties": {
    "contract_version": {
      "type": "string"
    },
    "tool_name": {
      "type": "string",
      "const": "inspect_document"
    },
    "document": {
      "type": "object",
      "additionalProperties": false,
      "required": [
        "document_id",
        "document_url",
        "retrieved_at"
      ],
      "properties": {
        "document_id": {
          "type": "string"
        },
        "document_url": {
          "type": "string",
          "format": "uri"
        },
        "final_url": {
          "type": ["string", "null"],
          "format": "uri"
        },
        "retrieved_at": {
          "type": "string",
          "format": "date-time"
        },
        "mime_type": {
          "type": ["string", "null"]
        },
        "http_status": {
          "type": ["integer", "null"]
        },
        "content_length": {
          "type": ["integer", "null"],
          "minimum": 0
        },
        "content_hash": {
          "type": ["string", "null"]
        },
        "document_type": {
          "type": ["string", "null"]
        },
        "title": {
          "type": ["string", "null"]
        }
      }
    },
    "extraction_status": {
      "type": "object",
      "additionalProperties": false,
      "required": [
        "retrieval_succeeded",
        "text_extraction_succeeded",
        "facts_found"
      ],
      "properties": {
        "retrieval_succeeded": {
          "type": "boolean"
        },
        "text_extraction_succeeded": {
          "type": "boolean"
        },
        "facts_found": {
          "type": "boolean"
        },
        "limitations": {
          "type": "array",
          "items": {
            "type": "string"
          }
        }
      }
    },
    "candidate_facts": {
      "type": "array",
      "items": {
        "type": "object",
        "additionalProperties": false,
        "required": [
          "fact_id",
          "fact_type",
          "value_status",
          "support_level",
          "candidate_business_fact",
          "evidence_ids"
        ],
        "properties": {
          "fact_id": {
            "type": "string"
          },
          "fact_type": {
            "type": "string"
          },
          "value_status": {
            "type": "string",
            "enum": [
              "found",
              "not_found",
              "inferred",
              "conflicting"
            ]
          },
          "value": {
            "type": ["string", "number", "boolean", "null"]
          },
          "normalized_value": {
            "type": ["string", "number", "boolean", "null"]
          },
          "units": {
            "type": ["string", "null"]
          },
          "support_level": {
            "type": "string",
            "enum": [
              "explicit",
              "strong_signal",
              "weak_signal",
              "inferred"
            ]
          },
          "field_confidence": {
            "type": ["number", "null"],
            "minimum": 0,
            "maximum": 1
          },
          "candidate_business_fact": {
            "type": "boolean"
          },
          "evidence_ids": {
            "type": "array",
            "minItems": 1,
            "items": {
              "type": "string"
            }
          },
          "notes": {
            "type": "array",
            "items": {
              "type": "string"
            }
          }
        }
      }
    },
    "evidence": {
      "type": "array",
      "items": {
        "$ref": "#/$defs/evidence"
      }
    },
    "raw_text": {
      "type": ["string", "null"]
    }
  },
  "$defs": {
    "evidence": {
      "type": "object",
      "additionalProperties": false,
      "required": [
        "evidence_id",
        "source_type",
        "source_url",
        "retrieved_at",
        "claim_type",
        "claim_value",
        "support_level"
      ],
      "properties": {
        "evidence_id": {
          "type": "string"
        },
        "source_type": {
          "type": "string"
        },
        "source_title": {
          "type": ["string", "null"]
        },
        "source_url": {
          "type": "string",
          "format": "uri"
        },
        "retrieved_at": {
          "type": "string",
          "format": "date-time"
        },
        "claim_type": {
          "type": "string"
        },
        "claim_value": {
          "type": ["string", "number", "boolean", "null"]
        },
        "excerpt": {
          "type": ["string", "null"]
        },
        "support_level": {
          "type": "string",
          "enum": [
            "explicit",
            "strong_signal",
            "weak_signal",
            "inferred"
          ]
        },
        "source_reliability": {
          "type": ["number", "null"],
          "minimum": 0,
          "maximum": 1
        },
        "conflict_status": {
          "type": "string",
          "enum": [
            "none",
            "possible_conflict",
            "conflict"
          ]
        }
      }
    }
  }
}
```

### Required output fields

- `contract_version`
- `tool_name`
- `document`
- `extraction_status`
- `candidate_facts`
- `evidence`

### Optional output fields

- `raw_text`
- optional metadata fields inside `document`
- optional fact notes

### Evidence

Every candidate fact with `value_status` other than `not_found` must reference at least one evidence object through `evidence_ids`.

### Error behavior

May return structured errors for:

- invalid input
- source not found
- blocked source
- retrieval failed
- timeout
- unsupported document
- extraction failure

If retrieval succeeds but no relevant facts are found, the tool should succeed with:

- `retrieval_succeeded = true`
- `text_extraction_succeeded = true` or `false` depending on the actual result
- `facts_found = false`

### Timeout considerations

- Recommended default timeout: 30 seconds
- Large or slow documents may time out
- Timeout should return a structured error rather than partial ambiguous extraction in v1

### Evidence and provenance behavior

- evidence must preserve the inspected document URL
- evidence must preserve retrieval timestamp
- evidence excerpts should be direct support, not summary-only whenever possible
- support level must distinguish explicit text from inference

### Confidence behavior

`field_confidence` means confidence in the extraction of the candidate fact from this inspected artifact.

It does not mean:

- the business should trust the fact
- the source is authoritative
- the fact has been cross-checked against other sources

Semantics:

- retrieval succeeded: the document was fetched
- information explicitly found: the document directly states the fact
- inferred: the fact required interpretation, normalization, or indirect patterning
- conflicting: the same inspected artifact produced inconsistent candidate values
- not found: the inspected artifact did not yield the requested fact

### Determinism

- expected to be deterministic for the same artifact bytes and parser behavior
- may vary if the source content changes or the file at the URL changes

### External dependencies

- public internet access
- supported text or document extraction runtime

### Internet access

- yes

### Stored data access

- no required persistent business-data access
- may use ephemeral cache only

### Modification behavior

- read-only
- no side effects

### Caller

- can be called directly by Mercury for targeted inspection
- can be called internally by future `research_show` or `discover_documents` follow-up workflows

### Versioning

- stable tool name
- additive evolution preferred
- schema-breaking changes require a new contract version

## Shared evidence contract

Use one reusable evidence structure across future research tools.

### Evidence object

```json
{
  "evidence_id": "string",
  "source_type": "official_site | application_platform | directory | community | document | other",
  "source_title": "string | null",
  "source_url": "uri",
  "retrieved_at": "date-time",
  "claim_type": "string",
  "claim_value": "string | number | boolean | null",
  "excerpt": "string | null",
  "support_level": "explicit | strong_signal | weak_signal | inferred",
  "source_reliability": "number | null",
  "conflict_status": "none | possible_conflict | conflict"
}
```

### Evidence semantics

- `source_type`: what kind of source produced the claim
- `source_title`: human-readable source label when available
- `source_url`: exact source reference
- `retrieved_at`: when Research MCP saw the source
- `claim_type`: the fact class supported by the evidence
- `claim_value`: the supported claim value
- `excerpt`: supporting text or near-verbatim support when appropriate
- `support_level`: how directly the source supports the claim
- `source_reliability`: source-quality estimate, not truth
- `conflict_status`: whether other seen evidence appears to disagree

This structure is intentionally small enough for Mercury reasoning and eventual Lynks persistence.

## Shared error contract

All machine-readable errors should use a simple structured shape.

### Error object

```json
{
  "error": {
    "code": "invalid_input | source_not_found | blocked_source | retrieval_failed | timeout | unsupported_document | extraction_failed | conflicting_sources | internal_error",
    "message": "human-readable summary",
    "retryable": true,
    "details": {
      "field": "optional field name",
      "source_url": "optional uri",
      "http_status": 404
    }
  }
}
```

### Error rules

- `invalid_input`: caller supplied malformed or incomplete input
- `source_not_found`: source returned 404 or equivalent
- `blocked_source`: source type or target is blocked by policy or implementation
- `retrieval_failed`: network or fetch failure after valid input
- `timeout`: request exceeded allowed execution time
- `unsupported_document`: file type or structure cannot be processed
- `extraction_failed`: retrieval worked but parsing failed
- `conflicting_sources`: reserved for future multi-source tools more than these first two
- `internal_error`: unexpected failure

## Data ownership

Every output field from these tools should be understood through ownership categories.

### A. Transient research result

Examples:

- `search_summary`
- `extraction_status`
- `discovery_confidence`
- `field_confidence`
- `limitations`

Owned by Research MCP at runtime only.

### B. Evidence or provenance

Examples:

- `provenance`
- `evidence`
- `retrieved_at`
- `source_url`
- `excerpt`
- `support_level`

Research MCP can emit these; Mercury and Lynks may later persist them.

### C. Candidate business fact

Examples:

- `application_deadline`
- `booth_fee`
- `jury_fee`
- `event_dates`
- `load_in_instructions`
- `contact_email`

Research MCP may return these only as candidates supported by evidence.

### D. Canonical business data

Examples:

- approved event dates
- accepted application URL
- accepted booth fee
- accepted vendor packet attachment

These are not owned by Research MCP.
They only become canonical after Mercury or Lynks accepts them.

## Future tool roadmap

Likely next tools after these first two:

- `discover_shows`
  Finds candidate events broadly.

- `resolve_show_identity`
  Normalizes and compares event identity across aliases, URLs, and sources.

- `research_show`
  Multi-source evidence gathering around one event or edition.

- `collect_application_facts`
  Focused extraction of application links, fees, deadlines, and platform details.

- `observe_show_changes`
  Fresh retrieval plus diffing against prior supplied snapshots.

## Implementation boundary

The eventual implementation of these contracts will still not include:

- Mercury
- Lynks writes
- Control App integration
- autonomous business decisions
- a permanent business database
- Scout UI
- recommendation logic
- vendor assignment logic

These first tools are read-only research capabilities only.
