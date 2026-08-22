# Ancient Innovations Brain Core — B4 Reasoning Connection

B4 activates the replaceable reasoning-model port without making any model the Brain.

## Ownership boundary
- Ancient Innovations owns governance, durable memory, evidence, decisions, audit records and business truth.
- A model provider receives only the prompt/context deliberately assembled by Brain Core.
- A provider never receives PostgreSQL credentials or raw Control App service access.
- Model output is advisory. It is not automatically durable memory and it does not perform business actions.

## Provider routing
B4 supports the OpenAI Responses API as the first live provider adapter, selected only by environment configuration. The `IBrainModelRouter` contract remains provider-neutral so additional/local providers can replace or coexist with it later.

Environment variables:
- `BRAIN_REASONING_PROVIDER=OPENAI`
- `BRAIN_REASONING_MODEL=<model name>`
- `BRAIN_REASONING_API_KEY=<key>` (or `OPENAI_API_KEY`)
- optional `BRAIN_REASONING_ENDPOINT` (defaults to the OpenAI Responses endpoint)
- optional `BRAIN_REASONING_EFFORT` (defaults to `low`)

No key is stored in the database by B4.

## B4 contract test
The self-test does not call an external model. It proves:
1. SHOW_BRAIN is authorized for advisory intelligence reads.
2. Context crosses the B3 governed gateway.
3. The provider-neutral router accepts the reasoning contract.
