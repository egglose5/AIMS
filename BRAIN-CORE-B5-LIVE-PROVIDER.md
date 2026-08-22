# Ancient Innovations Brain Core — B5 Live Reasoning Provider

B5 turns the provider-neutral B4 router into a live advisory reasoning connection while preserving Brain Core ownership and governance.

## B5 contract
- Brain Core still owns durable memory, evidence, permissions, audit history and business truth.
- The provider receives only the question and context deliberately assembled after B3 governance checks.
- The provider receives no PostgreSQL credentials and no direct Control App service access.
- Model output is advisory only. It cannot submit applications, spend money, assign vendors, or change canonical business data.
- Provider/model selection remains environment configuration and can be replaced later.

## Default model
The configuration helper defaults to `gpt-5.6-luna` with low reasoning effort because it is the current cost-sensitive GPT-5.6 API model. Change the model later by rerunning the helper.

## Secret handling
The API key is written only to local `.env.brain-b5`, permissions 600. It is not stored in PostgreSQL and is not included in Brain audit records. `docker-compose.override.yml` references that local env file.

## Test
1. Run B4 contract self-test; it must still pass without network access.
2. Configure the provider with `./configure-brain-provider-b5.sh`.
3. Open Show Brain and confirm Provider/Model show LIVE configuration.
4. Click **Run B5 live reasoning self-test**.
5. PASS requires a completed reasoning ledger entry. The model remains advisory.
