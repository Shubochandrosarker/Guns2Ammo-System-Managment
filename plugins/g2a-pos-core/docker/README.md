# G2A POS — local AI stack

Self-hosted Ollama + Qdrant for the v1.0.0 AI Agent. Designed to run on
the merchant's own server (or a private VPS) so 4473 PII, customer data
and the bound book never touch a third-party API.

## Prerequisites

- Docker + Docker Compose
- (Optional) Nvidia GPU + container toolkit for 4–8× speedup
- ~10 GB disk for a 7B model + embeddings

## Boot

```bash
cd docker
docker compose up -d
docker exec g2a-ollama ollama pull qwen2.5:7b-instruct
docker exec g2a-ollama ollama pull nomic-embed-text
```

## Point the plugin at it

WP Admin → **AI Settings**:

| Setting             | Value                                              |
|---------------------|----------------------------------------------------|
| Mode                | `live`                                             |
| Chat endpoint       | `http://<host>:11434/v1/chat/completions`          |
| Embedding endpoint  | `http://<host>:11434/api/embeddings`               |
| Chat model          | `qwen2.5:7b-instruct`                              |
| Embedding model     | `nomic-embed-text`                                 |
| Temperature         | `0.2`                                              |
| Max tokens          | `800`                                              |
| Timeout (s)         | `60`                                               |

## Model recommendations

| Use case               | Model                          | Notes                          |
|------------------------|--------------------------------|--------------------------------|
| Single-store CPU       | `qwen2.5:3b-instruct`          | ~4GB RAM, conversational       |
| Single-store w/ GPU    | `qwen2.5:7b-instruct`          | Sweet spot, tool calls clean   |
| Multi-store / heavy    | `llama-3.1:8b-instruct-q4_K_M` | More world knowledge           |
| Embeddings (all sizes) | `nomic-embed-text`             | 768-dim, fast, English+code    |

## Air-gap mode

Set `OLLAMA_HOST=127.0.0.1` and put both containers on a docker network
with `internal: true`. The plugin will continue to talk to them via the
host bridge, but the containers themselves have no outbound network —
ideal if you don't want firmware/model downloads to phone home.

## Stub mode (no LLM)

If you set Mode = `stub` in AI Settings, the plugin's agent loop still
runs end-to-end: tool registry, RAG (text fallback retrieval), audit
ledger, confirmation popups. The agent just responds with canned text
and routes obvious requests by keyword. Useful for QA and demos.
