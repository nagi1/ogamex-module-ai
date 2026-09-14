# Module-owned headless AgentOS memory driver. Build from this directory:
#   docker build -t ogamex-ai-cognition-agentos:0.10.16 .
FROM node:22-slim

ENV NODE_ENV=production

WORKDIR /driver

COPY package.json ./
# Only the memory subset is used. The embedder is the module's own deterministic
# implementation, so no model runtime, no provider SDK and no ONNX dependency is
# installed: this image has nothing that could call a generative model.
RUN npm install --omit=dev --no-audit --no-fund && npm cache clean --force

COPY local-embedding-manager.mjs server.mjs ./

EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=3 \
    CMD node -e "fetch('http://127.0.0.1:8080/health').then((r) => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))"

CMD ["node", "server.mjs"]
